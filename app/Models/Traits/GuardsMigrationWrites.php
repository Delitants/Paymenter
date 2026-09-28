<?php

namespace App\Models\Traits;

use App\Services\BillmanagerMigration\MigrationHold;

trait GuardsMigrationWrites
{
    public static function bootGuardsMigrationWrites(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(function ($model) use ($event) {
                MigrationHold::assertAllowed($model, $event);
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
