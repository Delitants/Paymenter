<?php

namespace Paymenter\Extensions\Others\Bind;

use App\Models\Extension;
use App\Models\Service;
use App\Models\User;
use App\Services\BillmanagerMigration\AccountAccess;
use App\Services\BillmanagerMigration\MigrationHold;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Others\Bind\Models\ZoneBinding;

final class BindAccess
{
    private static function deny(): never
    {
        throw new AuthorizationException('DNS access is unavailable for this service');
    }

    public static function assertAllowed(User $user, ZoneBinding $binding, string $operation): void
    {
        if (!in_array($operation, ['inspect', 'replace_records', 'inspect_request', 'prepare_zone', 'prepare_secondary'], true)) {
            self::deny();
        }
        foreach (['billmanager_holds', 'billmanager_members', 'billmanager_accounts', 'ext_bind_zones', 'ext_bind_operations'] as $table) {
            if (!Schema::hasTable($table)) {
                self::deny();
            }
        }
        $actor = User::query()->find($user->id);
        $record = ZoneBinding::with('service')->find($binding->id);
        $ext = Extension::where('type', 'other')->where('extension', 'Bind')->first();
        if (!$actor || !$record || !$record->service || !$record->approved_at || !$ext || !$ext->enabled || MigrationHold::isHeld($actor)) {
            self::deny();
        }
        $adminContext = request()->is('admin/*') || request()->routeIs('paymenter.livewire.update');
        $read = in_array($operation, ['inspect', 'inspect_request'], true);
        $permission = $operation === 'inspect_request' ? 'admin.bind.reconcile' : 'admin.bind.inspect';
        if ($read && $adminContext && $actor->hasPermission('admin.bind.view') && $actor->hasPermission($permission)) {
            return;
        }
        $service = $record->service;
        if (!$record->enabled || MigrationHold::isHeld($service) || ($service->user && MigrationHold::isHeld($service->user))) {
            self::deny();
        }
        if (in_array($operation, ['prepare_zone', 'prepare_secondary'], true)) {
            if (!$adminContext || !$actor->hasPermission('admin.bind.prepare') || !in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_PENDING], true)) {
                self::deny();
            }
        } else {
            if ($service->status !== Service::STATUS_ACTIVE || !AccountAccess::canRead($actor, $service)) {
                self::deny();
            }
            if (!$read && !Gate::forUser($actor)->allows('update', $service)) {
                self::deny();
            }
        }
        if (!$read) {
            $value = $ext->settings()->where('key', 'operations_enabled')->first()?->value;
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
                self::deny();
            }
        }
    }
}
