<?php

namespace App\Console\Commands;

use App\Models\Gateway;
use Illuminate\Console\Command;
use Paymenter\Extensions\Gateways\Wave\Wave;

class ReconcileWavePayment extends Command
{
    protected $signature = 'wave:reconcile {gateway} {reference} {--apply}';

    protected $description = 'Verify one destination Wave invoice and apply its payment under collection and migration holds';

    public function handle(): int
    {
        if (!$this->option('apply')) {
            $this->error('Explicit --apply is required to record a verified payment');

            return self::FAILURE;
        }
        $gateway = Gateway::find($this->argument('gateway'));
        if (!$gateway || $gateway->extension !== 'Wave') {
            $this->error('A native Wave gateway is required');

            return self::FAILURE;
        }
        try {
            (new Wave($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway)->syncPayment((string) $this->argument('reference'));
        } catch (\Throwable) {
            $this->error('Payment remains unreconciled or held; inspect merchant and invoice state');

            return self::FAILURE;
        }
        $this->info('Verified destination payment reconciled');

        return self::SUCCESS;
    }
}
