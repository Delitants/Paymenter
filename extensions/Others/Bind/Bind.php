<?php

namespace Paymenter\Extensions\Others\Bind;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Helpers\ExtensionHelper;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Others\Bind\Models\ZoneBinding;

#[ExtensionMeta(name: 'BIND', description: 'Native customer DNS through a restricted SSH helper', version: '0.1.0', author: 'Paymenter Community')]
class Bind extends Extension
{
    public function getConfig($values = []): array
    {
        $fields = [['name' => 'authoritative_nameservers', 'label' => 'Authoritative nameservers (one hostname per line)', 'type' => 'textarea'], ['name' => 'operations_enabled', 'label' => 'Enable DNS changes after native verification and activation approval', 'type' => 'checkbox', 'default' => false]];
        foreach (['primary', 'secondary'] as $role) {
            $fields[] = ['name' => $role . '_host', 'label' => ucfirst($role) . ' SSH host', 'type' => 'text', 'required' => true];
            $fields[] = ['name' => $role . '_port', 'label' => ucfirst($role) . ' SSH port', 'type' => 'number', 'default' => 22, 'required' => true];
            $fields[] = ['name' => $role . '_username', 'label' => ucfirst($role) . ' restricted SSH account', 'type' => 'text', 'default' => 'paymenter-dns', 'required' => true];
            $fields[] = ['name' => $role . '_private_key', 'label' => ucfirst($role) . ' dedicated private key', 'type' => 'textarea', 'encrypted' => true, 'required' => true];
            $fields[] = ['name' => $role . '_host_key', 'label' => ucfirst($role) . ' verified public host key', 'type' => 'text', 'required' => true];
        }

        return $fields;
    }

    public function installed()
    {
        ExtensionHelper::runMigrations('extensions/Others/Bind/database/migrations');
    }

    public function uninstalled()
    {
        throw new \RuntimeException('DNS evidence is retained; disable the extension and use a reviewed removal plan');
    }

    public function boot()
    {
        Gate::policy(ZoneBinding::class, Policies\ZoneBindingPolicy::class);
        View::addNamespace('bind', __DIR__ . '/resources/views');
        Lang::addNamespace('bind', __DIR__ . '/resources/lang');
        \Livewire\Livewire::component('bind.zones.index', Livewire\Zones\Index::class);
        \Livewire\Livewire::component('bind.zones.show', Livewire\Zones\Show::class);
        require __DIR__ . '/routes/web.php';
        Event::listen('navigation', function () {
            if (!Auth::check() || !Schema::hasTable('ext_bind_zones')) {
                return null;
            }$user = Auth::user();
            $zones = ZoneBinding::where('enabled', true)->whereNotNull('approved_at')->whereHas('service', fn ($q) => $q->whereIn('user_id', AccountAccess::visibleOwnerIds($user)))->get();
            if (!$zones->contains(fn ($b) => Gate::allows('view', $b))) {
                return null;
            }

            return ['name' => 'DNS', 'route' => 'bind.zones.index', 'icon' => 'ri-global', 'separator' => false, 'children' => []];
        });
        Event::listen('permissions', fn () => ['admin.bind.view' => 'View DNS bindings', 'admin.bind.approve' => 'Approve exact DNS bindings', 'admin.bind.inspect' => 'Inspect held DNS bindings', 'admin.bind.prepare' => 'Prepare approved DNS zones', 'admin.bind.reconcile' => 'Reconcile DNS operation outcomes']);
    }
}
