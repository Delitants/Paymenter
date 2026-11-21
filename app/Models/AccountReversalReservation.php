<?php

namespace App\Models;

class AccountReversalReservation extends Model
{
    use Traits\GuardsAccountWrites;

    protected $guarded = [];

    protected $casts = ['principal' => 'decimal:4', 'posting_required' => 'boolean'];

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

    public function depositMovement()
    {
        return $this->belongsTo(AccountMovement::class, 'deposit_movement_id');
    }

    public function postedMovement()
    {
        return $this->belongsTo(AccountMovement::class, 'posted_movement_id');
    }

    public function operation()
    {
        return $this->belongsTo(PaymentOperation::class, 'payment_operation_id');
    }
}
