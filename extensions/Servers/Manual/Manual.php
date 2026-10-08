<?php

namespace Paymenter\Extensions\Servers\Manual;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\ReadOnlyServer;
use App\Models\Service;
use Illuminate\Support\Facades\Gate;

#[ExtensionMeta(name: 'Manual', description: 'Explicit operator-managed external and dedicated services', version: '1.0.0', author: 'Paymenter Community')]
class Manual extends ReadOnlyServer
{
    public function testConfig(): bool
    {
        return true;
    }

    public function getServerInfo(Service $service): array
    {
        return ['management' => 'manual', 'billing_status' => $service->status];
    }

    public function getActions(Service $service): array
    {
        Gate::authorize('view', $service);

        return [['type' => 'text', 'label' => 'Management', 'text' => 'This service is managed by an operator. Contact support for changes.']];
    }
}
