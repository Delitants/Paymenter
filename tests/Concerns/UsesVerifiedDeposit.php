<?php

namespace Tests\Concerns;

use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\Gateways\Operations\ManualSettlements;
use App\Services\Gateways\PaymentAttempts;
use App\Services\Gateways\PaymentWriteGuard;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Fixtures\FeeGateway;

trait UsesVerifiedDeposit
{
    protected function depositGateway(string $fixed = '2.00'): Gateway
    {
        class_exists(FeeGateway::class);
        $gateway = Gateway::create(['name' => 'Synthetic deposit gateway', 'extension' => 'FeeGateway', 'type' => 'gateway', 'enabled' => true]);
        foreach (['collection_enabled' => '1', 'customer_fee_enabled' => '1', 'customer_fee_percent' => '0',
            'customer_fee_fixed' => $fixed, 'customer_fee_currency' => 'USD', 'admin_payment_operations_enabled' => '1'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $gateway;
    }

    protected function verifiedDeposit(Invoice $invoice, Gateway $gateway, string $reference, bool $process = true): InvoiceTransaction
    {
        $this->actingAs(User::findOrFail($invoice->user_id));
        $ledger = new PaymentAttempts;
        $attempt = $ledger->begin($gateway, $invoice->fresh(), hash('sha256', 'synthetic deposit merchant'), 'USD');
        $settle = fn () => $ledger->settle($gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, 'USD', $reference);
        $process ? $settle() : Event::fakeFor($settle, ['eloquent.updated: ' . Invoice::class]);
        $transaction = $invoice->transactions()->sole();
        (new PaymentWriteGuard)->updateProcessorFee($transaction->transaction_id, '1.00', $transaction);

        return $transaction->fresh();
    }

    protected function manualDeposit(Invoice $invoice, Gateway $gateway, string $amount, string $reference): void
    {
        $role = Role::firstOrCreate(['name' => 'Synthetic deposit staff'], ['permissions' => ['admin.invoice_transactions.manual_settle']]);
        $actor = User::factory()->createQuietly(['role_id' => $role->id]);
        $this->actingAs($actor);
        (new ManualSettlements)->record($actor, $invoice->fresh(), $gateway, $amount, $reference, 'Synthetic receipt verified',
            now()->utc()->format('Y-m-d\TH:i:s\Z'), (string) Str::uuid());
    }
}
