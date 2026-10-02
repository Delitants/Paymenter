<?php

namespace App\Models;

use RuntimeException;

class InvoicePaidProcessing extends Model
{
    protected $primaryKey = 'invoice_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['processed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Paid invoice processing evidence is immutable.'));
        static::deleting(fn () => throw new RuntimeException('Paid invoice processing evidence cannot be deleted.'));
    }
}
