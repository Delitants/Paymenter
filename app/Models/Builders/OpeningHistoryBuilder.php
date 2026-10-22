<?php

namespace App\Models\Builders;

use App\Services\BillmanagerMigration\Opening\OpeningBatchStore;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** Bulk history mutations cannot bypass the guarded model's store scope. */
final class OpeningHistoryBuilder extends Builder
{
    public function update(array $values)
    {
        OpeningBatchStore::assertBuilderMutation($this, 'update', $values);

        return parent::update($values);
    }

    public function delete()
    {
        return $this->deny();
    }

    public function forceDelete()
    {
        return $this->deny();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        return $this->deny();
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        return $this->deny();
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        return $this->deny();
    }

    public function fillAndInsert(array $values)
    {
        return $this->deny();
    }

    public function fillAndInsertGetId(array $values)
    {
        return $this->deny();
    }

    public function fillAndInsertOrIgnore(array $values)
    {
        return $this->deny();
    }

    public function __call($method, $parameters)
    {
        $name = strtolower($method);
        if ($name === 'insertgetid') {
            OpeningBatchStore::assertBuilderMutation($this, 'create', $parameters[0] ?? []);
        } elseif (in_array($name, ['insert', 'insertorignore', 'insertusing', 'insertorignoreusing', 'updateorinsert', 'truncate', 'incrementeach', 'decrementeach'], true)) {
            return $this->deny();
        }

        return parent::__call($method, $parameters);
    }

    private function deny(): never
    {
        throw new RuntimeException('Opening history is writable only by the live batch store.');
    }
}
