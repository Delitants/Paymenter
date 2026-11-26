<?php

namespace App\Services\Accounts\Legacy;

use App\Models\AccountMovement;
use App\Models\AccountOpeningBatch;
use App\Models\AccountOpeningReceipt;
use App\Models\AccountWallet;
use App\Models\LegacyEffect;
use App\Models\LegacyPostingResult;
use App\Models\LegacyReceiptConflict;
use App\Models\User;
use App\Services\Accounts\AccountPaymentLocks;
use App\Services\Accounts\AccountWriteContext;
use App\Services\Accounts\WalletLedger;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\ProofSchema;
use App\Services\BillmanagerMigration\Opening\StrictProofJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Applies a single original receipt, with no provider or fulfillment action. */
final class LegacyPostingService
{
    private static ?array $frame = null;

    private readonly LegacyOpeningClassifier $classifier;

    private readonly LegacyPostingAuthority $authority;

    public function __construct(LegacyClock $clock)
    {
        $this->classifier = new LegacyOpeningClassifier($clock);
        $this->authority = new LegacyPostingAuthority($clock);
    }

    public function apply(string $effectKey): ?LegacyPostingResult
    {
        if (self::$frame !== null || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('Legacy posting requires its own durable transaction.');
        }
        try {
            return $this->applyOnce($effectKey, false);
        } catch (UniqueConstraintViolationException $failure) {
            return $this->applyOnce($effectKey, true) ?? throw $failure;
        }
    }

    private function applyOnce(string $effectKey, bool $replayOnly): ?LegacyPostingResult
    {
        $preview = $this->classifier->classify($effectKey, true);
        if ($preview['classification'] === 'ambiguous') {
            return null;
        }
        $hint = AccountOpeningReceipt::where('receipt_sha256', $preview['opening_receipt_sha256'])->sole();
        $effect = LegacyEffect::where('effect_key', $effectKey)->sole();
        $parentKey = EconomicFacts::fromArray(StrictProofJson::decode($effect->economic_json))->data()['parent_effect_key'];
        $graph = $preview['classification'] === 'excluded' ? $this->refundGraph($hint) : [];

        return DB::transaction(function () use ($preview, $hint, $parentKey, $graph, $replayOnly) {
            return (new AccountPaymentLocks)->duringIncomingEvidence(array_column($graph, 'invoice_id'),
                function ($invoices) use ($preview, $hint, $parentKey, $graph, $replayOnly) {
                    if ($invoices->contains(fn ($invoice) => $invoice->user_id !== $hint->owner_id)) {
                        throw new RuntimeException('Native refund dependencies cross the accepted legacy owner.');
                    }
                    $owner = User::findOrFail($hint->owner_id);
                    AccountPaymentLocks::lockFinancialUsers($owner);
                    $owner = User::whereKey($hint->owner_id)->lockForUpdate()->firstOrFail();
                    $wallet = AccountWallet::whereKey($hint->wallet_id)->lockForUpdate()->firstOrFail();
                    if ($wallet->user_id !== $hint->owner_id || $wallet->currency_code !== $hint->currency ||
                        $wallet->opening_identity !== $hint->opening_identity) {
                        throw new RuntimeException('Legacy owner, wallet or native refund dependencies changed.');
                    }
                    if ($preview['classification'] === 'excluded') {
                        $this->assertRefundGraph($hint, $graph);
                    }

                    return $this->applyLocked($owner, $wallet, $hint, $parentKey, $preview, $replayOnly);
                }, array_column($graph, 'gateway_id'));
        }, 1);
    }

    private function applyLocked(User $owner, AccountWallet $wallet, AccountOpeningReceipt $hint, ?string $parentKey, array $preview, bool $replayOnly): ?LegacyPostingResult
    {
        $batch = AccountOpeningBatch::whereKey($hint->batch_id)->lockForUpdate()->firstOrFail();
        $opening = AccountOpeningReceipt::whereKey($hint->id)->lockForUpdate()->firstOrFail();
        if ($opening->getRawOriginal() !== $hint->getRawOriginal()) {
            throw new RuntimeException('The original legacy opening changed before posting.');
        }
        $effectKey = $preview['effect_key'];
        $keys = $parentKey === null ? [$effectKey] : [$effectKey, $parentKey];
        $effects = LegacyEffect::whereIn('effect_key', $keys)->orderBy('effect_key')->lockForUpdate()->get()->keyBy('effect_key');
        $effect = $effects->get($effectKey);
        $parent = $parentKey === null ? null : $effects->get($parentKey);
        if (!$effect || EconomicFacts::fromArray(StrictProofJson::decode($effect->economic_json))->data()['parent_effect_key'] !== $parentKey) {
            throw new RuntimeException('The original legacy effect or its parent link changed.');
        }
        $parentResult = $parent ? LegacyPostingResult::where('effect_id', $parent->id)->lockForUpdate()->first() : null;
        if ($parentKey !== null && !$parentResult) {
            return null;
        }
        $children = LegacyEffectCaps::lockChildren($effect, $parent, $parentResult, $preview);
        if ($parent && LegacyReceiptConflict::where('effect_id', $parent->id)->lockForUpdate()->first(['id']) !== null) {
            return null;
        }
        $decision = $this->classifier->classifyLocked($effectKey, $owner->id);
        if ($decision !== $preview) {
            throw new RuntimeException('Opening classification changed before posting.');
        }
        $receipt = LegacyPostingReceipt::readLocked($effect, $batch, $opening, $decision, $parentResult);
        $approval = $this->authority->approve($receipt);
        $cap = LegacyEffectCaps::assertAllowed($receipt, $parent, $parentResult, $approval['parent_cap'], $children);
        if ($receipt->facts['disposition'] !== 'included') {
            MigrationHold::assertAllowed($owner, 'post legacy payment', true);
            MigrationHold::assertAllowed($wallet, 'post legacy payment', true);
        }
        self::$frame = ['receipt' => $receipt, 'connection' => DB::connection(), 'pdo' => DB::connection()->getPdo(),
            'result' => null, 'pending_result' => null];
        try {
            $result = $this->result($receipt, $wallet, $owner, $approval, $cap, $replayOnly);
            if ($this->classifier->classifyLocked($effectKey, $owner->id) !== $decision ||
                LegacyOpeningExclusion::sourceTrust()->sha256 !== $receipt->sourceTrustHash ||
                $this->authority->approve($receipt) !== $approval) {
                throw new RuntimeException('Legacy posting evidence changed before commit.');
            }

            return $result;
        } finally {
            self::$frame = null;
        }
    }

    private function result(LegacyPostingReceipt $receipt, AccountWallet $wallet, User $owner, array $approval, ?string $cap, bool $replayOnly): LegacyPostingResult
    {
        $facts = $receipt->durableFacts() + ['parent_cap_sha256' => $cap,
            'parent_cap_json' => $cap === null ? null : CanonicalPolicy::bytes($approval['parent_cap'])];
        $result = LegacyPostingResult::where('effect_id', $receipt->facts['effect_id'])->lockForUpdate()->first();
        $applied = $facts['disposition'] === 'applied';
        $movement = AccountMovement::where('source_key', $receipt->sourceKey())->lockForUpdate()->first();
        $context = $applied ? AccountWriteContext::legacyEffect($receipt) : null;
        if ($result !== null) {
            if ($result->only(array_keys($facts)) !== $facts || $result->movement_id !== $movement?->id ||
                $applied !== ($movement !== null) || ($approval['sha256'] === null) !== ($result->authority_sha256 === null) ||
                ($receipt->exclusionHash === null) !== ($result->exclusion_sha256 === null)) {
                throw new RuntimeException('Committed legacy result differs from the current original receipt.');
            }
            if ($result->authority_sha256 !== null) {
                ProofSchema::hash($result->authority_sha256);
            }
            if ($result->exclusion_sha256 !== null) {
                ProofSchema::hash($result->exclusion_sha256);
            }
            if ($context !== null) {
                $originalBalance = clone $wallet;
                $originalBalance->balance = $movement->balance_before;
                $expected = $context->movementAttributes($originalBalance, $receipt->requestKey());
                if ($movement->only(array_keys($expected)) !== $expected) {
                    throw new RuntimeException('Committed legacy movement differs from its original receipt.');
                }
            }

            return $result;
        }
        if ($replayOnly || $movement !== null) {
            throw new RuntimeException('Legacy posting recovery requires the original complete committed result.');
        }
        $ledger = new WalletLedger;
        if ($context !== null) {
            $request = $receipt->requestKey();
            $movement = $ledger->post($context, $facts['net_delta'], 'legacy_effect', $request, $context->fingerprint($request));
        } elseif ($facts['disposition'] === 'applied_zero' && $ledger->quote($owner, $receipt->facts['currency'])?->blocked !== false) {
            throw new RuntimeException('A conserved zero result requires an active reconciled wallet.');
        }
        $expected = $facts + ['movement_id' => $movement?->id, 'authority_sha256' => $approval['sha256'],
            'exclusion_sha256' => $receipt->exclusionHash, 'created_at' => gmdate('Y-m-d H:i:s')];
        self::$frame['result'] = $expected;
        $written = LegacyPostingResult::create($expected);
        if (!$written->exists || $written->getKey() === null || self::$frame['pending_result'] !== null || self::$frame['result'] !== null) {
            throw new RuntimeException('The legacy posting result was not durably inserted.');
        }
        $stored = LegacyPostingResult::whereKey($written->id)->lockForUpdate()->firstOrFail();
        if ($stored->only(array_keys($expected)) !== $expected) {
            throw new RuntimeException('Persisted legacy result changed from its exact receipt.');
        }

        return $stored;
    }

    private function refundGraph(AccountOpeningReceipt $opening): array
    {
        $rows = DB::table('account_reversal_reservations')
            ->leftJoin('payment_operations', 'payment_operations.id', '=', 'account_reversal_reservations.payment_operation_id')
            ->leftJoin('invoices', 'invoices.id', '=', 'payment_operations.invoice_id')
            ->where('account_reversal_reservations.wallet_id', $opening->wallet_id)
            ->orderBy('account_reversal_reservations.id')
            ->get(['account_reversal_reservations.id', 'account_reversal_reservations.user_id', 'payment_operations.id as operation_id',
                'payment_operations.invoice_id', 'payment_operations.gateway_id', 'invoices.user_id as invoice_owner']);
        foreach ($rows as $row) {
            if ($row->user_id !== $opening->owner_id || $row->invoice_owner !== $opening->owner_id ||
                $row->operation_id === null || $row->invoice_id === null || $row->gateway_id === null) {
                throw new RuntimeException('An original native refund dependency is missing or belongs to another owner.');
            }
        }

        return $rows->map(fn ($row) => (array) $row)->all();
    }

    private function assertRefundGraph(AccountOpeningReceipt $opening, array $graph): void
    {
        // Read the reservation set before touching any operation discovered after the owner lock.
        $reservations = DB::table('account_reversal_reservations')->where('wallet_id', $opening->wallet_id)
            ->orderBy('id')->lockForUpdate()->get(['id', 'user_id', 'payment_operation_id as operation_id']);
        foreach ($reservations as $index => $reservation) {
            $before = $graph[$index] ?? [];
            if ((array) $reservation !== array_intersect_key($before, (array) $reservation)) {
                throw new RuntimeException('The native refund graph expanded after wallet acquisition.');
            }
        }
        if ($reservations->count() !== count($graph)) {
            throw new RuntimeException('The native refund graph changed after wallet acquisition.');
        }
        foreach ($graph as $item) {
            $operation = DB::table('payment_operations')->where('id', $item['operation_id'])->lockForUpdate()->first(['invoice_id', 'gateway_id']);
            if (!$operation || $operation->invoice_id !== $item['invoice_id'] || $operation->gateway_id !== $item['gateway_id']) {
                throw new RuntimeException('A native refund operation changed its admitted payment graph.');
            }
        }
    }

    public static function assertCurrent(LegacyPostingReceipt $receipt): void
    {
        $frame = self::$frame;
        if ($frame === null || $frame['receipt'] !== $receipt || DB::connection() !== $frame['connection'] ||
            DB::connection()->getPdo() !== $frame['pdo'] || !DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('Legacy money requires its original locked posting transaction.');
        }
        AccountPaymentLocks::assertFinancialUsers([$receipt->facts['user_id']]);
    }

    public static function assertResultWrite(LegacyPostingResult $result): void
    {
        $expected = self::$frame['result'] ?? null;
        if ($expected === null || self::$frame['pending_result'] !== null || $result->exists || $result->getKey() !== null ||
            array_diff(array_keys($result->getAttributes()), array_keys($expected)) ||
            $result->only(array_keys($expected)) !== $expected) {
            throw new RuntimeException('Legacy posting results require their exact immutable transaction.');
        }
        self::assertCurrent(self::$frame['receipt']);
        self::$frame['pending_result'] = $result;
    }

    public static function assertResultSql(LegacyPostingResult $result, array $attributes): void
    {
        if ((self::$frame['pending_result'] ?? null) !== $result || $attributes !== $result->getAttributes() ||
            $result->only(array_keys(self::$frame['result'])) !== self::$frame['result']) {
            throw new RuntimeException('Legacy result SQL requires its original model and immutable values.');
        }
        self::assertCurrent(self::$frame['receipt']);
        self::$frame['result'] = null;
        self::$frame['pending_result'] = null;
    }
}
