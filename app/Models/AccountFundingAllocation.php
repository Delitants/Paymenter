<?php

namespace App\Models;

class AccountFundingAllocation extends Model
{
    use Traits\GuardsAccountWrites;

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'cash_amount' => 'decimal:4', 'debt_amount' => 'decimal:4', 'reversed_amount' => 'decimal:2'];

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

    public function movement()
    {
        return $this->belongsTo(AccountMovement::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function transaction()
    {
        return $this->belongsTo(InvoiceTransaction::class, 'invoice_transaction_id');
    }
}
