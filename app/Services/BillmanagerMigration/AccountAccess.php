<?php

namespace App\Services\BillmanagerMigration;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AccountAccess
{
    public static function visibleOwnerIds(User $user, bool $currentRead = false): array
    {
        $query = DB::table('billmanager_members as members')
            ->join('billmanager_accounts as accounts', 'accounts.id', '=', 'members.account_id')
            ->where('members.user_id', $user->id);
        if ($currentRead && DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $owners = $query->pluck('accounts.owner_user_id')->all();

        return array_values(array_unique(array_map('intval', [$user->id, ...$owners])));
    }

    public static function canRead(User $user, Model $record, bool $currentRead = false): bool
    {
        return in_array((int) $record->getAttribute('user_id'), self::visibleOwnerIds($user, $currentRead), true);
    }
}
