<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpPool extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'ip_version',
        'subnet_mask',
        'gateway',
        'dns_primary',
        'dns_secondary',
        'server_id',
    ];

    protected $casts = [
        'ip_version' => 'string',
    ];

    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function getAvailableIpsAttribute(): int
    {
        return $this->ipAddresses()->where('is_assigned', false)->count();
    }

    public function getTotalIpsAttribute(): int
    {
        return $this->ipAddresses()->count();
    }
}
