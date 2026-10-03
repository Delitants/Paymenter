<?php

namespace App\Models;

use App\Services\Billing\MoneyCalculator;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class Coupon extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'type',
        'time',
        'code',
        'value',
        'max_uses',
        'max_uses_per_user',
        'starts_at',
        'expires_at',
        'recurring',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'max_uses_per_user' => 'integer',
        'value' => 'decimal:4',
    ];

    /**
     * Get the products that belong to the option.
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'coupon_products');
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Check if the user has exceeded the maximum allowed uses of this coupon
     *
     * @param  int  $userId
     */
    public function hasExceededMaxUsesPerUser($userId): bool
    {
        if (empty($this->max_uses_per_user)) {
            return false;
        }

        return $this->services()
            ->where('user_id', $userId)
            ->count() >= $this->max_uses_per_user;
    }

    public function calculateDiscount($price, $type = 'price')
    {
        return (float) $this->calculateDiscountDecimal((string) $price, $type);
    }

    public function calculateDiscountDecimal(string $price, string $type = 'price'): string
    {
        if (!in_array($type, ['price', 'setup_fee'])) {
            throw new \InvalidArgumentException('Invalid type for coupon discount calculation');
        }
        $calculator = new MoneyCalculator;
        $amount = $calculator->money($price);
        if (!in_array($this->applies_to, ['all', $type])) {
            return '0.00';
        }
        $discount = match ($this->type) {
            'percentage' => $amount->multipliedBy($calculator->rate((string) $this->value))->dividedBy(100, 2, RoundingMode::HALF_UP),
            'fixed' => $calculator->money((string) $this->value),
            default => $calculator->money('0.00'),
        };

        return (string) ($discount->isGreaterThan($amount) ? $amount : $discount);
    }
}
