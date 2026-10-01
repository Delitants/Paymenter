<?php

namespace App\Models;

use App\Classes\Price;
use App\Observers\InvoiceItemObserver;
use App\Services\Billing\InvoicePricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([InvoiceItemObserver::class])]
class InvoiceItem extends Model implements Auditable
{
    use HasFactory, Traits\Auditable, Traits\GuardsMigrationWrites, Traits\GuardsPaymentWrites;

    protected $fillable = [
        'invoice_id',
        'kind',
        'tax_amount',
        'quantity',
        'price',
        'description',
        'gateway_id',
        'reference_id',
        'reference_type',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }

    public function total()
    {
        return (string) BigDecimal::of($this->price)->multipliedBy($this->quantity)->toScale(2);
    }

    public function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->total(), 'currency' => $this->invoice->currency, 'tax_amount' => $this->tax_amount ?? (new InvoicePricing)->lineTax($this->invoice, $this->price, $this->quantity)])
        );
    }

    public function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->price, 'currency' => $this->invoice->currency, 'tax_amount' => (string) BigDecimal::of($this->tax_amount ?? (new InvoicePricing)->lineTax($this->invoice, $this->price, $this->quantity))->dividedBy($this->quantity, 2, RoundingMode::HALF_UP)])
        );
    }
}
