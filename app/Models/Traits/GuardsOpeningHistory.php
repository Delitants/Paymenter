<?php

namespace App\Models\Traits;

use App\Models\Builders\OpeningHistoryBuilder;
use App\Services\BillmanagerMigration\Opening\OpeningBatchStore;
use Illuminate\Database\Eloquent\Builder;

trait GuardsOpeningHistory
{
    public function newEloquentBuilder($query)
    {
        return new OpeningHistoryBuilder($query);
    }

    public function delete()
    {
        // Deny before Model::delete can dispatch a deleting event. Existing
        // receipts and batches have no legitimate deletion transition.
        OpeningBatchStore::assertMutation($this, 'delete');

        return parent::delete();
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new \RuntimeException('Opening history is writable only by the live batch store.');
    }

    protected function performInsert(Builder $query)
    {
        OpeningBatchStore::assertMutation($this, 'create', $query);

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        OpeningBatchStore::assertMutation($this, 'update', $query);

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel()
    {
        OpeningBatchStore::assertMutation($this, 'delete');

        return parent::performDeleteOnModel();
    }
}
