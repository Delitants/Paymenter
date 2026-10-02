<?php

namespace App\Models;

use App\Enums\InvoiceTransactionStatus;
use App\Services\Gateways\Operations\OperationPolicy;
use App\Services\Gateways\Operations\OperationResult;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

class PaymentOperation extends Model implements Auditable
{
    use Traits\Auditable;

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'payload' => 'encrypted:array', 'actor_snapshot' => 'encrypted:array', 'effective_at' => 'immutable_datetime', 'outcome_evidence' => 'encrypted:array'];

    protected $auditExclude = ['payload', 'actor_snapshot', 'outcome_evidence'];

    protected static function booted(): void
    {
        static::creating(function (self $operation) {
            if (self::$claimRequest !== $operation->request_key || $operation->state !== 'queued' || DB::transactionLevel() === 0) {
                throw new RuntimeException('Payment operations must be created by a locked journal claim.');
            }
        });
        static::updating(function (self $operation) {
            if ($operation->isDirty(['state', 'provider_reference', 'result_transaction_id', 'outcome_code', 'outcome_evidence']) && self::$outcomeWrite !== $operation->id) {
                throw new RuntimeException('Payment outcomes require a bounded journal transition.');
            }
            if ($operation->isDirty(['request_key', 'kind', 'invoice_id', 'gateway_id', 'original_transaction_id', 'actor_id', 'actor_snapshot', 'amount', 'currency_code', 'reason', 'effective_at', 'request_fingerprint', 'source_fingerprint', 'payload'])) {
                throw new RuntimeException('Payment operation identity and request details are immutable.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Payment operation history cannot be deleted.'));
    }

    private static ?int $outcomeWrite = null;

    private static ?string $claimRequest = null;

    public static function claimLockedRequest(User $actor, Invoice $invoice, Gateway $gateway, array $attributes): self
    {
        if (DB::transactionLevel() === 0 || self::$claimRequest !== null ||
            ($attributes['state'] ?? null) !== 'queued' || ($attributes['invoice_id'] ?? null) !== $invoice->id ||
            ($attributes['gateway_id'] ?? null) !== $gateway->id || ($attributes['actor_id'] ?? null) !== $actor->id ||
            ($attributes['currency_code'] ?? null) !== $invoice->currency_code ||
            array_intersect(['provider_reference', 'result_transaction_id', 'outcome_code', 'outcome_evidence'], array_keys($attributes))) {
            throw new RuntimeException('A journal claim requires its original locked financial identity.');
        }
        $permission = match ($attributes['kind'] ?? null) {
            'manual_receipt', 'manual_restore' => 'manual_settle', 'manual_unsettle' => 'manual_unsettle',
            'external_refund', 'provider_refund' => 'refund', 'provider_capture' => 'capture',
            default => throw new RuntimeException('Unsupported payment operation claim.'),
        };
        $policy = new OperationPolicy;
        $policy->authorize($actor, $permission, $invoice, $gateway);
        $policy->amount($attributes['amount']);
        $policy->request($attributes['request_key'], $attributes['reason'], $attributes['effective_at']);
        self::$claimRequest = $attributes['request_key'];
        try {
            return self::create($attributes);
        } finally {
            self::$claimRequest = null;
        }
    }

    /** Claim a single write durably. A replay never enters the write phase again. */
    public function startExecution(User $actor): bool
    {
        $this->assertLockedProvider();
        if ($this->state !== 'queued') {
            return false;
        }
        (new OperationPolicy)->authorize($actor, $this->kind === 'provider_capture' ? 'capture' : 'refund', $this->invoice, $this->gateway);
        $executor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $history = $this->outcome_evidence ?? [];
        $history[] = ['state' => 'processing', 'provider_reference' => null, 'outcome_code' => 'execution_started',
            'actor_id' => $executor->id, 'actor_snapshot' => ['id' => $executor->id, 'name' => $executor->name],
            'recorded_at' => now()->utc()->format('Y-m-d H:i:s'), 'evidence' => []];
        $this->writeOutcome(['state' => 'processing', 'outcome_evidence' => $history]);

        return true;
    }

    public function recordManualResult(string $outcomeCode, ?InvoiceTransaction $transaction = null): void
    {
        if (DB::transactionLevel() === 0 || $this->state !== 'queued' ||
            !in_array($this->kind, ['manual_receipt', 'manual_unsettle', 'manual_restore', 'external_refund'], true)) {
            throw new RuntimeException('Manual outcome requires its claimed operation transaction.');
        }
        $this->refreshLockedRequest();
        if ($this->state !== 'queued') {
            throw new RuntimeException('Manual operation is already completed.');
        }
        $expected = match ($this->kind) {
            'manual_receipt' => 'manual_recorded', 'manual_unsettle' => 'unsettled', 'manual_restore' => 'settled', 'external_refund' => 'external_recorded',
        };
        if ($outcomeCode !== $expected || ($this->kind === 'external_refund' ? $transaction !== null :
            (!$transaction || $transaction->invoice_id !== $this->invoice_id || $transaction->gateway_id !== $this->gateway_id ||
                !BigDecimal::of($transaction->amount)->isEqualTo($this->amount) ||
                $transaction->settlement_origin !== 'manual_record' || $transaction->settlement_state !== ($this->kind === 'manual_unsettle' ? 'unsettled' : 'settled')))) {
            throw new RuntimeException('Manual outcome does not match the original operation.');
        }
        $this->writeOutcome(['state' => 'succeeded', 'outcome_code' => $outcomeCode, 'result_transaction_id' => $transaction?->id]);
    }

    public function recordProviderResult(OperationResult $result, User $actor, ?InvoiceTransaction $transaction = null): void
    {
        $this->assertLockedProvider();
        if (!in_array($this->state, ['processing', 'pending', 'uncertain'], true)) {
            throw new RuntimeException('Terminal provider operation history is immutable.');
        }
        if (in_array($result->state, ['succeeded', 'failed'], true)) {
            $result->assertVerified($this);
        }
        if ($result->state === 'succeeded' && $this->kind === 'provider_capture' &&
            (!$transaction || $transaction->invoice_id !== $this->invoice_id || $transaction->gateway_id !== $this->gateway_id ||
                $transaction->status !== InvoiceTransactionStatus::Succeeded || $transaction->settlement_origin !== 'manual_capture' ||
                $transaction->transaction_id !== 'gateway:' . $this->gateway_id . ':' . $result->providerReference ||
                !BigDecimal::of($transaction->amount)->isEqualTo($this->amount))) {
            throw new RuntimeException('Capture outcome is missing its verified native payment.');
        }
        $history = $this->outcome_evidence ?? [];
        $history[] = ['state' => $result->state, 'provider_reference' => $result->providerReference, 'outcome_code' => $result->outcomeCode,
            'actor_id' => $actor->id, 'recorded_at' => now()->utc()->format('Y-m-d H:i:s'), 'evidence' => $result->safeEvidence()];
        $this->writeOutcome(['state' => $result->state, 'provider_reference' => $result->providerReference ?? $this->provider_reference,
            'outcome_code' => $result->outcomeCode, 'outcome_evidence' => $history, 'result_transaction_id' => $transaction?->id ?? $this->result_transaction_id]);
    }

    private function assertLockedProvider(): void
    {
        if (DB::transactionLevel() === 0 || !in_array($this->kind, ['provider_refund', 'provider_capture'], true) ||
            !in_array($this->state, ['queued', 'processing', 'pending', 'uncertain', 'succeeded', 'failed'], true)) {
            throw new RuntimeException('Provider outcome requires its locked claimed operation.');
        }
        $this->refreshLockedRequest();
    }

    private function refreshLockedRequest(): void
    {
        $stored = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
        if ($stored->state !== $this->state || $stored->request_fingerprint !== $this->request_fingerprint ||
            $this->isDirty(['request_key', 'kind', 'invoice_id', 'gateway_id', 'original_transaction_id', 'actor_id', 'actor_snapshot',
                'amount', 'currency_code', 'reason', 'effective_at', 'request_fingerprint', 'source_fingerprint', 'payload'])) {
            throw new RuntimeException('Operation changed; reconcile its stored immutable request.');
        }
        $this->setRawAttributes($stored->getAttributes(), true);
    }

    private function writeOutcome(array $attributes): void
    {
        if (self::$outcomeWrite !== null) {
            throw new RuntimeException('Nested operation outcomes are not allowed.');
        }
        self::$outcomeWrite = $this->id;
        try {
            $this->update($attributes);
        } finally {
            self::$outcomeWrite = null;
        }
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }

    public function originalTransaction()
    {
        return $this->belongsTo(InvoiceTransaction::class, 'original_transaction_id');
    }

    public function resultTransaction()
    {
        return $this->belongsTo(InvoiceTransaction::class, 'result_transaction_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
