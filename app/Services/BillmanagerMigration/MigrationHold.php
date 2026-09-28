<?php

namespace App\Services\BillmanagerMigration;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class MigrationHold
{
    public static function isHeld(Model $model): bool
    {
        return DB::table('billmanager_holds')->whereNull('released_at')
            ->where(function ($query) use ($model) {
                $query->where(function ($direct) use ($model) {
                    $direct->where('model_type', $model->getMorphClass())->where('model_id', $model->getKey() ?? 0);
                });
                if ($model->getAttribute('user_id')) {
                    $query->orWhere(function ($owner) use ($model) {
                        $owner->where('model_type', (new User)->getMorphClass())->where('model_id', $model->getAttribute('user_id'));
                    });
                }
            })->exists();
    }

    public static function assertAllowed(Model $model, string $operation): void
    {
        if (self::isHeld($model)) {
            throw new MigrationHeldException('Operation blocked by migration hold: ' . $operation);
        }
    }
}
