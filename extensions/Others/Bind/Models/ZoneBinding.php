<?php

namespace Paymenter\Extensions\Others\Bind\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ZoneBinding extends Model
{
    use HasUuids;

    protected $table = 'ext_bind_zones';

    protected $fillable = ['zone', 'service_id'];

    protected $casts = ['enabled' => 'boolean', 'approved_at' => 'datetime'];

    protected static function booted(): void
    {
        self::creating(function (self $m) {
            $zone = idn_to_ascii(rtrim((string) $m->zone, '.'), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!$zone || strlen($zone) > 253 || !filter_var($zone, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new \InvalidArgumentException('Invalid zone name');
            }$m->zone = strtolower($zone) . '.';
        });
        self::updating(function (self $m) {
            if ($m->isDirty(['id', 'zone', 'service_id'])) {
                throw new \LogicException('DNS binding identity is immutable');
            }
        });
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function operations()
    {
        return $this->hasMany(DnsOperation::class, 'binding_id');
    }
}
