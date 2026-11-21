<?php

namespace App\Models;

use App\Classes\Price;
use App\Enums\InvoiceTransactionStatus;
use App\Observers\InvoiceTransactionObserver;
use App\Services\Billing\InvoicePricing;
use App\Services\Gateways\PaymentWriteGuard;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([InvoiceTransactionObserver::class])]
class InvoiceTransaction extends Model implements Auditable
{
    use HasFactory, Traits\Auditable, Traits\GuardsMigrationWrites, Traits\GuardsPaymentWrites {
        performInsert as protected guardedInsert;
        performUpdate as protected guardedUpdate;
    }

    protected $fillable = [
        'invoice_id',
        'gateway_id',
        'amount',
        'fee',
        'transaction_id',
        'status',
        'is_credit_transaction',
        'settlement_origin',
        'settlement_state',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'status' => InvoiceTransactionStatus::class,
        'is_credit_transaction' => 'boolean',
        'original_allocation' => 'encrypted:array',
    ];

    protected $auditExclude = ['original_allocation'];

    protected function performInsert(Builder $query)
    {
        return (new PaymentWriteGuard)->withPaymentLock($this, function () use ($query) {
            (new PaymentWriteGuard)->assertMutation($this);
            if ($this->original_allocation !== null) {
                throw new \RuntimeException('Original allocation is captured from native received payment evidence.');
            }
            $this->freezeReceivedAllocation();

            return $this->guardedInsert($query);
        });
    }

    protected function performUpdate(Builder $query)
    {
        return (new PaymentWriteGuard)->withPaymentLock($this, function () use ($query) {
            (new PaymentWriteGuard)->assertMutation($this);
            if ($this->isDirty('original_allocation')) {
                throw new \RuntimeException('Original received payment allocation is immutable.');
            }
            if ($this->getRawOriginal('status') !== InvoiceTransactionStatus::Succeeded->value) {
                $this->freezeReceivedAllocation();
            }

            return $this->guardedUpdate($query);
        });
    }

    private function freezeReceivedAllocation(): void
    {
        if ($this->status !== InvoiceTransactionStatus::Succeeded || $this->is_credit_transaction || !$this->gateway_id || $this->original_allocation !== null) {
            return;
        }
        $summary = (new InvoicePricing)->summary($this->invoice()->firstOrFail());
        $product = BigDecimal::of($summary->unpaidNet)->plus($summary->unpaidTax);
        $amount = BigDecimal::of($this->amount);
        // Overpayments lacking an exact invoice allocation remain unproven.
        if (!$amount->isPositive() || $amount->isGreaterThan($summary->payable)) {
            return;
        }
        $gross = $amount->isGreaterThan($product) ? $product : $amount;
        $tax = $gross->isEqualTo($product) ? BigDecimal::of($summary->unpaidTax) :
            $gross->multipliedBy($summary->unpaidTax)->dividedBy($product, 2, RoundingMode::HALF_UP);
        $this->forceFill(['original_allocation' => ['net' => (string) $gross->minus($tax)->toScale(2),
            'tax' => (string) $tax->toScale(2), 'fee' => (string) $amount->minus($gross)->toScale(2)]]);
    }

    public function refundedAmount(): Attribute
    {
        return Attribute::make(get: function () {
            $amount = BigDecimal::of('0.00');
            foreach (PaymentOperation::where('original_transaction_id', $this->id)->whereIn('kind', ['provider_refund', 'external_refund'])->where('state', 'succeeded')->get() as $operation) {
                $amount = $amount->plus($operation->amount);
            }

            return (string) $amount->toScale(2);
        });
    }

    public function isManaged(): bool
    {
        return $this->settlement_origin !== null || $this->original_allocation !== null || PaymentOperation::where(fn ($q) => $q->where('original_transaction_id', $this->id)->orWhere('result_transaction_id', $this->id))->exists();
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }

    public function settlementLabel(): Attribute
    {
        return Attribute::make(get: fn () => $this->status !== InvoiceTransactionStatus::Succeeded || $this->settlement_state === 'unsettled' ? 'Unsettled' : match ($this->settlement_origin) {
            'manual_record' => 'Manually settled — admin recorded',
            'manual_capture' => 'Manually settled — gateway verified',
            default => 'Settled',
        });
    }

    /**
     * Formatted remaining amount of the invoice.
     */
    public function formattedFee(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->fee, 'currency' => $this->invoice->currency])
        );
    }

    /**
     * Formatted remaining amount of the invoice.
     */
    public function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->amount, 'currency' => $this->invoice->currency])
        );
    }
}
