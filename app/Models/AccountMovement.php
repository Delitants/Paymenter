<?php

namespace App\Models;

class AccountMovement extends Model
{
    use Traits\GuardsAccountWrites;

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $casts = ['delta' => 'decimal:4', 'balance_before' => 'decimal:4', 'balance_after' => 'decimal:4', 'payload' => 'array'];

    public function wallet()
    {
        return $this->belongsTo(AccountWallet::class, 'wallet_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function originalMovement()
    {
        return $this->belongsTo(self::class, 'linked_reversal_id');
    }
}
