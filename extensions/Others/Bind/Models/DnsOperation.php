<?php

namespace Paymenter\Extensions\Others\Bind\Models;

use Illuminate\Database\Eloquent\Model;

final class DnsOperation extends Model
{
    protected $table = 'ext_bind_operations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $casts = ['response' => 'array', 'serial' => 'integer'];

    protected static function booted(): void
    {
        self::updating(function (self $m) {
            if ($m->isDirty(['id', 'binding_id', 'actor_user_id', 'kind', 'request_hash'])) {
                throw new \LogicException('DNS operation identity is immutable');
            }
        });
    }

    public function binding()
    {
        return $this->belongsTo(ZoneBinding::class, 'binding_id');
    }
}
