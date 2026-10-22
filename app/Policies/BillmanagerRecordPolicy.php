<?php

namespace App\Policies;

use App\Models\BillmanagerRecord;
use App\Models\User;

class BillmanagerRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return count(BillmanagerRecord::tablesVisibleTo($user)) > 0;
    }

    public function view(User $user, BillmanagerRecord $record): bool
    {
        return in_array($record->source_table, BillmanagerRecord::tablesVisibleTo($user), true);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BillmanagerRecord $record): bool
    {
        return false;
    }

    public function delete(User $user, BillmanagerRecord $record): bool
    {
        return false;
    }
}
