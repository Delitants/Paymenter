<?php

namespace App\Models;

use App\Events\Setting\Retrieved;
use App\Events\Setting\Saved;
use App\Events\Setting\Saving;
use App\Redactors\RightRedactor;
use App\Redactors\SecretRedactor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class Setting extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'encrypted',
        'settingable_id',
        'settingable_type',
    ];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    protected $dispatchesEvents = [
        'retrieved' => Retrieved::class,
        'saving' => Saving::class,
        'saved' => Saved::class,
    ];

    public function getAttributeModifiers(): array
    {
        if (!$this->encrypted) {
            return [];
        }

        return [
            'value' => in_array($this->key, ['wm_certificate', 'wm_private_key', 'wm_key_passphrase'], true) ? SecretRedactor::class : RightRedactor::class,
        ];
    }
}
