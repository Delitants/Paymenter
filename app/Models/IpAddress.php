<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IpAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'ip_pool_id',
        'ip_address',
        'hostname',
        'is_assigned',
        'assigned_to_type',
        'assigned_to_id',
    ];

    protected $casts = [
        'is_assigned' => 'boolean',
    ];

    public function ipPool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class);
    }

    public function assignedTo(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_assigned', false);
    }

    public function scopeAssigned($query)
    {
        return $query->where('is_assigned', true);
    }
}
