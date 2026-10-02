<?php

namespace App\Services\BillmanagerMigration;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class MigrationHold
{
    public static function isHeld(Model $model, bool $currentRead = false): bool
    {
        foreach (['invoice_id' => Invoice::class, 'service_id' => Service::class, 'ticket_id' => Ticket::class, 'ticket_message_id' => TicketMessage::class] as $key => $class) {
            if ($id = $model->getAttribute($key)) {
                $query = $class::whereKey($id);
                if ($currentRead && DB::transactionLevel() > 0) {
                    $query->lockForUpdate();
                }
                $parent = $query->first();
                if ($parent && self::isHeld($parent, $currentRead)) {
                    return true;
                }
            }
        }

        $query = DB::table('billmanager_holds')->whereNull('released_at')
            ->where(function ($query) use ($model) {
                $query->where(function ($direct) use ($model) {
                    $direct->where('model_type', $model->getMorphClass())->where('model_id', $model->getKey() ?? 0);
                });
                if ($model->getAttribute('user_id')) {
                    $query->orWhere(function ($owner) use ($model) {
                        $owner->where('model_type', (new User)->getMorphClass())->where('model_id', $model->getAttribute('user_id'));
                    });
                }
            });
        if ($currentRead && DB::transactionLevel() > 0) {
            return $query->lockForUpdate()->first() !== null;
        }

        return $query->exists();
    }

    public static function assertAllowed(Model $model, string $operation, bool $currentRead = false): void
    {
        if (self::isHeld($model, $currentRead)) {
            throw new MigrationHeldException('Operation blocked by migration hold: ' . $operation);
        }
    }
}
