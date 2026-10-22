<?php

namespace App\Console\Commands;

use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\Gateways\CollectionDisabledException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Gateways\Wave\Wave;
use RuntimeException;
use Throwable;

class SyncWavePayments extends Command
{
    protected $signature = 'wave:sync-payments {--gateway= : Limit checks to one Wave gateway} {--limit=500 : Maximum attempts checked in this run (1-500)}';

    protected $description = 'Check pending native Wave invoices and record authenticated paid invoices';

    public function handle(): int
    {
        $limit = (string) $this->option('limit');
        $gatewayId = (string) ($this->option('gateway') ?? '');
        if (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 500 || ($gatewayId !== '' && (!ctype_digit($gatewayId) || !Gateway::where('extension', 'Wave')->whereKey($gatewayId)->exists()))) {
            $this->error('Choose a valid Wave gateway and a check limit between 1 and 500.');

            return self::FAILURE;
        }
        $lock = Cache::lock('wave:payment-sync', 900);
        try {
            if (!$lock->get()) {
                $this->line('Wave payment checks are already running.');

                return self::SUCCESS;
            }
            $cursorKey = 'wave:payment-sync:cursor:' . ($gatewayId === '' ? 'all' : $gatewayId);
            $progress = Cache::get($cursorKey, []);
            $cursor = is_array($progress) ? max(0, (int) ($progress['cursor'] ?? 0)) : 0;
            $upperId = is_array($progress) ? max(0, (int) ($progress['upper_id'] ?? 0)) : 0;
            $query = GatewayPaymentAttempt::whereIn('state', ['open', 'initializing'])
                ->whereNotNull('provider_reference')->whereNotNull('provider_payload')
                ->whereHas('gateway', fn ($q) => $q->where('extension', 'Wave')->where('enabled', true))
                ->whereHas('invoice', fn ($q) => $q->where('status', 'pending'))
                ->when($gatewayId !== '', fn ($q) => $q->where('gateway_id', $gatewayId));
            // Freeze the cycle's upper bound so new checkouts cannot postpone revisiting older invoices.
            if ($upperId <= $cursor) {
                $cursor = 0;
                $upperId = (int) (clone $query)->max('id');
            }
            $attempts = (clone $query)->where('id', '>', $cursor)->where('id', '<=', $upperId)->orderBy('id')->limit((int) $limit)->get();
            if ($attempts->isEmpty() && $cursor > 0) {
                $upperId = (int) (clone $query)->max('id');
                $attempts = (clone $query)->where('id', '<=', $upperId)->orderBy('id')->limit((int) $limit)->get();
            }
            $stats = ['checked' => 0, 'paid' => 0, 'pending' => 0, 'held' => 0, 'failed' => 0];
            $deadline = microtime(true) + 240;
            foreach ($attempts as $attempt) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $stats['checked']++;
                try {
                    $gateway = $attempt->gateway;
                    (new Wave($gateway->settings->pluck('value', 'key')->all()))->bindRecord($gateway)->syncPayment($attempt->reference);
                    $stats['paid']++;
                } catch (MigrationHeldException|CollectionDisabledException) {
                    $stats['held']++;
                } catch (RuntimeException $exception) {
                    if ($exception->getMessage() === 'Wave invoice is not fully paid') {
                        $stats['pending']++;
                    } else {
                        $this->recordFailure($stats, $attempt);
                    }
                } catch (Throwable) {
                    $this->recordFailure($stats, $attempt);
                }
                // Advance even after an unpaid/failed check so older invoices cannot starve later ones.
                Cache::forever($cursorKey, ['cursor' => $attempt->id, 'upper_id' => $upperId]);
            }
            $this->line(json_encode($stats, JSON_THROW_ON_ERROR));

            return $stats['failed'] ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->error('Wave payment checks could not finish. Verify gateway configuration and cache availability.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function recordFailure(array &$stats, GatewayPaymentAttempt $attempt): void
    {
        $stats['failed']++;
        Log::warning('Wave invoice status could not be reconciled.', ['gateway_id' => $attempt->gateway_id, 'attempt_id' => $attempt->id]);
    }
}
