<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Services\Accounts\NativeDowngradeReceipt;
use App\Services\BillmanagerMigration\MigrationHold;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpgradeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true, public ?int $upgradeId = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->upgradeId !== null) {
            NativeDowngradeReceipt::fulfill($this->upgradeId);

            return;
        }
        MigrationHold::assertAllowed($this->service, 'UpgradeJob');
        // $data is the data that will be used to send the email, data is coming from the extension itself
        try {
            ExtensionHelper::upgradeServer($this->service);
        } catch (Exception $e) {
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }
    }
}
