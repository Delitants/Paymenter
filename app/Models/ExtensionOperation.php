<?php

namespace App\Models;

/** Durable provider claim. Payloads contain private contact data and must stay encrypted. */
class ExtensionOperation extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array'];
}
