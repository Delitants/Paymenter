<?php

namespace App\Services\BillmanagerMigration;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

final class PriceReconciler
{
    public function resolve(array $service, Snapshot $snapshot): array
    {
        $period = (int) $service['period'];
        if ($period !== -100 && $period <= 0) {
            throw new RuntimeException('Unsupported source billing period');
        }
        if (!isset($service['cost']) || !is_numeric($service['cost']) || BigDecimal::of($service['cost'])->isNegative()) {
            throw new RuntimeException('Missing or negative renewal cost requires reconciliation');
        }
        // BILLmanager item.cost is the calculated renewal total, already including
        // addons, discounts and applicable taxes. Never add catalog prices to it.
        $amount = BigDecimal::of($service['cost']);
        $native = $amount->toScale(2, RoundingMode::HALF_UP);
        $delta = $native->minus($amount);
        $reasons = [];
        if (!$delta->isZero()) {
            $reasons[] = 'fractional_cent';
        }
        if ((int) ($service['costperiod'] ?? 0) !== $period) {
            $reasons[] = 'cost_period_changed';
        }
        $expenses = array_values(array_filter($snapshot->rows('expenses'), fn ($e) => (string) $e['item'] === (string) $service['id'] &&
            in_array($e['operation'], ['prolong', 'open', 'billperiodic'], true) &&
            (int) $e['period'] === (int) ($service['costperiod'] ?? 0)));
        usort($expenses, fn ($a, $b) => [$b['cdate'], (int) $b['id']] <=> [$a['cdate'], (int) $a['id']]);
        $last = $expenses[0] ?? null;
        $total = $discount = $tax = null;
        $comparison = 'no_matching_expense';
        $expenseIds = [];
        if ($last) {
            $addons = array_column(array_filter($snapshot->rows('addons'), fn ($a) => (string) $a['parent'] === (string) $service['id']), 'id');
            $rows = array_filter($snapshot->rows('expenses'), fn ($e) => ((string) $e['id'] === (string) $last['id'] || in_array($e['item'], $addons, true)) &&
                $e['cdate'] === $last['cdate'] && $e['operation'] === $last['operation'] && (int) $e['period'] === (int) $last['period']);
            $total = $discount = $tax = BigDecimal::zero();
            foreach ($rows as $row) {
                $total = $total->plus($row['amount']);
                $discount = $discount->plus($row['discountamount'] ?? '0');
                $tax = $tax->plus($row['taxamount'] ?? '0');
                $expenseIds[] = (string) $row['id'];
            }
            $comparison = $total->isEqualTo($amount) ? 'matched' : 'differs';
            if ($comparison === 'differs') {
                $reasons[] = 'renewal_differs_from_last_expense';
            }
        } elseif ($period !== -100) {
            $reasons[] = 'no_matching_expense';
        }
        // Tax policy and discounts must be checked again at handover. Historical
        // gross totals must not automatically become new net catalog prices.
        $reasons[] = 'handover_tax_and_discount_review';

        return [
            'source_amount' => (string) $service['cost'], 'native_amount' => (string) $native,
            'rounding_delta' => (string) $delta, 'cost_calculated_at' => $service['costdate'] ?? null,
            'source_period' => $period, 'cost_period' => isset($service['costperiod']) ? (int) $service['costperiod'] : null,
            'type' => $period === -100 ? 'one-time' : 'recurring',
            'billing_period' => $period === -100 ? null : $period, 'billing_unit' => $period === -100 ? null : 'month',
            'expense_comparison' => $comparison, 'last_billed_total' => $total === null ? null : (string) $total,
            'last_discount_total' => $discount === null ? null : (string) $discount,
            'last_tax_total' => $tax === null ? null : (string) $tax,
            'expense_ids' => $expenseIds, 'last_billed_at' => $last['cdate'] ?? null, 'review_reasons' => $reasons,
        ];
    }
}
