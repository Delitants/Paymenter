<?php

namespace App\Models;

class AccountWallet extends Model
{
    use Traits\GuardsAccountWrites;

    protected $guarded = [];

    protected $hidden = ['opening_evidence'];

    protected $casts = ['opening_balance' => 'decimal:4', 'balance' => 'decimal:4', 'borrowing_limit' => 'decimal:4', 'active' => 'boolean', 'reconciliation_required' => 'boolean', 'opening_evidence' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function movements()
    {
        return $this->hasMany(AccountMovement::class, 'wallet_id');
    }

    public function allocations()
    {
        return $this->hasMany(AccountFundingAllocation::class, 'wallet_id');
    }

    public function reservations()
    {
        return $this->hasMany(AccountReversalReservation::class, 'wallet_id');
    }
}
