<?php

namespace App\Console\Commands;

use App\Models\GatewayPaymentAttempt;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CollectionDisabledException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Gateways\BTCPay\BTCPay;
use Throwable;

class SyncBTCPayPayments extends Command
{
    protected $signature = 'btcpay:sync-payments';

    protected $description = 'Reconcile pending BTCPay invoices through the store API';

    public function handle(): int
    {
        $lock = Cache::lock('btcpay:payment-sync', 900);
        if (!$lock->get()) {
            return self::SUCCESS;
        }
        $key = 'btcpay:payment-sync:progress';
        try {
            $query = GatewayPaymentAttempt::query()
                ->where(fn ($query) => $query->where('state', 'initializing')->orWhere(fn ($query) => $query
                    ->where('state', 'open')->whereNotNull('provider_reference')))
                ->whereHas('gateway', fn ($query) => $query->where('extension', 'BTCPay')->where('enabled', true))
                ->whereHas('invoice', fn ($query) => $query->where('status', 'pending'))
                ->with('gateway.settings');
            [$cursor, $upper] = Cache::get($key, [0, 0]);
            if ($cursor >= $upper) {
                $cursor = 0;
                $upper = (int) (clone $query)->max('id');
            }
            $attempts = $query->where('id', '>', $cursor)->where('id', '<=', $upper)->orderBy('id')->limit(100)->get();
            $stats = ['paid' => 0, 'pending' => 0, 'expired' => 0, 'review' => 0, 'held' => 0, 'failed' => 0];
            $deadline = microtime(true) + 210;
            foreach ($attempts as $attempt) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                try {
                    $gateway = $attempt->gateway;
                    $state = (new BTCPay($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway)->syncPayment($attempt->reference);
                    $stats[$state]++;
                } catch (CollectionDisabledException|MigrationHeldException) {
                    $stats['held']++;
                } catch (Throwable) {
                    $stats['failed']++;
                    Log::warning('BTCPay invoice status could not be reconciled.', ['gateway_id' => $attempt->gateway_id, 'attempt_id' => $attempt->id]);
                }
                $cursor = $attempt->id;
            }
            Cache::forever($key, [$attempts->isEmpty() ? $upper : $cursor, $upper]);
            $this->line(json_encode($stats, JSON_THROW_ON_ERROR));

            return $stats['failed'] ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
