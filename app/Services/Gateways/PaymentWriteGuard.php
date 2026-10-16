<?php

namespace App\Services\Gateways;

use App\Enums\InvoiceTransactionStatus;
use App\Models\AccountFundingAllocation;
use App\Models\AccountWallet;
use App\Models\Extension;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePaidProcessing;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\Accounts\AccountFundingReversals;
use App\Services\Accounts\AccountPaymentLocks;
use App\Services\Accounts\AccountRecollection;
use App\Services\Accounts\AccountWriteGuard;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\Operations\OperationResult;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PaymentWriteGuard
{
    private static ?GatewayPaymentAttempt $settling = null;

    private static ?PaymentOperation $operation = null;

    private static ?OperationResult $captureResult = null;

    private static ?array $processorOriginal = null;

    public function withPaymentLock(InvoiceTransaction $record, callable $write): mixed
    {
        return DB::transaction(function () use ($record, $write) {
            $invoiceIds = array_values(array_unique(array_filter([$record->invoice_id, $record->getRawOriginal('invoice_id')])));
            $gatewayIds = array_values(array_unique(array_filter([$record->gateway_id, $record->getRawOriginal('gateway_id')])));
            if (AccountPaymentLocks::active()) {
                AccountPaymentLocks::currentInvoices($invoiceIds);
                AccountPaymentLocks::assertGateways($gatewayIds);

                return $write();
            }

            return (new AccountPaymentLocks)->during($invoiceIds, fn () => $write(), $gatewayIds);
        });
    }

    public function withParentDeletion(Model $record, callable $delete): mixed
    {
        return DB::transaction(function () use ($record, $delete) {
            if ($record->isDirty($record->getKeyName())) {
                throw new RuntimeException('Payment parent identity cannot change during deletion.');
            }
            if ($record instanceof Extension) {
                $stored = Extension::withTrashed()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                if ($stored->type !== 'gateway') {
                    return $delete();
                }
                $ids = array_merge(InvoiceTransaction::where('gateway_id', $stored->id)->pluck('invoice_id')->all(),
                    PaymentOperation::where('gateway_id', $stored->id)->pluck('invoice_id')->all(),
                    GatewayPaymentAttempt::where('gateway_id', $stored->id)->pluck('invoice_id')->all());
                (new InvoicePaymentDependencies)->lock($ids);
                if ($this->hasParentPaymentHistory($stored, true)) {
                    throw new RuntimeException('Gateway payment identity and reconciliation history cannot be deleted.');
                }
            } elseif ($record instanceof User) {
                if (AccountWallet::where('user_id', $record->id)->exists()) {
                    throw new RuntimeException('User account opening and journal history cannot be deleted.');
                }
                $ids = Invoice::where('user_id', $record->id)->pluck('id')->all();
                $locked = (new InvoicePaymentDependencies)->lock($ids);
                // Lock the owner range as a current read after dependency locks.
                // New children outside that set require a fresh ordered discovery.
                $current = Invoice::where('user_id', $record->id)->orderBy('id')->lockForUpdate()->pluck('id')->all();
                if (array_diff($current, $locked->modelKeys())) {
                    throw new RuntimeException('New payment dependencies require reload before deleting this user.');
                }
                if ($this->invoiceHistoryExists($current, true)) {
                    throw new RuntimeException('User payment identity and settlement history cannot be deleted.');
                }
            }

            return $delete();
        });
    }

    public function hasParentPaymentHistory(Model $record, bool $currentRead = false): bool
    {
        if ($record instanceof User) {
            $wallet = AccountWallet::where('user_id', $record->id);
            if ($currentRead) {
                $wallet->lockForUpdate();
            }
            if ($wallet->first(['id'])) {
                return true;
            }
            $query = Invoice::where('user_id', $record->id);
            if ($currentRead) {
                $query->lockForUpdate();
            }

            return $this->invoiceHistoryExists($query->pluck('id')->all(), $currentRead);
        }
        if (!$record instanceof Extension) {
            return false;
        }
        if (!$currentRead) {
            $record = Extension::withTrashed()->whereKey($record->getRawOriginal($record->getKeyName()))->first();
        }
        if (!$record || $record->type !== 'gateway') {
            return false;
        }
        $queries = [PaymentOperation::where('gateway_id', $record->id), GatewayPaymentAttempt::where('gateway_id', $record->id),
            InvoiceTransaction::where('gateway_id', $record->id)->where(fn ($q) => $q->whereNotNull('original_allocation')->orWhereNotNull('settlement_origin')->orWhere('status', InvoiceTransactionStatus::Processing))];
        foreach ($queries as $query) {
            if ($currentRead) {
                $query->lockForUpdate();
            }
            if ($query->first(['id']) !== null) {
                return true;
            }
        }

        return false;
    }

    private function invoiceHistoryExists(array $ids, bool $currentRead): bool
    {
        $queries = [PaymentOperation::whereIn('invoice_id', $ids), GatewayPaymentAttempt::whereIn('invoice_id', $ids),
            InvoiceTransaction::whereIn('invoice_id', $ids)->where(fn ($q) => $q->whereNotNull('original_allocation')->orWhereNotNull('settlement_origin')->orWhere('status', InvoiceTransactionStatus::Processing)),
            InvoicePaidProcessing::whereIn('invoice_id', $ids)];
        foreach ($queries as $query) {
            if ($currentRead) {
                $query->lockForUpdate();
            }
            if ($query->first() !== null) {
                return true;
            }
        }

        return false;
    }

    public function updateProcessorFee(string $reference, mixed $fee, ?InvoiceTransaction $original = null): InvoiceTransaction
    {
        $fee = BigDecimal::of((string) $fee)->toScale(2);
        if ($fee->isNegative() || $reference === '' || self::$processorOriginal !== null) {
            throw new RuntimeException('Invalid original processor deduction.');
        }
        $matches = InvoiceTransaction::where('transaction_id', $reference)->limit(2)->get();
        if ($matches->count() !== 1) {
            throw new RuntimeException('Original processor payment reference is missing or ambiguous.');
        }
        $original ??= $matches->first();
        if ($original->id !== $matches->first()->id || $this->processorIdentity($original) !== $this->processorIdentity($matches->first())) {
            throw new RuntimeException('Original processor payment identity changed.');
        }
        $identity = $this->processorIdentity($original);
        $invoiceIdentity = $original->invoice->only(['user_id', 'currency_code']);

        return $this->withPaymentLock($original, function () use ($reference, $fee, $original, $identity, $invoiceIdentity) {
            $matches = InvoiceTransaction::where('transaction_id', $reference)->limit(2)->lockForUpdate()->get();
            if ($matches->count() !== 1) {
                throw new RuntimeException('Original processor payment reference is missing or ambiguous.');
            }
            $stored = $matches->first();
            $invoice = Invoice::whereKey($stored->invoice_id)->lockForUpdate()->firstOrFail();
            if ($stored->id !== $original->id || $stored->transaction_id !== $reference || $this->processorIdentity($stored) !== $identity ||
                $invoice->only(['user_id', 'currency_code']) !== $invoiceIdentity || !$stored->gateway_id || !$stored->transaction_id ||
                $stored->status !== InvoiceTransactionStatus::Succeeded || !BigDecimal::of($stored->amount)->isPositive() || $stored->is_credit_transaction || $stored->settlement_origin === 'manual_record' ||
                $stored->settlement_state === 'unsettled' || str_starts_with($stored->transaction_id, 'manual:')) {
                throw new RuntimeException('Original received native processor payment identity does not match.');
            }
            MigrationHold::assertAllowed($stored, 'append processor deduction', true);
            if ($stored->fee !== null && BigDecimal::of($stored->fee)->isEqualTo($fee)) {
                return $stored;
            }
            self::$processorOriginal = ['id' => $stored->id, 'identity' => $identity];
            try {
                $stored->fee = (string) $fee;
                $stored->save();
            } finally {
                self::$processorOriginal = null;
            }

            return $stored;
        });
    }

    private function processorIdentity(InvoiceTransaction $record): array
    {
        return $record->only(['invoice_id', 'gateway_id', 'transaction_id', 'amount', 'status', 'is_credit_transaction',
            'settlement_origin', 'settlement_state', 'original_allocation']);
    }

    private function isProcessorFeeWrite(Model $record): bool
    {
        return $record instanceof InvoiceTransaction && $record->exists && self::$processorOriginal !== null &&
            $record->id === self::$processorOriginal['id'] && $this->processorIdentity($record) === self::$processorOriginal['identity'] &&
            array_diff(array_keys($record->getDirty()), ['fee', 'updated_at']) === [];
    }

    public function withInvoiceLock(Model $record, callable $write): mixed
    {
        $ids = $record instanceof Invoice ? ($record->exists ? [$record->id] : []) : [$record->invoice_id, $record->getRawOriginal('invoice_id')];
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_NUMERIC);
        if (!$ids) {
            return $write();
        }

        return DB::transaction(function () use ($ids, $write) {
            if (AccountPaymentLocks::active()) {
                AccountPaymentLocks::currentInvoices($ids);

                return $write();
            }

            return (new AccountPaymentLocks)->during($ids, fn () => $write());
        });
    }

    public function assertEditable(Invoice $invoice): void
    {
        if ($this->isFrozen($invoice)) {
            throw new RuntimeException('An external payment attempt requires reconciliation before changing invoice finances.');
        }
    }

    public function isFrozen(Invoice $invoice): bool
    {
        // A dependency discovery read may establish a repeatable-read snapshot
        // before the invoice lock waits. Re-read committed claims after that wait.
        return GatewayPaymentAttempt::where('invoice_id', $invoice->id)->whereIn('state', ['open', 'initializing', 'paid'])->lockForUpdate()->first(['id']) !== null;
    }

    public function assertMutation(Model $record, bool $deleting = false): void
    {
        if (!$deleting && AccountRecollection::allowsFee($record)) {
            return;
        }
        if ($record instanceof InvoiceTransaction && ($record->settlement_origin === 'account_funding' || $record->getRawOriginal('settlement_origin') === 'account_funding')) {
            if (!$deleting && !$record->exists) {
                (new AccountWriteGuard)->authorizeInternalTransaction($record);

                return;
            }
            throw new RuntimeException('Internal account payment identity and settlement history are immutable.');
        }
        if ($record instanceof InvoiceTransaction && $record->is_credit_transaction && $record->invoice_id) {
            $invoice = Invoice::whereKey($record->invoice_id)->lockForUpdate()->firstOrFail();
            User::whereKey($invoice->user_id)->lockForUpdate()->firstOrFail();
            if (AccountWallet::where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first() !== null) {
                throw new RuntimeException('Managed account payments require a proven internal allocation rather than a legacy credit marker.');
            }
        }
        if ($record instanceof InvoiceTransaction && !$deleting && $record->status === InvoiceTransactionStatus::Succeeded &&
            (!$record->exists || $record->getRawOriginal('status') !== InvoiceTransactionStatus::Succeeded->value) && !$record->is_credit_transaction) {
            $invoice = Invoice::whereKey($record->invoice_id)->lockForUpdate()->firstOrFail();
            if ($invoice->items()->where('kind', 'credit_allocation')->exists()) {
                User::whereKey($invoice->user_id)->lockForUpdate()->firstOrFail();
                if (AccountWallet::where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first() &&
                    !$this->isSettlementWrite($record) && !$this->isOperationWrite($record)) {
                    throw new RuntimeException('Managed deposits require a verified settlement or authorized manual receipt.');
                }
            }
        }
        $invoiceId = $record instanceof Invoice ? $record->id : $record->invoice_id;
        if ($invoiceId && AccountFundingAllocation::where('invoice_id', $invoiceId)->lockForUpdate()->first() !== null &&
            (($record instanceof InvoiceItem && ($deleting || $record->isDirty(['invoice_id', 'price', 'quantity', 'tax_amount', 'kind', 'gateway_id', 'reference_id', 'reference_type'])) &&
                (($record->getRawOriginal('kind') ?? $record->kind) !== 'gateway_fee' || $record->kind !== 'gateway_fee')) ||
            ($record instanceof Invoice && ($deleting || $record->isDirty(['user_id', 'currency_code', 'pricing_tax_rate', 'pricing_tax_name', 'pricing_tax_country', 'pricing_tax_inclusive']))))) {
            throw new RuntimeException('Internally funded invoice pricing and financial identity are immutable.');
        }
        $fields = match (true) {
            $record instanceof Invoice => ['user_id', 'currency_code', 'status', 'pricing_tax_rate', 'pricing_tax_name', 'pricing_tax_country', 'pricing_tax_inclusive'],
            $record instanceof InvoiceItem => ['invoice_id', 'price', 'quantity', 'tax_amount', 'kind', 'gateway_id', 'reference_id', 'reference_type'],
            $record instanceof InvoiceTransaction => ['invoice_id', 'amount', 'status', 'gateway_id', 'transaction_id', 'is_credit_transaction', 'fee', 'settlement_origin', 'settlement_state', 'original_allocation'],
            default => [],
        };
        if (!$deleting && $record->exists && !$record->isDirty($fields)) {
            return;
        }
        $frozenOriginal = $record instanceof InvoiceTransaction && $record->exists ? InvoiceTransaction::whereKey($record->id)->lockForUpdate()->value('original_allocation') : null;
        if ($record instanceof InvoiceTransaction && ($frozenOriginal !== null || $record->settlement_origin !== null || $record->getRawOriginal('settlement_origin') !== null || $record->isDirty(['settlement_origin', 'settlement_state']) ||
            ($record->exists && PaymentOperation::where(fn ($q) => $q->where('original_transaction_id', $record->id)->orWhere('result_transaction_id', $record->id))->exists()))) {
            if (!$deleting && ($this->isOperationWrite($record) || $this->isProcessorFeeWrite($record))) {
                return;
            }
            throw new RuntimeException('Managed payment identity and settlement history are immutable.');
        }
        if ($record instanceof Invoice && $record->exists && $deleting && InvoicePaidProcessing::whereKey($record->id)->exists()) {
            throw new RuntimeException('Paid invoice processing history cannot be deleted.');
        }
        if ($record instanceof Invoice && $record->exists && ($deleting || $record->isDirty(['user_id', 'currency_code'])) && (PaymentOperation::where('invoice_id', $record->id)->exists() ||
                InvoiceTransaction::where('invoice_id', $record->id)->whereNotNull('original_allocation')->lockForUpdate()->first(['id']) !== null)) {
            throw new RuntimeException('A managed payment invoice identity is immutable.');
        }
        if (!$deleting && ($this->isOperationWrite($record) || $this->isSettlementWrite($record) || $this->isProcessorFeeWrite($record) || AccountFundingReversals::allowsInvoiceWrite($record))) {
            return;
        }
        $ids = $record instanceof Invoice ? ($record->exists ? [$record->id] : []) : [$record->invoice_id, $record->getRawOriginal('invoice_id')];
        foreach (array_unique(array_filter($ids)) as $id) {
            $this->assertEditable(Invoice::findOrFail($id));
        }
    }

    public function defersIncomingProcessing(Invoice $invoice): bool
    {
        return self::$operation !== null && in_array(self::$operation->kind, ['manual_receipt', 'provider_capture'], true) && $this->isOperationWrite($invoice);
    }

    public function incomingOperation(InvoiceTransaction $record): ?PaymentOperation
    {
        if (self::$operation && $this->isOperationWrite($record) && in_array(self::$operation->kind, ['manual_receipt', 'provider_capture'], true)) {
            return self::$operation;
        }

        return PaymentOperation::where('result_transaction_id', $record->id)->whereIn('kind', ['manual_receipt', 'provider_capture'])->where('state', 'succeeded')->lockForUpdate()->first();
    }

    private function isSettlementWrite(Model $record): bool
    {
        $a = self::$settling;
        if (!$a) {
            return false;
        }
        if ($record instanceof Invoice) {
            return $record->id === $a->invoice_id && $record->getRawOriginal('status') === 'pending' && $record->status === 'paid' && array_diff(array_keys($record->getDirty()), ['status', 'updated_at']) === [];
        }
        if ($record instanceof InvoiceTransaction) {
            return $record->invoice_id === $a->invoice_id && $record->gateway_id === $a->gateway_id &&
                BigDecimal::of($record->amount)->isEqualTo($a->amount) && $record->status === InvoiceTransactionStatus::Succeeded &&
                !$record->is_credit_transaction && $record->transaction_id === 'gateway:' . $a->gateway_id . ':' . $a->provider_transaction_id &&
                (!$record->exists || ($record->getRawOriginal('invoice_id') == $a->invoice_id && $record->getRawOriginal('transaction_id') === $record->transaction_id));
        }

        return false;
    }

    private function isOperationWrite(Model $record): bool
    {
        $operation = self::$operation;
        if (!$operation) {
            return false;
        }
        if ($record instanceof Invoice) {
            return in_array($operation->kind, ['manual_receipt', 'manual_unsettle', 'manual_restore', 'provider_capture'], true) && $record->id === $operation->invoice_id && in_array($record->status, ['pending', 'paid'], true) &&
                in_array($record->getRawOriginal('status'), ['pending', 'paid'], true) &&
                array_diff(array_keys($record->getDirty()), ['status', 'updated_at']) === [];
        }
        if (!$record instanceof InvoiceTransaction || $record->invoice_id !== $operation->invoice_id || $record->gateway_id !== $operation->gateway_id ||
            !BigDecimal::of($record->amount)->isEqualTo($operation->amount) || $record->status !== InvoiceTransactionStatus::Succeeded || $record->is_credit_transaction) {
            return false;
        }
        if ($operation->kind === 'provider_capture') {
            $result = self::$captureResult;

            return $result && !$record->exists && $record->transaction_id === 'gateway:' . $operation->gateway_id . ':' . $result->providerReference &&
                $record->settlement_origin === 'manual_capture' && $record->settlement_state === 'settled' && !$record->fee;
        }
        if ($operation->kind === 'manual_receipt') {
            return !$record->exists && $record->transaction_id === 'manual:' . $operation->gateway_id . ':' . $operation->source_fingerprint &&
                $record->settlement_origin === 'manual_record' && $record->settlement_state === 'settled' && !$record->fee;
        }

        return in_array($operation->kind, ['manual_unsettle', 'manual_restore'], true) && $record->exists &&
            $record->id === $operation->original_transaction_id && $record->settlement_origin === 'manual_record' &&
            $record->settlement_state === ($operation->kind === 'manual_unsettle' ? 'unsettled' : 'settled') &&
            array_diff(array_keys($record->getDirty()), ['settlement_state', 'updated_at']) === [];
    }

    public function duringOperation(PaymentOperation $operation, callable $write, ?OperationResult $captureResult = null): mixed
    {
        if (DB::transactionLevel() === 0 || self::$settling !== null || self::$operation !== null) {
            throw new RuntimeException('An operation write requires its verified invoice transaction.');
        }
        $stored = PaymentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
        $manual = $stored->state === 'queued' && in_array($stored->kind, ['manual_receipt', 'manual_unsettle', 'manual_restore'], true);
        $capture = $stored->kind === 'provider_capture' && in_array($stored->state, ['processing', 'pending', 'uncertain'], true) && $captureResult?->state === 'succeeded';
        if ((!$manual && !$capture) || $stored->request_fingerprint !== $operation->request_fingerprint) {
            throw new RuntimeException('Operation identity requires reconciliation.');
        }
        if ($capture) {
            $captureResult->assertVerified($stored);
            $attempt = GatewayPaymentAttempt::whereKey($stored->payload['provider_context']['attempt_id'])->lockForUpdate()->firstOrFail();
            if ($attempt->state !== 'open' || $attempt->invoice_id !== $stored->invoice_id || $attempt->gateway_id !== $stored->gateway_id ||
                $attempt->provider_reference !== $stored->payload['provider_context']['original_reference'] ||
                $attempt->merchant_fingerprint !== $stored->payload['provider_context']['merchant_fingerprint'] ||
                !BigDecimal::of($attempt->amount)->isEqualTo($stored->amount) || $attempt->currency_code !== $stored->currency_code) {
                throw new RuntimeException('Capture no longer matches its original native authorization.');
            }
        }
        self::$captureResult = $captureResult;
        self::$operation = $stored;
        try {
            return $write();
        } finally {
            self::$operation = null;
            self::$captureResult = null;
        }
    }

    public function duringSettlement(GatewayPaymentAttempt $attempt, callable $write): mixed
    {
        if (DB::transactionLevel() === 0 || self::$settling !== null || self::$operation !== null) {
            throw new RuntimeException('Settlement requires its verified invoice transaction.');
        }
        $stored = GatewayPaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
        if (!in_array($stored->state, ['open', 'initializing'], true) || !$stored->provider_transaction_id ||
            $stored->provider_transaction_id !== $attempt->provider_transaction_id) {
            throw new RuntimeException('Settlement identity requires reconciliation.');
        }
        foreach (PaymentOperation::where('invoice_id', $stored->invoice_id)->where('kind', 'provider_capture')
            ->whereIn('state', ['queued', 'processing', 'pending', 'uncertain'])->lockForUpdate()->get() as $operation) {
            if (($operation->payload['provider_context']['attempt_id'] ?? null) === $stored->id) {
                throw new RuntimeException('An active administrative capture requires operation reconciliation before native settlement.');
            }
        }
        self::$settling = $stored;
        try {
            return $write();
        } finally {
            self::$settling = null;
        }
    }
}
