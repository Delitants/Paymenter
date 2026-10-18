<?php

namespace App\Models\Builders;

use App\Services\Accounts\Legacy\LegacyReceiptWriteGuard;
use Illuminate\Database\Eloquent\Builder;

final class LegacyEvidenceBuilder extends Builder
{
    public function update(array $values)
    {
        LegacyReceiptWriteGuard::deny();
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        LegacyReceiptWriteGuard::deny();
    }

    public function delete()
    {
        LegacyReceiptWriteGuard::deny();
    }

    public function forceDelete()
    {
        LegacyReceiptWriteGuard::deny();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        LegacyReceiptWriteGuard::deny();
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        LegacyReceiptWriteGuard::deny();
    }

    public function __call($method, $parameters)
    {
        if (in_array(strtolower($method), ['insert', 'insertgetid', 'insertorignore', 'insertusing', 'insertorignoreusing', 'updateorinsert', 'updatefrom', 'incrementeach', 'decrementeach', 'truncate'], true)) {
            LegacyReceiptWriteGuard::deny();
        }

        return parent::__call($method, $parameters);
    }
}
