<?php

namespace App\Console\Commands;

use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Console\Command;
use Paymenter\Extensions\Servers\ResellerClub\Lifecycle;
use RuntimeException;

class ResellerClubReconcile extends Command
{
    protected $signature = 'resellerclub:reconcile {invoice-item : Native paid invoice item ID}
        {--contact-id= : Provider contact ID to verify after an unknown contact creation result}';

    protected $description = 'Reconcile a paid domain operation using provider GET requests only';

    public function handle(): int
    {
        $contact = $this->option('contact-id');
        if (!ctype_digit((string) $this->argument('invoice-item')) || ($contact !== null && !preg_match('/^[1-9][0-9]*$/D', $contact))) {
            $this->error('Specify valid native invoice and provider contact IDs');

            return self::FAILURE;
        }
        $item = InvoiceItem::find($this->argument('invoice-item'));
        $service = $item?->reference;
        if (!$item || $item->reference_type !== Service::class || !($service instanceof Service) || $service->product?->server?->extension !== 'ResellerClub') {
            $this->error('The paid invoice item must reference a native ResellerClub service');

            return self::FAILURE;
        }
        try {
            $config = $service->product->server->settings()->get()->pluck('value', 'key')->all();
            (new Lifecycle($config, reconcileOnly: true, contactId: $contact))->handle($service, $item);
            $this->info('Existing provider operation reconciled. Native service status: ' . $service->fresh()->status . '. Provider writes: 0.');

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->line('No provider order was submitted. Keep the operation pending until identity and outcome are verified.');

            return self::FAILURE;
        }
    }
}
