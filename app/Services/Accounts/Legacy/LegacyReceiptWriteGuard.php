<?php

namespace App\Services\Accounts\Legacy;

use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Models\LegacyReceiptBudget;
use App\Models\LegacyReceiptConflict;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Exact, transaction-scoped evidence writes. Privileged SQL is outside this boundary. */
final class LegacyReceiptWriteGuard
{
    public const STORE_KEY = 'legacy-receipt-store-v1';

    private static ?self $active = null;

    private bool $failed = false;

    private ?array $pending = null;

    private array $written = [];

    private ?int $budgetId;

    private int $baseBytes;

    private int $baseCount;

    private bool $conflict = false;

    private function __construct(private readonly LegacyRetentionContext $context)
    {
        $budget = LegacyReceiptBudget::where('store_key', self::STORE_KEY)->lockForUpdate()->first();
        $this->budgetId = $budget ? (int) $budget->id : null;
        $this->baseBytes = $budget ? (int) $budget->retained_bytes : 0;
        $this->baseCount = $budget ? (int) $budget->retained_observations : 0;
    }

    public static function configuration(): array
    {
        return ['enabled' => config('legacy-payments.receipt_ingress_enabled'), 'path' => config('legacy-payments.trust_path'), 'gid' => config('legacy-payments.trust_reader_gid')];
    }

    public static function configuredTrust(): LegacyTransportTrust
    {
        $configuration = self::configuration();
        if ($configuration['enabled'] !== true || !is_string($configuration['path']) || !is_int($configuration['gid']) || $configuration['gid'] < 0) {
            throw new DomainException('Legacy receipt retention requires enabled protected configuration.');
        }
        try {
            return LegacyTrustReader::read($configuration['path'], $configuration['gid']);
        } catch (\Throwable $exception) {
            throw new DomainException('Current protected legacy trust is unavailable.', previous: $exception);
        }
    }

    public static function during(LegacyRetentionContext $context, Closure $write): mixed
    {
        if (self::$active !== null || DB::transactionLevel() === 0) {
            self::deny();
        }
        self::assertCurrent($context);

        // A savepoint also protects callers that catch a rejected scope while
        // retaining their outer transaction. It is not a retry or outer commit.
        return DB::transaction(function () use ($context, $write) {
            $scope = new self($context);
            self::$active = $scope;
            try {
                $result = $write();
                $scope->assertComplete();
                self::assertCurrent($context);

                return $result;
            } finally {
                self::$active = null;
            }
        }, 1);
    }

    private static function assertCurrent(LegacyRetentionContext $context): void
    {
        if (self::configuration() !== $context->configuration) {
            self::deny();
        }
        $trust = self::configuredTrust();
        if ($trust->sha256 !== $context->observation->policySha256) {
            self::deny();
        }
        $clock = app()->bound(LegacyClock::class) ? app(LegacyClock::class) : new SystemLegacyClock;
        try {
            AuthenticatedLegacyObservation::verify($context->observation->payloadBytes, $context->observation->signatureBytes, $trust, $clock->now());
        } catch (\Throwable $exception) {
            if (self::$active) {
                self::$active->failed = true;
            }
            throw new DomainException('Legacy retention authentication is no longer current.', previous: $exception);
        }
    }

    public static function deny(): never
    {
        if (self::$active) {
            self::$active->failed = true;
        }
        throw new DomainException('Legacy evidence requires its exact guarded retention transition.');
    }

    public static function assertRecordWrite(Model $model, string $operation): void
    {
        $scope = self::$active;
        if (!$scope || $scope->failed || $scope->pending !== null || DB::transactionLevel() === 0 || $model->getConnection() !== DB::connection()) {
            self::deny();
        }
        $class = get_class($model);
        $tables = [LegacyEffect::class => 'legacy_effects', LegacyObservation::class => 'legacy_observations', LegacyReceiptConflict::class => 'legacy_receipt_conflicts', LegacyReceiptBudget::class => 'legacy_receipt_budgets'];
        if (!isset($tables[$class]) || $model->getTable() !== $tables[$class] || !in_array($operation, ['create', 'update'], true)) {
            self::deny();
        }
        $expected = $scope->expected($class, $operation, $model);
        $actual = $model->getAttributes();
        ksort($expected);
        ksort($actual);
        if ($actual !== $expected) {
            self::deny();
        }
        $scope->pending = [$model, $operation, $expected, $model->getTable()];
    }

    private function expected(string $class, string $operation, Model $model): array
    {
        if ($class !== LegacyReceiptBudget::class && ($operation !== 'create' || isset($this->written[$class]) || isset($this->written['budget_updated']))) {
            self::deny();
        }
        if ($class === LegacyReceiptBudget::class) {
            if ($operation === 'create') {
                if ($this->budgetId !== null || LegacyEffect::exists() || LegacyObservation::exists() || LegacyReceiptConflict::exists()) {
                    self::deny();
                }

                return ['store_key' => self::STORE_KEY, 'retained_bytes' => 0, 'retained_observations' => 0];
            }
            if ($this->budgetId === null || (int) $model->id !== $this->budgetId || isset($this->written['budget_updated']) || !isset($this->written[LegacyObservation::class]) || $this->conflict !== isset($this->written[LegacyReceiptConflict::class])) {
                self::deny();
            }
            [$bytes, $count] = $this->usage();
            if ($bytes > $this->context->trust->data['retained_byte_limit'] || $count > $this->context->trust->data['retained_observation_limit']) {
                self::deny();
            }

            return ['id' => $this->budgetId, 'store_key' => self::STORE_KEY, 'retained_bytes' => $bytes, 'retained_observations' => $count];
        }
        if ($this->budgetId === null) {
            self::deny();
        }
        $effect = LegacyEffect::where('effect_key', $this->context->observation->identity->key())->lockForUpdate()->first();
        if ($class === LegacyEffect::class) {
            if ($effect !== null) {
                self::deny();
            }

            return $this->context->effectAttributes();
        }
        if ($effect === null) {
            self::deny();
        }
        $this->conflict = $effect->economic_sha256 !== $this->context->observation->economic->fingerprint();
        if ($class === LegacyObservation::class) {
            return $this->context->observationAttributes((int) $effect->id, $this->conflict ? 'economic_conflict' : 'pending_source_qualification');
        }
        if (!$this->conflict || !isset($this->written[LegacyObservation::class])) {
            self::deny();
        }

        return ['effect_id' => (int) $effect->id, 'observation_id' => $this->written[LegacyObservation::class],
            'expected_economic_sha256' => $effect->economic_sha256, 'observed_economic_sha256' => $this->context->observation->economic->fingerprint(),
            'reason' => 'economic_facts_changed', 'created_at' => $this->context->createdAt];
    }

    /** Recheck after Eloquent creating/updating observers, immediately before SQL. */
    public static function assertPendingSql(Model $model, string $operation, array $values): void
    {
        $scope = self::$active;
        if (!$scope || $scope->failed || $scope->pending === null || $scope->pending[0] !== $model || $scope->pending[1] !== $operation || $scope->pending[3] !== $model->getTable() || $model->getConnection() !== DB::connection()) {
            self::deny();
        }
        $expected = $scope->pending[2];
        $actual = $model->getAttributes();
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            self::deny();
        }
        if ($operation === 'update') {
            if ((int) $model->getRawOriginal('id') !== $scope->budgetId) {
                self::deny();
            }
            $expected = ['retained_bytes' => $expected['retained_bytes'], 'retained_observations' => $expected['retained_observations']];
        }
        ksort($values);
        ksort($expected);
        if ($values !== $expected) {
            self::deny();
        }
    }

    public static function recordWritten(Model $model, string $operation): void
    {
        $scope = self::$active;
        if (!$scope || $scope->pending === null || $scope->pending[0] !== $model || $scope->pending[1] !== $operation) {
            self::deny();
        }
        $expected = $scope->pending[2];
        $actual = $model->getAttributes();
        if ($operation === 'create') {
            if (!is_int($model->id) || $model->id <= 0) {
                self::deny();
            }
            unset($actual['id']);
        }
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected || $model->getConnection() !== DB::connection() || $model->getTable() !== $scope->pending[3]) {
            self::deny();
        }
        $class = get_class($model);
        if ($class === LegacyReceiptBudget::class) {
            if ($operation === 'create') {
                $scope->budgetId = (int) $model->id;
            } else {
                $scope->written['budget_updated'] = (int) $model->id;
            }
        }
        $scope->written[$class] = (int) $model->id;
        $scope->pending = null;
    }

    private function usage(): array
    {
        $charge = 0;
        if (isset($this->written[LegacyEffect::class])) {
            $attributes = $this->context->effectAttributes();
            $charge += strlen($attributes['identity_json']) + strlen($attributes['economic_json']);
        }
        if (isset($this->written[LegacyObservation::class])) {
            $charge += strlen($this->context->observation->payloadBytes) + strlen($this->context->observation->signatureBytes);
        }
        if ($this->baseBytes < 0 || $this->baseCount < 0 || $this->baseBytes > PHP_INT_MAX - $charge || $this->baseCount === PHP_INT_MAX) {
            self::deny();
        }

        return [$this->baseBytes + $charge, $this->baseCount + (isset($this->written[LegacyObservation::class]) ? 1 : 0)];
    }

    private function assertComplete(): void
    {
        if ($this->failed || $this->pending !== null) {
            self::deny();
        }
        if (!$this->written) {
            return;
        }
        if (!isset($this->written[LegacyObservation::class], $this->written['budget_updated']) || $this->conflict !== isset($this->written[LegacyReceiptConflict::class])) {
            self::deny();
        }
        $budget = LegacyReceiptBudget::find($this->budgetId);
        [$bytes, $count] = $this->usage();
        if (!$budget || $budget->retained_bytes !== $bytes || $budget->retained_observations !== $count || !LegacyObservation::whereKey($this->written[LegacyObservation::class])->where('observation_key', $this->context->observationKey())->exists()) {
            self::deny();
        }
    }
}
