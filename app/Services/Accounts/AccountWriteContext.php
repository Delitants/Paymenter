<?php

namespace App\Services\Accounts;

use App\Models\AccountDowngradeReceipt;
use App\Models\AccountFundingAllocation;
use App\Models\AccountMovement;
use App\Models\AccountWallet;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\PaymentOperation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class AccountWriteContext
{
    public const MOVEMENTS = ['deposit', 'downgrade', 'invoice_funding', 'deposit_refund', 'manual_unsettle', 'manual_restore', 'internal_reversal'];

    private function __construct(
        public string $action,
        public int $ownerId,
        public string $currency,
        public ?OpeningEvidence $evidence = null,
        public ?OpeningAuthority $authority = null,
        public ?int $invoiceId = null,
        public ?array $receiptProof = null,
    ) {}

    public static function invoiceFunding(Invoice $invoice, ?User $actor, mixed $amount, bool $automatic = false, bool $maximum = false): self
    {
        $proof = InvoiceFundingReceipt::capture($invoice, $actor, $amount, $automatic);
        if ($maximum) {
            if ($automatic) {
                throw new RuntimeException('Automatic invoice funding cannot use a partial maximum request.');
            }
            $proof['request_mode'] = 'maximum';
        }

        return new self('invoice_funding', $proof['financial']['owner_id'], $proof['financial']['currency'], invoiceId: $invoice->id, receiptProof: $proof);
    }

    public static function depositOperation(PaymentOperation $operation): self
    {
        DepositReversals::authorize($operation, true);
        $proof = DepositReversalReceipt::facts($operation, true);
        if (!$proof || ($proof['outcome']['state'] ?? null) !== 'succeeded') {
            throw new RuntimeException('Only a stored succeeded original outcome can move principal.');
        }

        return new self($proof['kind'], $proof['owner_id'], $proof['currency'], invoiceId: $operation->invoice_id, receiptProof: $proof);
    }

    public static function internalReversal(User $actor, AccountFundingAllocation $allocation, string $principal, string $reason, array $original): self
    {
        (new AccountFundingPolicy)->authorizeReversal($actor, $allocation);

        return new self('internal_reversal', $allocation->user_id, $allocation->currency_code, invoiceId: $allocation->invoice_id,
            receiptProof: ['allocation_id' => $allocation->id, 'original' => $original, 'actor_id' => $actor->id, 'principal' => $principal, 'reason' => $reason]);
    }

    public function outgoing(): bool
    {
        return in_array($this->action, ['deposit_refund', 'manual_unsettle', 'manual_restore'], true);
    }

    public function delta(): string
    {
        $principal = in_array($this->action, ['deposit', 'downgrade', 'deposit_refund', 'manual_unsettle', 'manual_restore'], true) ? AccountAmount::parse($this->receiptProof['principal']) : AccountAmount::positiveCents($this->receiptProof['principal']);

        return in_array($this->action, ['invoice_funding', 'deposit_refund', 'manual_unsettle'], true) ? AccountAmount::parse('0')->subtract($principal)->exact() : $principal->exact();
    }

    public static function paidDeposit(InvoicePaidProcessing $receipt): self
    {
        return DB::transaction(function () use ($receipt) {
            $proof = NativeDepositReceipt::readFacts($receipt->invoice_id);

            return new self('deposit', $proof['owner_id'], $proof['currency'], invoiceId: $proof['invoice_id'], receiptProof: $proof);
        }, 3);
    }

    public static function completedDowngrade(ServiceUpgrade $upgrade): self
    {
        $proof = NativeDowngradeReceipt::read($upgrade->id, false);

        return new self('downgrade', $proof['owner_id'], $proof['currency'], invoiceId: $upgrade->invoice_id, receiptProof: $proof);
    }

    public function assertReceiptFactsCurrent(): void
    {
        if ($this->outgoing()) {
            DepositReversals::authorize(PaymentOperation::findOrFail($this->receiptProof['operation_id']), true);
        }
        $current = match ($this->action) {
            'deposit' => NativeDepositReceipt::readFacts($this->invoiceId),
            'downgrade' => NativeDowngradeReceipt::read($this->receiptProof['upgrade_id'], false),
            'deposit_refund', 'manual_unsettle', 'manual_restore' => DepositReversalReceipt::facts(PaymentOperation::findOrFail($this->receiptProof['operation_id']), true),
            default => throw new RuntimeException('A supported original incoming receipt is required.'),
        };
        if ($current !== $this->receiptProof) {
            throw new RuntimeException('Original incoming receipt facts changed.');
        }
    }

    public function fingerprint(string $requestKey): string
    {
        if ($requestKey === '' || strlen($requestKey) > 128 || preg_match('/[\x00-\x1f\x7f]/', $requestKey)) {
            throw new RuntimeException('A bounded movement receipt request identity is required.');
        }

        return hash('sha256', json_encode([$this->action, $this->ownerId, $this->currency, $this->invoiceId, $this->receiptProof, $requestKey], JSON_THROW_ON_ERROR));
    }

    public function assertReceiptCurrent(): void
    {
        if ($this->action === 'internal_reversal') {
            $allocation = AccountFundingAllocation::whereKey($this->receiptProof['allocation_id'])->lockForUpdate()->firstOrFail();
            (new AccountFundingPolicy)->authorizeReversal(User::findOrFail($this->receiptProof['actor_id']), $allocation);
            if (InternalReversalReceipt::facts($allocation) !== $this->receiptProof['original']) {
                throw new RuntimeException('Original internal reversal evidence changed.');
            }

            return;
        }
        if ($this->outgoing()) {
            $this->assertReceiptFactsCurrent();
            DepositReversalReceipt::assertMoney($this->receiptProof);

            return;
        }
        if ($this->action === 'invoice_funding') {
            InvoiceFundingReceipt::assertCurrent($this);

            return;
        }
        if ($this->action === 'downgrade') {
            if (NativeDowngradeReceipt::read($this->receiptProof['upgrade_id']) !== $this->receiptProof) {
                throw new RuntimeException('Original downgrade completion evidence changed.');
            }

            return;
        }
        if ($this->action !== 'deposit' || $this->invoiceId === null || $this->receiptProof === null ||
            NativeDepositReceipt::read($this->invoiceId) !== $this->receiptProof) {
            throw new RuntimeException('Original deposit receipt changed or is unsupported.');
        }
    }

    public function sourceKey(): ?string
    {
        if ($this->outgoing()) {
            return DepositReversalReceipt::sourceKey($this->receiptProof['operation_id']);
        }
        if (in_array($this->action, ['invoice_funding', 'internal_reversal'], true)) {
            return null;
        }
        if ($this->action === 'downgrade') {
            return hash('sha256', 'native-downgrade:' . $this->receiptProof['upgrade_id']);
        }
        if ($this->action !== 'deposit' || $this->invoiceId === null) {
            throw new RuntimeException('A supported original movement receipt is required.');
        }

        return hash('sha256', 'native-deposit:' . $this->invoiceId);
    }

    public function movementAttributes(AccountWallet $wallet, string $requestKey): array
    {
        if ($this->action === 'internal_reversal') {
            $original = $this->receiptProof['original']['allocation'];
            if ($wallet->id !== $original['wallet_id'] || $wallet->user_id !== $this->ownerId || $wallet->currency_code !== $this->currency) {
                throw new RuntimeException('Original internal reversal wallet changed.');
            }
            $before = AccountAmount::parse($wallet->balance);
            $delta = AccountAmount::parse($this->delta());

            return ['wallet_id' => $wallet->id, 'user_id' => $wallet->user_id, 'currency_code' => $wallet->currency_code, 'kind' => 'internal_reversal',
                'delta' => $delta->exact(), 'balance_before' => $before->exact(), 'balance_after' => $before->add($delta)->exact(),
                'actor_id' => $this->receiptProof['actor_id'], 'origin' => 'admin', 'request_key' => $requestKey, 'request_fingerprint' => $this->fingerprint($requestKey), 'source_key' => null,
                'reference_type' => AccountFundingAllocation::class, 'reference_id' => $original['id'], 'linked_reversal_id' => $original['movement_id'],
                'payload' => ['principal' => $this->receiptProof['principal'], 'reason' => $this->receiptProof['reason'],
                    'original_fingerprint' => hash('sha256', json_encode($this->receiptProof['original'], JSON_THROW_ON_ERROR))]];
        }
        if ($this->outgoing()) {
            if ($wallet->id !== $this->receiptProof['wallet_id'] || $wallet->user_id !== $this->ownerId || $wallet->currency_code !== $this->currency) {
                throw new RuntimeException('Original reversal wallet identity changed.');
            }
            $before = AccountAmount::parse($wallet->balance);
            $delta = AccountAmount::parse($this->delta());

            return ['wallet_id' => $wallet->id, 'user_id' => $wallet->user_id, 'currency_code' => $wallet->currency_code, 'kind' => $this->action, 'delta' => $delta->exact(),
                'balance_before' => $before->exact(), 'balance_after' => $before->add($delta)->exact(), 'actor_id' => $this->receiptProof['actor_id'], 'origin' => 'native',
                'request_key' => $requestKey, 'request_fingerprint' => $this->fingerprint($requestKey), 'source_key' => $this->sourceKey(),
                'reference_type' => PaymentOperation::class, 'reference_id' => $this->receiptProof['operation_id'], 'linked_reversal_id' => $this->receiptProof['linked_reversal_id'],
                'payload' => ['original_transaction_id' => $this->receiptProof['original_transaction_id'], 'deposit_movement_id' => $this->receiptProof['deposit_movement_id'],
                    'principal' => $this->receiptProof['principal'], 'operation_fingerprint' => $this->receiptProof['request']['request_fingerprint']]];
        }
        if ($this->action === 'invoice_funding') {
            $this->fingerprint($requestKey);
            if ($wallet->user_id !== $this->ownerId || $wallet->currency_code !== $this->currency) {
                throw new RuntimeException('Internal funding receipt wallet identity changed.');
            }
            $before = AccountAmount::parse($wallet->balance);
            $delta = AccountAmount::parse($this->delta());
            $principal = AccountAmount::positiveCents($this->receiptProof['principal']);
            $split = FundingSplit::forPayment($wallet->balance, $this->receiptProof['principal']);

            return ['wallet_id' => $wallet->id, 'user_id' => $wallet->user_id, 'currency_code' => $wallet->currency_code,
                'kind' => $this->action, 'delta' => $delta->exact(), 'balance_before' => $before->exact(), 'balance_after' => $before->add($delta)->exact(),
                'actor_id' => $this->receiptProof['actor_id'], 'origin' => $this->receiptProof['origin'], 'request_key' => $requestKey,
                'request_fingerprint' => $this->fingerprint($requestKey), 'source_key' => null, 'reference_type' => Invoice::class, 'reference_id' => $this->invoiceId,
                'linked_reversal_id' => null, 'payload' => ['pricing_fingerprint' => $this->receiptProof['financial']['pricing_fingerprint'],
                    'principal' => $principal->floorCents(), 'cash' => $split->cash, 'debt' => $split->debt]];
        }
        if (!in_array($this->action, ['deposit', 'downgrade'], true) || $this->receiptProof === null || $wallet->user_id !== $this->ownerId || $wallet->currency_code !== $this->currency ||
            $requestKey === '' || strlen($requestKey) > 128 || preg_match('/[\x00-\x1f\x7f]/', $requestKey) ||
            $this->receiptProof['processed_at'] < $wallet->created_at->format('Y-m-d H:i:s')) {
            throw new RuntimeException('A current original deposit receipt and bounded request identity are required.');
        }
        NativeOpeningHistory::assertEligible($wallet, $this->action, $this->receiptProof);
        $before = AccountAmount::parse($wallet->balance);
        $delta = AccountAmount::parse($this->receiptProof['principal']);

        return ['wallet_id' => $wallet->id, 'user_id' => $wallet->user_id, 'currency_code' => $wallet->currency_code,
            'kind' => $this->action, 'delta' => $delta->exact(), 'balance_before' => $before->exact(), 'balance_after' => $before->add($delta)->exact(),
            'actor_id' => null, 'origin' => 'native', 'request_key' => $requestKey, 'request_fingerprint' => $this->fingerprint($requestKey),
            'source_key' => $this->sourceKey(), 'reference_type' => $this->action === 'deposit' ? InvoicePaidProcessing::class : AccountDowngradeReceipt::class,
            'reference_id' => $this->action === 'deposit' ? $this->invoiceId : $this->receiptProof['receipt_id'],
            'linked_reversal_id' => null, 'payload' => ['receipt_fingerprint' => hash('sha256', json_encode($this->receiptProof, JSON_THROW_ON_ERROR)),
                ...($this->action === 'deposit' ? ['deposit_slices' => $this->receiptProof['deposit_slices']] : ['upgrade_id' => $this->receiptProof['upgrade_id']])]];
    }

    public function allocationAttributes(AccountMovement $movement): array
    {
        if ($this->action !== 'invoice_funding' || $movement->kind !== 'invoice_funding' || $movement->user_id !== $this->ownerId ||
            $movement->currency_code !== $this->currency || $movement->reference_id !== $this->invoiceId || $movement->delta !== $this->delta() ||
            $movement->request_fingerprint !== $this->fingerprint($movement->request_key)) {
            throw new RuntimeException('Internal allocation requires its exact persisted movement receipt.');
        }

        return ['wallet_id' => $movement->wallet_id, 'user_id' => $this->ownerId, 'currency_code' => $this->currency, 'movement_id' => $movement->id,
            'invoice_id' => $this->invoiceId, 'invoice_transaction_id' => null, 'amount' => $this->receiptProof['principal'],
            'cash_amount' => $movement->payload['cash'], 'debt_amount' => $movement->payload['debt'], 'pricing_fingerprint' => $movement->payload['pricing_fingerprint'], 'reversed_amount' => '0.00'];
    }

    public static function opening(OpeningEvidence $evidence, OpeningAuthority $authority): self
    {
        return new self('opening', $evidence->ownerId, $evidence->currency, $evidence, $authority);
    }

    public function openingAttributes(): array
    {
        $evidence = $this->evidence ?? throw new RuntimeException('Opening evidence is required.');
        foreach ([$evidence->sourceSystem, $evidence->sourceAccount] as $identity) {
            if ($identity === '' || strlen($identity) > 190 || preg_match('/[\x00-\x1f\x7f]/', $identity)) {
                throw new DomainException('A bounded source opening identity is required.');
            }
        }
        if ($evidence->ownerId <= 0 || !preg_match('/^[A-Z]{3}$/D', $evidence->currency)) {
            throw new DomainException('A native owner and currency are required.');
        }
        foreach ([$evidence->snapshotHash, $evidence->policyHash, $evidence->grantHash] as $hash) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new DomainException('Verified opening lineage is required.');
            }
        }
        $source = AccountAmount::parse($evidence->opening);
        $effective = $source->compare(AccountAmount::parse('0')) > 0 ? AccountAmount::parse($source->halfUpCents()) : $source;

        return [
            'user_id' => $evidence->ownerId,
            'currency_code' => $evidence->currency,
            'opening_identity' => hash('sha256', json_encode([$evidence->sourceSystem, $evidence->sourceAccount, $evidence->currency], JSON_THROW_ON_ERROR)),
            'opening_balance' => $effective->exact(),
            'balance' => $effective->exact(),
            'borrowing_limit' => AccountAmount::nonnegative($evidence->limit)->exact(),
            'active' => $evidence->active,
            'reconciliation_required' => false,
            'opening_evidence' => ['source' => get_object_vars($evidence), 'effective_opening' => $effective->exact(), 'rounding_delta' => $effective->subtract($source)->exact(),
                'native_income_exclusions' => NativeOpeningHistory::forOpening($evidence->ownerId, $evidence->currency)],
        ];
    }
}
