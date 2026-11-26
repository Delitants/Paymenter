<?php

namespace App\Models;

use App\Models\Builders\LegacyEvidenceBuilder;
use App\Models\Builders\LegacyInstanceWriteBuilder;
use App\Services\Accounts\Legacy\LegacyPostingService;
use App\Services\Accounts\Legacy\LegacyReceiptWriteGuard;
use Illuminate\Database\Eloquent\Builder;

final class LegacyPostingResult extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['parent_cap_json'];

    protected $casts = ['net_delta' => 'decimal:4'];

    public function newEloquentBuilder($query)
    {
        return new LegacyEvidenceBuilder($query);
    }

    protected function performInsert(Builder $query)
    {
        LegacyPostingService::assertResultWrite($this);

        return parent::performInsert((new LegacyInstanceWriteBuilder($query->getQuery()))->setModel($this));
    }

    protected function performUpdate(Builder $query)
    {
        LegacyReceiptWriteGuard::deny();
    }

    protected function performDeleteOnModel()
    {
        LegacyReceiptWriteGuard::deny();
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        LegacyReceiptWriteGuard::deny();
    }
}
