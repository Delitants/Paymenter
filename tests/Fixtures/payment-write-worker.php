<?php

use App\Livewire\Invoices\Show;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Gateways\PaymentAttempts;
use App\Services\Gateways\PaymentWriteGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Real independent connection for invoice-claim race tests. No external HTTP.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/FeeGateway.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment() !== 'testing' || DB::connection()->getDatabaseName() !== $argv[1] || !str_ends_with($argv[1], '_test') || config('mail.default') !== 'array') {
    throw new RuntimeException('Unsafe test worker identity.');
}
Http::preventStrayRequests();
config(['settings.credits_enabled' => true]);
echo "ready\n";
flush();
try {
    $invoice = Invoice::findOrFail($argv[3]);
    Auth::login($invoice->user);
    if ($argv[2] === 'edit') {
        InvoiceItem::findOrFail($argv[4])->update(['price' => '107.14']);
    } elseif ($argv[2] === 'credit') {
        $beforeCredit = $invoice->user->credits()->where('currency_code', $invoice->currency_code)->sole()->amount;
        $beforeTransactions = $invoice->transactions()->count();
        $component = new Show;
        $component->invoice = $invoice;
        $component->selectedMethod = 'credit';
        $component->processPayment();
        if ((new PaymentWriteGuard)->isFrozen($invoice) &&
            $invoice->user->credits()->where('currency_code', $invoice->currency_code)->sole()->amount === $beforeCredit &&
            $invoice->transactions()->count() === $beforeTransactions) {
            echo "blocked\n";
            exit(0);
        }
    } elseif ($argv[2] === 'gateway') {
        (new PaymentAttempts)->begin(Gateway::findOrFail($argv[4]), $invoice, hash('sha256', 'synthetic merchant'), 'USD');
    } else {
        throw new InvalidArgumentException('Unknown test operation.');
    }
    echo "changed\n";
} catch (RuntimeException $e) {
    if (!str_contains(strtolower($e->getMessage()), 'reconciliation')) {
        throw $e;
    }
    echo "blocked\n";
}
