<?php

namespace App\Policies;

use App\Models\BillmanagerFinancialRecord;
use App\Models\User;
use App\Services\BillmanagerMigration\AccountAccess;

class BillmanagerFinancialRecordPolicy
{
    public function view(User $user, BillmanagerFinancialRecord $record): bool
    {
        return $record->status !== 'preliminary' && AccountAccess::canRead($user, $record);
    }
}
