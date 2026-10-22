<?php

namespace App\Services\BillmanagerMigration;

use App\Models\BillmanagerRecord;
use App\Models\Invoice;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HistoricalInvoiceImporter
{
    public function import(Snapshot $snapshot, ImportContext $context): array
    {
        $payments = array_column($snapshot->rows('payments'), null, 'id');
        $items = array_column($snapshot->rows('invoiceitems'), null, 'id');
        $profiles = array_column($snapshot->rows('profiles'), null, 'id');
        $companies = array_column($snapshot->rows('company_profiles'), null, 'id');
        $subaccounts = array_column($snapshot->rows('subaccounts'), null, 'id');
        $currencies = array_column($snapshot->rows('currencies'), 'iso', 'id');
        $countries = array_column($snapshot->rows('countries'), 'name', 'id');
        $lines = [];
        $allocations = [];
        foreach ($items as $line) {
            $lines[$line['invoice']][] = $line;
        }
        foreach ($snapshot->rows('invoiceitem_payments') as $allocation) {
            $invoice = $items[$allocation['invoiceitem']]['invoice'] ?? throw new RuntimeException('Unknown allocation invoice item');
            $allocations[$invoice][] = $allocation;
        }
        $timezone = $snapshot->sourceTimezone() ?? config('billmanager-migration.source_timezone');
        if (!$timezone || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException('An explicit source timezone is required');
        }
        $paid = 0;
        $archiveOnly = [];
        foreach ($snapshot->rows('invoices') as $invoice) {
            $rows = $allocations[$invoice['id']] ?? [];
            $invoiceLines = $lines[$invoice['id']] ?? [];
            $currency = $currencies[$invoice['currency']];
            $payer = $profiles[$invoice['customer']];
            $issuer = $companies[$invoice['company'] ?? ''] ?? null;
            $reason = $rows ? null : 'no_payment_allocations';
            $total = BigDecimal::of('0');
            $lineTotal = BigDecimal::of('0');
            $transactions = [];
            foreach ($rows as $row) {
                $payment = $payments[$row['payment']] ?? null;
                if (!$payment || $payment['status'] !== '4' || ($currencies[$payment['currency']] ?? null) !== $currency
                    || ($subaccounts[$payment['subaccount']]['account'] ?? null) !== $payer['account']) {
                    $reason ??= 'unproven_currency_or_payment_state';

                    continue;
                }
                if (empty($payment['paydate']) || str_starts_with($payment['paydate'], '0000-')) {
                    $reason ??= 'missing_payment_date';
                }
                $amount = BigDecimal::of(FinancialRows::decimal($row['amount']));
                if (!$amount->isPositive() || !$this->fitsNativeAmount($amount)) {
                    $reason ??= 'allocation_precision_or_sign';
                }
                $total = $total->plus($amount);
                $transactions[$row['payment']] = ($transactions[$row['payment']] ?? BigDecimal::of('0'))->plus($amount);
            }
            foreach ($invoiceLines as $line) {
                $amount = BigDecimal::of(FinancialRows::decimal($line['amount']));
                $lineTotal = $lineTotal->plus($amount);
                if (!$this->fitsNativeAmount($amount) || mb_strlen($line['name'] ?? '') > 255) {
                    $reason ??= 'line_precision_or_length';
                }
                if (!BigDecimal::of($line['taxamount'] ?? '0')->isZero()) {
                    $reason ??= 'tax_review';
                }
            }
            $expected = BigDecimal::of(FinancialRows::decimal($invoice['amount']));
            if (!$expected->isPositive() || !$total->isEqualTo($expected) || !$lineTotal->isEqualTo($expected)) {
                $reason ??= 'unproven_full_allocation';
            }
            if (!$issuer || empty($issuer['name'])) {
                $reason ??= 'missing_issuer';
            }
            if (!DB::table('currencies')->where('code', $currency)->exists()) {
                $reason ??= 'native_currency_unavailable';
            }
            if ($invoice['invoice_status'] !== '1') {
                $reason ??= 'document_state_review';
            }
            $mapped = $context->mappedId('invoices', (string) $invoice['id']);
            if ($reason !== null) {
                if ($mapped) {
                    throw new RuntimeException('Previously mapped invoice no longer reconciles');
                }
                $archiveOnly[$reason] = ($archiveOnly[$reason] ?? 0) + 1;

                continue;
            }
            $owner = DB::table('billmanager_accounts')->where('id', $context->mappedId('accounts', (string) $payer['account']))->value('owner_user_id');
            if (!$owner) {
                throw new RuntimeException('Historical invoice has no imported owner');
            }
            if ($mapped) {
                $mapping = DB::table('billmanager_mappings')->where(['source_host' => $context->sourceHost, 'source_table' => 'invoices', 'source_id' => $invoice['id']])->sole();
                $original = BillmanagerRecord::where(['import_id' => $mapping->import_id, 'source_table' => 'invoices', 'source_id' => $invoice['id']])->sole();
                if ($original->payload !== $invoice) {
                    throw new RuntimeException('Mapped historical invoice changed; reconciliation required');
                }
                $native = DB::table('invoices')->where('id', $mapped)->sole();
                if ($native->user_id != $owner || $native->number !== $invoice['number'] || $native->status !== 'paid' || $native->currency_code !== $currency) {
                    throw new RuntimeException('Native historical invoice changed');
                }
                if (!BigDecimal::of(DB::table('invoice_items')->where('invoice_id', $mapped)->sum('price'))->isEqualTo($expected)
                    || !BigDecimal::of(DB::table('invoice_transactions')->where('invoice_id', $mapped)->where('status', 'succeeded')->sum('amount'))->isEqualTo($expected)) {
                    throw new RuntimeException('Native historical invoice totals changed');
                }
                $paid++;

                continue;
            }
            if (DB::table('invoices')->where('number', $invoice['number'])->exists()) {
                throw new RuntimeException('Historical invoice number collision');
            }
            $date = Carbon::parse($invoice['cdate'], $timezone)->format('Y-m-d') . ' 00:00:00';
            $id = DB::table('invoices')->insertGetId(['user_id' => $owner, 'number' => $invoice['number'], 'status' => 'paid', 'currency_code' => $currency,
                'due_at' => null, 'created_at' => $date, 'updated_at' => $date]);
            $context->recordMapping('invoices', (string) $invoice['id'], 'invoices', $id);
            DB::table('billmanager_holds')->insert(['model_type' => Invoice::class, 'model_id' => $id, 'reason' => 'Historical invoice; awaiting billing handover']);
            foreach ($invoiceLines as $line) {
                $lineId = DB::table('invoice_items')->insertGetId(['invoice_id' => $id, 'price' => (string) BigDecimal::of($line['amount'])->toScale(2), 'quantity' => 1,
                    'description' => $line['name'], 'created_at' => $date, 'updated_at' => $date]);
                $context->recordMapping('invoiceitems', (string) $line['id'], 'invoice_items', $lineId);
            }
            foreach ($transactions as $paymentId => $amount) {
                $payment = $payments[$paymentId];
                $paidAt = Carbon::parse($payment['paydate'], $timezone)->utc()->format('Y-m-d H:i:s');
                $transactionId = DB::table('invoice_transactions')->insertGetId(['invoice_id' => $id, 'gateway_id' => null, 'amount' => (string) $amount->toScale(2),
                    'fee' => null, 'transaction_id' => $payment['externalid'] ?: $payment['number'], 'status' => 'succeeded', 'is_credit_transaction' => false,
                    'created_at' => $paidAt, 'updated_at' => $paidAt]);
                $context->recordMapping('invoice_payment_allocations', $invoice['id'] . ':' . $paymentId, 'invoice_transactions', $transactionId);
            }
            $address = static fn ($profile) => array_values(array_filter([
                $profile['address_legal'] ?? null, $profile['city_legal'] ?? null, $profile['state_legal'] ?? null,
                $profile['postcode_legal'] ?? null, $countries[$profile['country_legal'] ?? ''] ?? null, $profile['vatnum'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));
            DB::table('invoice_snapshots')->insert(['invoice_id' => $id, 'name' => $payer['name'], 'properties' => json_encode($address($payer), JSON_THROW_ON_ERROR),
                'bill_to' => implode("\n", [$issuer['name'], ...$address($issuer)]), 'tax_name' => 'Historical zero tax', 'tax_rate' => '0.00',
                'created_at' => $date, 'updated_at' => $date]);
            $paid++;
        }

        return ['native_paid_invoices' => $paid, 'archive_only_reasons' => $archiveOnly];
    }

    private function fitsNativeAmount(BigDecimal $amount): bool
    {
        try {
            $amount->toScale(2);
        } catch (RoundingNecessaryException) {
            return false;
        }

        return $amount->abs()->isLessThan(BigDecimal::of('1000000000000000'));
    }
}
