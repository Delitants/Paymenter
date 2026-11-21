<?php

namespace App\Models;

class AccountPostingIssue extends Model
{
    use Traits\GuardsAccountWrites;

    protected $guarded = [];

    protected $hidden = ['proof'];

    protected $casts = ['principal' => 'decimal:4', 'proof' => 'array'];
}
