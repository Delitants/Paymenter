<?php

namespace App\Models;

class GatewayPaymentAttempt extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'provider_payload' => 'encrypted:array', 'pricing_payload' => 'encrypted:array'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }
}
