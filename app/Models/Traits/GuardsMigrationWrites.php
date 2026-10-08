<?php

namespace App\Models\Traits;

use App\Models\Credit;
use App\Services\Accounts\AccountWriteGuard;
use App\Services\BillmanagerMigration\MigrationHold;

trait GuardsMigrationWrites
{
    public static function bootGuardsMigrationWrites(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(function ($model) use ($event) {
                if ($model instanceof Credit) {
                    (new AccountWriteGuard)->assertCreditMigrationEvent($model, $event);
                } else {
                    MigrationHold::assertAllowed($model, $event);
                }
                if ($model->exists && $model->isDirty(['user_id', 'invoice_id', 'service_id'])) {
                    // Moving a row to another owner must not discard its original hold.
                    $original = new static;
                    $original->setRawAttributes($model->getRawOriginal());
                    MigrationHold::assertAllowed($original, $event);
                }
            });
        }
    }
}
