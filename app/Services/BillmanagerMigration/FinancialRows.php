<?php

namespace App\Services\BillmanagerMigration;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

final class FinancialRows
{
    public static function decimal(string $value): string
    {
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value) || strlen($value) > 80) {
            throw new RuntimeException('Invalid exact financial amount');
        }
        BigDecimal::of($value);

        return $value;
    }

    public static function records(Snapshot $snapshot): \Generator
    {
        $currencies = array_column($snapshot->rows('currencies'), 'iso', 'id');
        $subaccounts = array_column($snapshot->rows('subaccounts'), null, 'id');
        $profiles = array_column($snapshot->rows('profiles'), null, 'id');
        $companies = array_column($snapshot->rows('company_profiles'), null, 'id');
        $lineItems = [];
        foreach ($snapshot->rows('invoiceitems') as $line) {
            $lineItems[$line['invoice']][] = $line;
        }
        foreach (['subaccounts', 'payments', 'invoices'] as $table) {
            foreach ($snapshot->rows($table) as $row) {
                $account = match ($table) {
                    'subaccounts' => $row['account'],
                    'payments' => $subaccounts[$row['subaccount']]['account'] ?? throw new RuntimeException('Payment has no selected subaccount'),
                    'invoices' => $profiles[$row['customer']]['account'] ?? throw new RuntimeException('Invoice has no selected payer'),
                };
                $currencyId = $table === 'payments' ? $subaccounts[$row['subaccount']]['currency'] : $row['currency'];
                $currency = $currencies[$currencyId] ?? throw new RuntimeException('Unknown financial currency');
                if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
                    throw new RuntimeException('Invalid financial currency');
                }
                $status = match ($table) {
                    'subaccounts' => 'held',
                    'payments' => match ((int) $row['status']) {
                        1 => 'new', 2 => 'pending', 3 => 'provisionally_credited', 4 => 'paid', 5 => 'awaiting_refund',
                        6 => 'refunded', 7 => 'fraudulent', 8 => 'new_instant', 9 => 'cancelled', 100 => 'deleting',
                        default => throw new RuntimeException('Unknown payment state'),
                    },
                    'invoices' => match ((int) $row['invoice_status']) {
                        0 => 'preliminary', 1 => 'created', 2 => 'requested', 3 => 'sent', 4 => 'signed',
                        100 => 'awaiting_electronic_delivery', 110 => 'electronically_signed', 120 => 'electronically_cancelled',
                        default => throw new RuntimeException('Unknown accounting invoice state'),
                    },
                };
                $amount = self::decimal($row[match ($table) {
                    'subaccounts' => 'balance', 'payments' => 'subaccountamount', 'invoices' => 'amount'
                }]);
                $details = $row;
                if ($table === 'subaccounts') {
                    $exact = BigDecimal::of($amount);
                    $details['rounded_amount'] = (string) $exact->toScale(2, RoundingMode::HALF_UP);
                    $details['rounding_delta'] = (string) BigDecimal::of($details['rounded_amount'])->minus($exact);
                }
                if ($table === 'invoices') {
                    $details['payer'] = $profiles[$row['customer']];
                    $details['issuer'] = $companies[$row['company'] ?? ''] ?? null;
                    $details['line_items'] = $lineItems[$row['id']] ?? [];
                }
                yield ['source_table' => $table, 'source_id' => (string) $row['id'], 'source_account_id' => (int) $account,
                    'number' => $row['number'] ?? null, 'currency_code' => $currency, 'status' => $status, 'amount' => $amount, 'details' => $details];
            }
        }
    }

    public static function archives(Snapshot $snapshot): \Generator
    {
        $accounts = [];
        $relations = [
            'profiles' => ['account', null], 'subaccounts' => ['account', null],
            'payments' => ['subaccount', 'subaccounts'], 'invoices' => ['customer', 'profiles'],
            'invoiceitems' => ['invoice', 'invoices'], 'expenses' => ['subaccount', 'subaccounts'],
            'expense_changes' => ['reference', 'expenses'], 'expense_payments' => ['expense', 'expenses'],
            'invoiceitem_expenses' => ['invoiceitem', 'invoiceitems'], 'invoiceitem_payments' => ['invoiceitem', 'invoiceitems'],
            'payment_refunds' => ['payment_base', 'payments'], 'payment_history' => ['reference', 'payments'],
            'invoice_history' => ['reference', 'invoices'], 'invoiceitem_history' => ['reference', 'invoiceitems'],
        ];
        foreach ($relations as $table => [$field, $parent]) {
            $occurrences = [];
            foreach ($snapshot->rows($table) as $row) {
                $account = $parent === null ? ($row[$field] ?? null) : ($accounts[$parent][$row[$field]] ?? null);
                if (!$account) {
                    throw new RuntimeException('Financial archive has no selected account: ' . $table);
                }
                $digest = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $occurrences[$digest] = ($occurrences[$digest] ?? 0) + 1;
                $id = isset($row['id']) ? (string) $row['id'] : $digest . ':' . $occurrences[$digest];
                $accounts[$table][$id] = (int) $account;
                yield [$table, $id, (int) $account, $row];
            }
        }
        foreach (['currencies', 'countries', 'company_profiles'] as $table) {
            foreach ($snapshot->rows($table) as $row) {
                yield [$table, (string) $row['id'], null, $row];
            }
        }
    }
}
