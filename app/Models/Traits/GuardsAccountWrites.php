<?php

namespace App\Models\Traits;

use App\Services\Accounts\AccountWriteGuard;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

trait GuardsAccountWrites
{
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new RuntimeException('Account arithmetic requires an exact verified receipt transition.');
    }

    protected function performInsert(Builder $query)
    {
        (new AccountWriteGuard)->assertRecordWrite($this, 'create');

        $inserted = parent::performInsert($query);
        if ($inserted) {
            (new AccountWriteGuard)->recordCreated($this);
        }

        return $inserted;
    }

    protected function performUpdate(Builder $query)
    {
        (new AccountWriteGuard)->assertRecordWrite($this, 'update');

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel()
    {
        (new AccountWriteGuard)->assertRecordWrite($this, 'delete');

        return parent::performDeleteOnModel();
    }
}
