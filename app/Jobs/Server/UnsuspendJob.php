<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paymenter\Extensions\Servers\ISPmanager\ISPmanager;

class UnsuspendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service)
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
        MigrationHold::assertAllowed($this->service, 'UnsuspendJob');
        try {
            ExtensionHelper::unsuspendServer($this->service, queued: true);
        } catch (Exception $e) {
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }
    }
}
