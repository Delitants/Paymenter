<?php

namespace App\Classes\Extension;

use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;
use RuntimeException;

/** Honest lifecycle boundary while a provider's write integration is unverified. */
abstract class ReadOnlyServer extends Server
{
    protected function unavailable(Service $service, string $operation): never
    {
        MigrationHold::assertAllowed($service, $operation);
        throw new RuntimeException(class_basename(static::class) . ' requires an operator for ' . $operation . '; automated lifecycle operations are unavailable');
    }

    public function createServer(Service $service, $settings = [], $properties = []): never
    {
        $this->unavailable($service, 'create');
    }

    public function suspendServer(Service $service, $settings = [], $properties = []): never
    {
        $this->unavailable($service, 'suspend');
    }

    public function unsuspendServer(Service $service, $settings = [], $properties = []): never
    {
        $this->unavailable($service, 'unsuspend');
    }

    public function terminateServer(Service $service, $settings = [], $properties = []): never
    {
        $this->unavailable($service, 'terminate');
    }

    public function upgradeServer(Service $service, $settings = [], $properties = []): never
    {
        $this->unavailable($service, 'upgrade');
    }
}
