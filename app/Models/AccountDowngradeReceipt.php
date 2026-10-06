<?php

namespace App\Models;

class AccountDowngradeReceipt extends Model
{
    use Traits\GuardsAccountWrites;

    protected $guarded = [];

    protected $hidden = ['proof'];

    protected $casts = ['principal' => 'decimal:4', 'proof' => 'array', 'confirmed_at' => 'immutable_datetime'];
}
