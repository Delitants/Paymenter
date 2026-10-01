<?php

namespace App\Services\Gateways;

use App\Enums\InvoiceTransactionStatus;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PaymentWriteGuard
{
    private static ?GatewayPaymentAttempt $settling = null;

    public function withInvoiceLock(Model $record, callable $write): mixed
    {
        $ids = $record instanceof Invoice ? ($record->exists ? [$record->id] : []) : [$record->invoice_id, $record->getRawOriginal('invoice_id')];
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_NUMERIC);
        if (!$ids) {
            return $write();
        }

        return DB::transaction(function () use ($ids, $write) {
            (new InvoicePaymentDependencies)->lock($ids);

            return $write();
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
        $fields = match (true) {
            $record instanceof Invoice => ['user_id', 'currency_code', 'status', 'pricing_tax_rate', 'pricing_tax_name', 'pricing_tax_country', 'pricing_tax_inclusive'],
            $record instanceof InvoiceItem => ['invoice_id', 'price', 'quantity', 'tax_amount', 'kind', 'gateway_id', 'reference_id', 'reference_type'],
            $record instanceof InvoiceTransaction => ['invoice_id', 'amount', 'status', 'gateway_id', 'transaction_id', 'is_credit_transaction'],
            default => [],
        };
        if (!$deleting && $record->exists && !$record->isDirty($fields)) {
            return;
        }
        if (!$deleting && $this->isSettlementWrite($record)) {
            return;
        }
        $ids = $record instanceof Invoice ? ($record->exists ? [$record->id] : []) : [$record->invoice_id, $record->getRawOriginal('invoice_id')];
        foreach (array_unique(array_filter($ids)) as $id) {
            $this->assertEditable(Invoice::findOrFail($id));
        }
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

    public function duringSettlement(GatewayPaymentAttempt $attempt, callable $write): mixed
    {
        if (DB::transactionLevel() === 0 || self::$settling !== null) {
            throw new RuntimeException('Settlement requires its verified invoice transaction.');
        }
        $stored = GatewayPaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
        if (!in_array($stored->state, ['open', 'initializing'], true) || !$stored->provider_transaction_id ||
            $stored->provider_transaction_id !== $attempt->provider_transaction_id) {
            throw new RuntimeException('Settlement identity requires reconciliation.');
        }
        self::$settling = $stored;
        try {
            return $write();
        } finally {
            self::$settling = null;
        }
    }
}
