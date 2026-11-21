<?php

namespace App\Models\Traits;

use App\Models\Builders\LegacyInstanceWriteBuilder;
use App\Services\Accounts\Legacy\LegacyReceiptWriteGuard;
use Illuminate\Database\Eloquent\Builder;

trait GuardsLegacyReceipts
{
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        LegacyReceiptWriteGuard::deny();
    }

    protected function performInsert(Builder $query)
    {
        LegacyReceiptWriteGuard::assertRecordWrite($this, 'create');
        // The public evidence builder denies bulk insertion. Only this protected,
        // already admitted model path uses the ordinary internal insert builder.
        $inserted = parent::performInsert((new LegacyInstanceWriteBuilder($query->getQuery()))->setModel($this));
        if ($inserted) {
            LegacyReceiptWriteGuard::recordWritten($this, 'create');
        }

        return $inserted;
    }

    protected function performUpdate(Builder $query)
    {
        LegacyReceiptWriteGuard::assertRecordWrite($this, 'update');
        $updated = parent::performUpdate((new LegacyInstanceWriteBuilder($query->getQuery()))->setModel($this));
        if ($updated) {
            LegacyReceiptWriteGuard::recordWritten($this, 'update');
        }

        return $updated;
    }

    protected function performDeleteOnModel()
    {
        LegacyReceiptWriteGuard::deny();
    }
}
