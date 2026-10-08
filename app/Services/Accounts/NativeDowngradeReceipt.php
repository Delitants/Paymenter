<?php

namespace App\Services\Accounts;

use App\Helpers\ExtensionHelper;
use App\Models\AccountDowngradeReceipt;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\BillmanagerMigration\MigrationHold;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Evidence of native completion, distinct from a mutable upgrade status marker. */
final class NativeDowngradeReceipt
{
    private static ?array $write = null;

    private static ?int $recordId = null;

    public static function complete(ServiceUpgrade $upgrade, Closure $native): ?AccountDowngradeReceipt
    {
        if (DB::transactionLevel() === 0 || !AccountPaymentLocks::coversUpgrade($upgrade->id) || $upgrade->status !== ServiceUpgrade::STATUS_PENDING) {
            throw new RuntimeException('Downgrade capture requires the original pending upgrade and its prelocked graph.');
        }
        $service = $upgrade->service;
        $before = self::serviceIdentity($service);
        $principal = $upgrade->invoice_id === null ? self::principal($upgrade) : '0.0000';
        $native();
        $service = $service->fresh();
        if ($upgrade->fresh()->status !== ServiceUpgrade::STATUS_COMPLETED || $service->product_id !== $upgrade->product_id ||
            $service->plan_id !== $upgrade->plan_id || $service->user_id !== $before['user_id'] || $service->currency_code !== $before['currency_code']) {
            throw new RuntimeException('Native downgrade did not complete its original service transition.');
        }
        if ($principal === '0.0000' || !config('settings.credits_on_downgrade', true)) {
            return null;
        }
        $proof = ['upgrade_id' => $upgrade->id, 'owner_id' => $before['user_id'], 'currency' => $before['currency_code'],
            'principal' => $principal, 'processed_at' => now()->format('Y-m-d H:i:s'), 'original_service' => $before,
            'completed_service' => self::serviceIdentity($service)];
        $state = $service->product->server_id ? 'prepared' : 'unneeded';
        $expected = ['service_upgrade_id' => $upgrade->id, 'user_id' => $service->user_id, 'currency_code' => $service->currency_code,
            'principal' => $principal, 'proof' => $proof, 'provider_state' => $state, 'confirmed_at' => null];

        return self::write($expected, null, fn () => AccountDowngradeReceipt::create($expected));
    }

    public static function principal(ServiceUpgrade $upgrade): string
    {
        $amount = AccountAmount::parse(self::proratedAmount($upgrade));

        return $amount->compare(AccountAmount::parse('0')) < 0 ? AccountAmount::parse('0')->subtract($amount)->exact() : '0.0000';
    }

    public static function proratedAmount(ServiceUpgrade $upgrade): string
    {
        $service = $upgrade->service;
        $plan = $service->plan;
        $period = match ($plan->billing_unit) {
            'day' => $plan->billing_period, 'week' => $plan->billing_period * 7,
            'month' => $plan->billing_period * 30, 'year' => $plan->billing_period * 365, default => 0,
        };
        $days = $service->expires_at ? (int) min($period, max(0, now()->startOfDay()->diffInDays($service->expires_at->copy()->startOfDay(), false))) : 0;
        $difference = self::difference($upgrade, $service->product, $upgrade->product);
        foreach ($upgrade->configs as $config) {
            if ($config->configValue) {
                $old = $service->configs->firstWhere('config_option_id', $config->config_option_id)?->configValue;
                $difference = $difference->plus(self::difference($upgrade, $old, $config->configValue));
            }
        }
        if (!$service->expires_at) {
            return $difference->isNegative() ? '0.00' : (string) AccountAmount::parse((string) $difference->toScale(2))->floorCents();
        }
        if ($days === 0 || $period <= 0) {
            return '0.00';
        }
        if ($difference->isNegative() && $service->coupon_id) {
            $cap = BigDecimal::of($service->calculatePrice());
            $difference = $difference->abs()->isGreaterThan($cap) ? $cap->negated() : $difference;
        }
        $amount = $difference->multipliedBy($days)->dividedBy($period, 2, RoundingMode::HALF_UP);
        AccountAmount::parse((string) $amount);

        return (string) $amount;
    }

    private static function difference(ServiceUpgrade $upgrade, mixed $old, mixed $new): BigDecimal
    {
        if (!$new || ($old && $old->is($new))) {
            return BigDecimal::of('0');
        }
        $plan = $upgrade->service->plan;
        $price = fn ($item) => $item ? BigDecimal::of($item->price(null, $plan->billing_period, $plan->billing_unit, $upgrade->service->currency_code)->price_decimal) : BigDecimal::of('0');

        return $price($new)->minus($price($old));
    }

    private static function serviceIdentity(Service $service): array
    {
        return $service->only(['id', 'user_id', 'currency_code', 'product_id', 'plan_id', 'price', 'quantity', 'status']) +
            ['expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
                'configs' => $service->configs()->orderBy('config_option_id')->get(['config_option_id', 'config_value_id'])->toArray()];
    }

    public static function read(int $upgradeId, bool $enforceHolds = true): array
    {
        if (DB::transactionLevel() === 0 || !AccountPaymentLocks::coversUpgrade($upgradeId)) {
            throw new RuntimeException('Completed downgrade verification requires its complete prelocked graph.');
        }
        $upgrade = ServiceUpgrade::whereKey($upgradeId)->lockForUpdate()->firstOrFail();
        $receipt = AccountDowngradeReceipt::where('service_upgrade_id', $upgradeId)->lockForUpdate()->first();
        if (!$receipt || $upgrade->status !== ServiceUpgrade::STATUS_COMPLETED || !in_array($receipt->provider_state, ['unneeded', 'confirmed'], true) ||
            $receipt->user_id !== $receipt->proof['owner_id'] || $receipt->currency_code !== $receipt->proof['currency'] ||
            $receipt->principal !== $receipt->proof['principal'] || $receipt->proof['upgrade_id'] !== $upgradeId ||
            $upgrade->service_id !== $receipt->proof['original_service']['id'] || $upgrade->plan_id !== $receipt->proof['completed_service']['plan_id'] ||
            $upgrade->product_id !== $receipt->proof['completed_service']['product_id']) {
            throw new RuntimeException('A verified completed native downgrade receipt is required.');
        }
        if ($enforceHolds) {
            MigrationHold::assertAllowed($upgrade, 'credit completed downgrade', true);
            $current = Service::whereKey($upgrade->service_id)->lockForUpdate()->firstOrFail();
            MigrationHold::assertAllowed($current, 'credit completed downgrade', true);
            if (self::serviceIdentity($current) !== $receipt->proof['completed_service']) {
                throw new RuntimeException('Completed downgrade service identity changed before account posting.');
            }
        }

        return $receipt->proof + ['receipt_id' => $receipt->id];
    }

    /** Execute once outside financial locks; store confirmation before any wallet posting. */
    public static function fulfill(int $upgradeId): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider downgrade completion requires a durable native claim.');
        }
        $claimed = self::withUpgrade($upgradeId, function ($upgrade) {
            $receipt = AccountDowngradeReceipt::where('service_upgrade_id', $upgrade->id)->lockForUpdate()->firstOrFail();
            if (in_array($receipt->provider_state, ['unneeded', 'confirmed'], true)) {
                return false;
            }
            if (!in_array($receipt->provider_state, ['prepared', 'failed'], true)) {
                throw new RuntimeException('Provider downgrade outcome requires reconciliation before another request.');
            }
            $service = Service::whereKey($upgrade->service_id)->lockForUpdate()->firstOrFail();
            MigrationHold::assertAllowed($service, 'complete provider downgrade', true);
            if (self::serviceIdentity($service) !== $receipt->proof['completed_service']) {
                throw new RuntimeException('Provider downgrade service identity changed.');
            }
            self::transition($receipt, 'processing', null);

            return $service;
        });
        if ($claimed !== false) {
            try {
                $result = ExtensionHelper::upgradeServer($claimed);
                $success = $result === true;
                $knownFailure = $result === false;
            } catch (Throwable $exception) {
                self::withUpgrade($upgradeId, function ($upgrade) {
                    $receipt = AccountDowngradeReceipt::where('service_upgrade_id', $upgrade->id)->lockForUpdate()->firstOrFail();
                    self::transition($receipt, 'uncertain', null);
                });
                throw $exception;
            }
            self::withUpgrade($upgradeId, function ($upgrade) use ($success, $knownFailure) {
                $receipt = AccountDowngradeReceipt::where('service_upgrade_id', $upgrade->id)->lockForUpdate()->firstOrFail();
                self::transition($receipt, $success ? 'confirmed' : ($knownFailure ? 'failed' : 'uncertain'), $success ? now() : null);
            });
            if (!$success) {
                throw new RuntimeException('Provider did not confirm the downgrade.');
            }
        }
        (new DepositLifecycle)->creditCompletedDowngrade(ServiceUpgrade::findOrFail($upgradeId));
    }

    private static function withUpgrade(int $id, Closure $write): mixed
    {
        return DB::transaction(function () use ($id, $write) {
            $snapshot = ServiceUpgrade::findOrFail($id);

            return (new AccountPaymentLocks)->duringIncomingEvidence(array_filter([$snapshot->invoice_id]),
                fn () => $write(ServiceUpgrade::whereKey($id)->lockForUpdate()->firstOrFail()), [], [$snapshot->service_id], [$id]);
        }, 3);
    }

    private static function transition(AccountDowngradeReceipt $receipt, string $state, mixed $at): void
    {
        $allowed = ['prepared' => ['processing'], 'failed' => ['processing'], 'processing' => ['failed', 'uncertain', 'confirmed']];
        if (!in_array($state, $allowed[$receipt->provider_state] ?? [], true)) {
            throw new RuntimeException('Contradictory provider downgrade outcome.');
        }
        self::write(['provider_state' => $state, 'confirmed_at' => $at], $receipt->id, fn () => $receipt->update(['provider_state' => $state, 'confirmed_at' => $at]));
    }

    private static function write(array $expected, ?int $id, Closure $write): mixed
    {
        if (self::$write !== null) {
            throw new RuntimeException('Nested downgrade receipt writes are not allowed.');
        }
        self::$write = $expected;
        self::$recordId = $id;
        try {
            return $write();
        } finally {
            self::$write = null;
            self::$recordId = null;
        }
    }

    public static function assertRecordWrite(AccountDowngradeReceipt $record, string $action): void
    {
        $expected = self::$write;
        if (DB::transactionLevel() === 0 || $expected === null || $action === 'delete' || $record->getKey() !== self::$recordId ||
            ($action === 'create' ? array_diff(array_keys($record->getAttributes()), array_keys($expected)) :
                array_diff(array_keys($record->getDirty()), [...array_keys($expected), 'updated_at']))) {
            throw new RuntimeException('Downgrade completion evidence is immutable outside its native transition.');
        }
        foreach ($expected as $key => $value) {
            $actual = $record->{$key};
            if ($key === 'confirmed_at') {
                $actual = $actual?->format('Y-m-d H:i:s');
                $value = $value?->format('Y-m-d H:i:s');
            }
            if ($actual !== $value) {
                throw new RuntimeException('Downgrade completion evidence changed.');
            }
        }
    }
}
