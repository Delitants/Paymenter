<?php

namespace App\Services\Gateways\Operations;

use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentOperation;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Gateways\PaymentAttempts;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class OperationPolicy
{
    public function authorize(User $actor, string $permission, Invoice $invoice, Gateway $gateway): void
    {
        $this->authorizeStaff($actor, $permission, $gateway);
        (new PaymentAttempts)->assertInvoice($invoice, currentRead: true);
        foreach ($invoice->items as $item) {
            if (!in_array($item->reference_type, [Service::class, ServiceUpgrade::class, Credit::class], true)) {
                continue;
            }
            // New wallet top-ups may intentionally have no existing wallet ID.
            if ($item->reference_type === Credit::class && !$item->reference_id) {
                continue;
            }
            $reference = $item->reference;
            if ($reference instanceof ServiceUpgrade) {
                $service = $reference->service();
                if (DB::transactionLevel() > 0) {
                    $service->lockForUpdate();
                }
                $reference = $service->first();
            }
            if (!$reference || (int) $reference->user_id !== (int) $invoice->user_id || $reference->currency_code !== $invoice->currency_code) {
                throw new RuntimeException('Invoice item ownership or currency does not match the payment invoice.');
            }
        }
    }

    public function authorizeVerifiedResult(User $actor, string $permission, PaymentOperation $operation, OperationResult $result): void
    {
        if ($operation->kind !== 'provider_refund' || !in_array($permission, ['refund', 'reconcile'], true) || !in_array($result->state, ['succeeded', 'failed'], true)) {
            throw new RuntimeException('Only a verified original refund result can bypass current invoice evidence holds.');
        }
        $result->assertVerified($operation);
        // This private finalization path follows an authorized provider query.
        // Retain its verified truth if authority changed during that query;
        // current monetary authorization is checked separately before posting.
        $current = User::whereKey($actor->id)->lockForUpdate()->first();
        if (!$current || (Auth::check() && Auth::id() !== $current->id)) {
            throw new AuthorizationException('The authenticated provider result reader changed.');
        }
    }

    private function authorizeStaff(User $actor, string $permission, Gateway $gateway): void
    {
        $query = User::whereKey($actor->id);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $actor = $query->first();
        if ($actor) {
            $role = $actor->role();
            if (DB::transactionLevel() > 0) {
                $role->lockForUpdate();
            }
            $actor->setRelation('role', $role->first());
        }
        if (!$actor || !in_array($permission, ['manual_settle', 'manual_unsettle', 'refund', 'capture', 'reconcile'], true) ||
            !$actor->hasPermission('admin.invoice_transactions.' . $permission) || (Auth::check() && Auth::id() !== $actor->id)) {
            throw new AuthorizationException('A dedicated administrative payment permission is required.');
        }
        $enabled = $gateway->settings()->where('key', 'admin_payment_operations_enabled');
        if (DB::transactionLevel() > 0) {
            $enabled->lockForUpdate();
        }
        if (!filter_var($enabled->first()?->value, FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Admin payment operations are disabled for this gateway.');
        }
    }

    public function amount(string $amount): string
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?$/D', $amount) || !BigDecimal::of($amount)->isPositive()) {
            throw new RuntimeException('A positive amount with at most two decimal places is required.');
        }

        return (string) BigDecimal::of($amount)->toScale(2);
    }

    public function request(string $key, string $reason, string $effectiveAt): array
    {
        if (!Str::isUuid($key) || strlen(trim($reason)) < 3 || strlen($reason) > 2000 ||
            !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?$/D', $effectiveAt)) {
            throw new RuntimeException('A request UUID, reason and explicit effective date are required.');
        }
        try {
            $parts = date_parse($effectiveAt);
            if ($parts['warning_count'] || $parts['error_count']) {
                throw new RuntimeException('Invalid calendar date.');
            }
            $date = CarbonImmutable::parse($effectiveAt, config('app.timezone'))->utc();
        } catch (\Throwable) {
            throw new RuntimeException('The effective date is invalid.');
        }

        return [strtolower($key), trim($reason), $date->format('Y-m-d H:i:s')];
    }

    public function configFields(): array
    {
        return [['name' => 'admin_payment_operations_enabled', 'label' => 'Enable admin refunds and settlement operations', 'type' => 'checkbox', 'default' => false,
            'description' => 'Enable only after provider permissions and payment reconciliation are verified. Migration holds still apply.']];
    }
}
