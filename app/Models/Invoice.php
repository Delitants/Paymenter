<?php

namespace App\Models;

use App\Classes\PDF;
use App\Classes\Price;
use App\Models\Traits\HasProperties;
use App\Observers\InvoiceObserver;
use App\Services\Billing\InvoicePricing;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([InvoiceObserver::class])]
class Invoice extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable, Traits\GuardsMigrationWrites, Traits\GuardsPaymentWrites;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['number', 'user_id', 'currency_code', 'due_at', 'status'];

    protected $casts = [
        'due_at' => 'date',
        'pricing_tax_rate' => 'decimal:4',
        'pricing_tax_inclusive' => 'boolean',
    ];

    public bool $send_create_email = true;

    /**
     * Total of the invoice.
     *
     * @return string
     */
    public function total(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) (new InvoicePricing)->summary($this)->total
        );
    }

    /**
     * Total of the invoice.
     *
     * @return string
     */
    public function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: function () {
                $summary = (new InvoicePricing)->summary($this);

                return new Price(['price' => $summary->total, 'currency' => $this->currency, 'tax_amount' => $summary->productTax]);
            }
        );
    }

    /**
     * Formatted remaining amount of the invoice.
     */
    public function formattedRemaining(): Attribute
    {
        return Attribute::make(
            get: function () {
                $summary = (new InvoicePricing)->summary($this);

                return new Price(['price' => $summary->payable, 'currency' => $this->currency, 'tax_amount' => $summary->unpaidTax]);
            }
        );
    }

    /**
     * Remaining amount of the invoice.
     */
    public function remaining(): Attribute
    {
        return Attribute::make(
            get: function () {
                $summary = (new InvoicePricing)->summary($this);

                return (float) (string) BigDecimal::of($summary->total)->minus($summary->paid);
            }
        );
    }

    /**
     * Get tax model of the invoice.
     * Either via locked invoice or via users country.
     */
    public function tax(): Attribute
    {
        return Attribute::make(get: function () {
            $context = (new InvoicePricing)->context($this);

            return $context['rate'] === '0.0000' ? 0 : new TaxRate(['name' => $context['name'], 'rate' => $context['rate'], 'country' => $context['country']]);
        });
    }

    public function userProperties(): Attribute
    {
        if (config('settings.invoice_snapshot', true) && $this?->snapshot?->properties) {
            return Attribute::make(
                get: fn () => $this->snapshot->properties
            );
        }

        return Attribute::make(
            get: fn () => $this->user->properties()->with('parent_property')->whereHas('parent_property', function ($query) {
                $query->where('show_on_invoice', true);
            })->pluck('value', 'key')->toArray()
        );
    }

    public function userName(): Attribute
    {
        if (config('settings.invoice_snapshot', true) && $this?->snapshot?->name) {
            return Attribute::make(
                get: fn () => $this->snapshot->name
            );
        }

        return Attribute::make(
            get: fn () => $this->user->name
        );
    }

    public function billTo(): Attribute
    {
        if (config('settings.invoice_snapshot', true) && $this?->snapshot?->bill_to) {
            return Attribute::make(
                get: fn () => $this->snapshot->bill_to
            );
        }

        return Attribute::make(
            get: fn () => config('settings.bill_to_text', config('settings.company_name'))
        );
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function transactions()
    {
        return $this->hasMany(InvoiceTransaction::class);
    }

    public function paymentOperations()
    {
        return $this->hasMany(PaymentOperation::class);
    }

    public function snapshot()
    {
        return $this->hasOne(InvoiceSnapshot::class);
    }

    public function pdf(): Attribute
    {
        return Attribute::make(
            get: fn () => PDF::generateInvoice($this)
        );
    }

    public function getRouteKey()
    {
        // Prefer using number if it’s set, otherwise fallback to id
        return $this->number ?: $this->id;
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return $this->where($field, $value)->firstOrFail();
        }

        // Try to find by number first
        $query = $this->where('number', $value);

        // Only try to match by ID if value is numeric
        if (is_numeric($value)) {
            $query->orWhere('id', $value);
        }

        return $query->orderByRaw('(number = ?) DESC', [$value])->firstOrFail();
    }
}
