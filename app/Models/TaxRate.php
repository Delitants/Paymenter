<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class TaxRate extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $casts = ['rate' => 'decimal:4'];

    protected $fillable = ['name', 'rate', 'country'];
}
