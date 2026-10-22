<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\AccountWallet;
use App\Models\CronStat;
use App\Models\DebugLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Accounts\AccountPaymentLocks;
use App\Services\Accounts\InvoiceFunding;
use App\Services\Accounts\WalletLedger;
use App\Services\Billing\InvoicePricing;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\InvoicePaymentDependencies;
use App\Services\Gateways\PaymentWriteGuard;
use App\Services\Service\PaidServiceLifecycle;
use App\Services\Service\RenewServiceService;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class CronJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cron-job';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run automated tasks';

    private int $successFullCharges = 0;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Config::set('audit.console', true);

        $cronTransactionStarted = false;

        try {
            // Send invoices if due date is x days away
            $this->runCronJob('invoices_created', function ($number = 0) {
                Service::where('status', 'active')->where('expires_at', '<', now()->addDays((int) config('settings.cronjob_invoice', 7)))->get()->each(function ($service) use (&$number) {
                    $invoice = DB::transaction(function () use ($service, &$number) {
                        if (!$this->lockPendingInvoices($service)) {
                            return;
                        }
                        $service = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
                        if (MigrationHold::isHeld($service) || PaidServiceLifecycle::pending($service) ||
                            $service->status !== Service::STATUS_ACTIVE || $service->expires_at >= now()->addDays((int) config('settings.cronjob_invoice', 7))) {
                            return;
                        }
                        // Does the service have already a pending invoice?
                        if ($service->invoices()->where('status', 'pending')->exists() || $service->cancellation()->exists()) {
                            return;
                        }

                        // Opt-in local pricing preparation; never perform provider writes here.
                        if ($service->product->server && ExtensionHelper::hasFunction($service->product->server, 'prepareRenewalInvoice')) {
                            try {
                                ExtensionHelper::call($service->product->server, 'prepareRenewalInvoice', [$service]);
                            } catch (Exception $e) {
                                report($e);

                                return;
                            }
                        }

                        // Calculate if we should edit the price because of the coupon
                        if ($service->coupon) {
                            // Calculate what iteration of the coupon we are in
                            $paidCycles = $service->invoices()->where('status', Invoice::STATUS_PAID)->count();
                            if ((int) $service->coupon->recurring > 1 && $paidCycles === (int) $service->coupon->recurring) {
                                // Calculate the price
                                $service->price = $service->calculatePrice();
                                $service->save();
                            }
                        }

                        // If service price is 0, immediately activate next period
                        if ($service->price <= 0) {
                            (new RenewServiceService)->handle($service);
                            $number++;

                            return;
                        }

                        // Create invoice
                        $invoice = $service->invoices()->make([
                            'user_id' => $service->user_id,
                            'status' => 'pending',
                            'due_at' => $service->expires_at,
                            'currency_code' => $service->currency_code,
                        ]);

                        $invoice->save();
                        // Create invoice items
                        $invoice->items()->create([
                            'reference_id' => $service->id,
                            'reference_type' => Service::class,
                            'price' => $service->price,
                            'quantity' => $service->quantity,
                            'description' => $service->description,
                        ]);

                        $number++;

                        return $invoice->refresh();
                    }, 3);
                    if (!$invoice) {
                        return;
                    }
                    $this->payInvoiceWithCredits($invoice);

                    // Charge billing agreements
                    if ($service->billing_agreement_id && $invoice->fresh()->status === 'pending') {
                        DB::afterCommit(function () use ($invoice, $service) {
                            try {
                                ExtensionHelper::charge(
                                    $service->billingAgreement->gateway,
                                    $invoice,
                                    $service->billingAgreement
                                );

                                $this->successFullCharges++;
                            } catch (Exception $e) {
                                // Ignore errors here
                                NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice);
                            }
                        });
                    }

                });

                return $number;
            });

            DB::beginTransaction();
            $cronTransactionStarted = true;

            $this->runCronJob('orders_cancelled', function ($number = 0) {
                // Cancel services if first invoice is not paid after x days
                Service::where('status', 'pending')->whereDoesntHave('invoices', function ($query) {
                    $query->where('status', 'paid');
                })->where('created_at', '<', now()->subDays((int) config('settings.cronjob_order_cancel', 7)))->get()->each(function ($service) use (&$number) {
                    if (MigrationHold::isHeld($service) || !$this->lockPendingInvoices($service)) {
                        return;
                    }
                    $service->invoices()->where('status', 'pending')->get()->each->update(['status' => 'cancelled']);

                    $service->update(['status' => 'cancelled']);

                    if ($service->product->stock !== null) {
                        $service->product->increment('stock', $service->quantity);
                    }

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('upgrade_invoices_updated', function ($number = 0) {
                // Update pending upgrade invoices
                ServiceUpgrade::where('status', 'pending')->get()->each(function ($upgrade) use (&$number) {
                    if (!$this->lockPendingInvoices($upgrade->service)) {
                        return;
                    }
                    if ($upgrade->invoice) {
                        $invoice = Invoice::whereKey($upgrade->invoice_id)->lockForUpdate()->firstOrFail();
                        if ((new PaymentWriteGuard)->isFrozen($invoice)) {
                            return;
                        }
                    }
                    if (MigrationHold::isHeld($upgrade->service)) {
                        return;
                    }
                    if ($upgrade->service->expires_at < now()) {
                        $upgrade->update(['status' => 'cancelled']);
                        // Somehow people manage to have an upgrade without an invoice
                        if ($upgrade->invoice) {
                            $upgrade->invoice->update(['status' => 'cancelled']);
                        }

                        $number++;

                        return;
                    }
                    if (!$upgrade->invoice) {
                        return;
                    }

                    $upgrade->invoice->items()->get()->each->update([
                        'price' => $upgrade->calculatePrice()->price,
                    ]);

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('services_suspended', function ($number = 0) {
                // Suspend orders if due date is overdue for x days
                Service::where('status', 'active')->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_suspend', 2)))->get()->each(function ($service) use (&$number) {
                    if (!$this->lockPendingInvoices($service)) {
                        return;
                    }
                    $service = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
                    if (MigrationHold::isHeld($service) || PaidServiceLifecycle::pending($service) ||
                        $service->status !== Service::STATUS_ACTIVE || $service->expires_at >= now()->subDays((int) config('settings.cronjob_order_suspend', 2))) {
                        return;
                    }
                    SuspendJob::dispatch($service);

                    $service->update(['status' => 'suspended']);
                    $number++;
                });

                return $number;
            });

            $this->runCronJob('services_terminated', function ($number = 0) {
                // Terminate orders if due date is overdue for x days
                Service::where('status', 'suspended')->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_terminate', 14)))->each(function ($service) use (&$number) {
                    if (!$this->lockPendingInvoices($service)) {
                        return;
                    }
                    $service = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
                    if (MigrationHold::isHeld($service) || PaidServiceLifecycle::pending($service) ||
                        $service->status !== Service::STATUS_SUSPENDED || $service->expires_at >= now()->subDays((int) config('settings.cronjob_order_terminate', 14))) {
                        return;
                    }
                    TerminateJob::dispatch($service);

                    $service->update(['status' => 'cancelled']);
                    // Cancel outstanding invoices
                    $service->invoices()->where('status', 'pending')->get()->each->update(['status' => 'cancelled']);

                    if ($service->product->stock !== null) {
                        $service->product->increment('stock', $service->quantity);
                    }

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('tickets_closed', function ($number = 0) {
                // Close tickets if no response for x days
                Ticket::where('status', 'replied')->each(function ($ticket) use (&$number) {
                    if (MigrationHold::isHeld($ticket)) {
                        return;
                    }
                    $lastMessage = $ticket->messages()->latest('created_at')->first();
                    if ($lastMessage && $lastMessage->created_at < now()->subDays((int) config('settings.cronjob_close_ticket', 7))) {
                        $ticket->update(['status' => 'closed']);
                        $number++;
                    }
                });

                return $number;
            });

            $this->runCronJob('email_logs_deleted', function ($number = 0) {
                $number = Notification::where('created_at', '<', now()->subDays((int) config('settings.cronjob_delete_email_logs', 90)))->count();
                // Delete email logs older then x
                Notification::where('created_at', '<', now()->subDays((int) config('settings.cronjob_delete_email_logs', 90)))->delete();

                return $number;
            });

        } catch (Exception $e) {
            if ($cronTransactionStarted) {
                DB::rollBack();
            }

            NotificationHelper::sendSystemEmailNotification('Cron Job Error', <<<HTML
                An error occurred while running the cron job:<br>
                <pre>{$e->getMessage()}.</pre><br>
                Please check the system and application logs for more details.
                HTML);

            throw $e;
        }

        DB::commit();

        Setting::updateOrCreate(
            ['key' => 'last_cron_run', 'settingable_type' => CronStat::class],
            ['value' => now()->toDateTimeString(), 'type' => 'string']
        );

        CronStat::create([
            'key' => 'invoice_charged',
            'value' => $this->successFullCharges,
            'date' => now()->toDateString(),
        ]);

        $this->info('Successfully charged ' . $this->successFullCharges . ' invoices.');

        // Remove old debug logs
        DebugLog::where('created_at', '<', now()->subDays(30))->delete();

        // Check for updates
        $this->info('Checking for updates...');

        $this->call(CheckForUpdates::class);
    }

    private function payInvoiceWithCredits(Invoice $invoice): void
    {
        if (!config('settings.credits_auto_use', true)) {
            return;
        }
        DB::transaction(function () use ($invoice) {
            (new AccountPaymentLocks)->during([$invoice->id], function ($locked) use ($invoice) {
                $invoice = $locked->firstWhere('id', $invoice->id);
                if ((new PaymentWriteGuard)->isFrozen($invoice) || $invoice->status !== 'pending') {
                    return;
                }
                (new InvoicePaymentDependencies)->assertCollectable($invoice, $locked);
                $owner = User::whereKey($invoice->user_id)->lockForUpdate()->firstOrFail();
                if (AccountWallet::where('user_id', $owner->id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first()) {
                    $quote = (new WalletLedger)->quote($owner, $invoice->currency_code);
                    if (!$quote || $quote->blocked) {
                        return;
                    }
                    (new InvoiceFunding)->fundAutomatic($invoice, 'renewal-funding:' . $invoice->id . ':' . (new InvoicePricing)->fingerprint($invoice, false));

                    return;
                }
                $credits = $owner->credits()->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
                $remaining = BigDecimal::of((new InvoicePricing)->summary($invoice)->payable);
                if ($remaining->isPositive() && $credits && BigDecimal::of((string) $credits->amount)->isGreaterThanOrEqualTo($remaining)) {
                    $credits->update(['amount' => (string) BigDecimal::of((string) $credits->amount)->minus($remaining)->toScale(2)]);
                    ExtensionHelper::addPayment($invoice, null, (string) $remaining, isCreditTransaction: true);
                }
            });
        });
    }

    private function lockPendingInvoices(Service $service): bool
    {
        // Match settlement's invoice-before-service order before any lifecycle job.
        $invoices = (new InvoicePaymentDependencies)->lockService($service->id);
        foreach ($invoices as $invoice) {
            if ($invoice->status === 'pending' && (new PaymentWriteGuard)->isFrozen($invoice)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Function to run a specific cron job by its key.
     */
    private function runCronJob(string $key, callable $callback): void
    {
        $items = $callback() ?? 0;

        CronStat::create([
            'key' => $key,
            'value' => $items,
            'date' => now()->toDateString(),
        ]);

        $this->info("Cronjob task '" . __('admin.cronjob.' . $key) . "' completed: Processed " . $items . ' items.');
    }
}
