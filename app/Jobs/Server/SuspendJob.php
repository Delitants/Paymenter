<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Servers\ISPmanager\ISPmanager;

class SuspendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true)
    {
        if ($service->product?->server?->extension === 'ISPmanager') {
            $this->timeout = ISPmanager::LIFECYCLE_JOB_TIMEOUT;
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        MigrationHold::assertAllowed($this->service, 'SuspendJob');
        $data = [];

        try {
            $data = ExtensionHelper::suspendServer($this->service, queued: true);
        } catch (Exception $e) {
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }

        $stale = $data === false && $this->service->product->server?->extension === 'ISPmanager';
        if ($this->sendNotification && !$stale) {
            NotificationHelper::serverSuspendedNotification($this->service->user, $this->service, is_array($data) ? $data : []);
        }
    }
}
