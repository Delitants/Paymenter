<?php

namespace App\Console\Commands\Extension;

use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\Gateways\Operations\ProviderOperations;
use Illuminate\Console\Command;

class ReconcilePaymentOperation extends Command
{
    protected $signature = 'payment-operation:reconcile {operation : Existing payment operation ID} {--actor= : Explicit administrator user ID}';

    protected $description = 'Read back an existing provider refund/capture without retrying its money write';

    public function handle(): int
    {
        $actorId = $this->option('actor');
        if (!is_string($actorId) || !ctype_digit($actorId) || !$actor = User::find($actorId)) {
            $this->error('An explicit existing administrator --actor ID is required.');

            return self::FAILURE;
        }
        $operation = PaymentOperation::find($this->argument('operation'));
        if (!$operation || !in_array($operation->kind, ['provider_refund', 'provider_capture'], true)) {
            $this->error('An existing provider refund or capture operation is required.');

            return self::FAILURE;
        }
        try {
            $result = (new ProviderOperations)->reconcile($actor, $operation);
        } catch (\Throwable) {
            $this->error('Readback refused or unverified. Check current administrator permission, gateway enablement, holds and original payment identity.');

            return self::FAILURE;
        }
        $this->info('Operation #' . $result->id . ': ' . $result->state . ' (' . $result->amount . ' ' . $result->currency_code . '). No provider write retried.');

        return in_array($result->state, ['succeeded', 'failed'], true) ? self::SUCCESS : self::FAILURE;
    }
}
