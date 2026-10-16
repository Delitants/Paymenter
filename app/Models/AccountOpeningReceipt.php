<?php

namespace App\Models;

class AccountOpeningReceipt extends Model
{
    use Traits\GuardsOpeningHistory;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['delta'];

    protected $casts = ['delta' => 'array'];
}
