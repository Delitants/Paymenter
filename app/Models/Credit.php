<?php

namespace App\Models;

use App\Classes\Price;
use App\Services\Accounts\AccountWriteGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

class Credit extends Model implements Auditable
{
    use Traits\Auditable, Traits\GuardsMigrationWrites;

    protected $casts = ['amount' => 'decimal:2'];

    protected $fillable = [
        'currency_code',
        'amount',
        'user_id',
    ];

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if (!$this->exists) {
            throw new RuntimeException('Cash arithmetic requires a persisted owner identity.');
        }

        return (new AccountWriteGuard)->withCreditWrite($this, 'arithmetic', fn () => parent::incrementOrDecrement($column, $amount, $extra, $method));
    }

    protected function performInsert(Builder $query)
    {
        return (new AccountWriteGuard)->withCreditWrite($this, 'create', fn () => parent::performInsert($query));
    }

    protected function performUpdate(Builder $query)
    {
        return (new AccountWriteGuard)->withCreditWrite($this, 'update', fn () => parent::performUpdate($query));
    }

    protected function performDeleteOnModel()
    {
        return (new AccountWriteGuard)->withCreditWrite($this, 'delete', fn () => parent::performDeleteOnModel());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->amount, 'currency' => $this->currency])
        );
    }
}
