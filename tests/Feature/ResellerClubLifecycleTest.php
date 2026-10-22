<?php

namespace Tests\Feature;

use App\Jobs\Server\PaidInvoiceJob;
use App\Models\ExtensionOperation;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\ProcessPaidInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResellerClubLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $product = $this->createProduct();
        $server = Server::create(['name' => 'Synthetic registrar', 'extension' => 'ResellerClub', 'type' => 'server', 'enabled' => true]);
        $product->product->update(['server_id' => $server->id]);
        $product->plan->update(['billing_unit' => 'year']);
        $user = User::factory()->create();
        $service = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'pending', 'price' => '10.00', 'quantity' => 1, 'expires_at' => null]);
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);
        $item = $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => '10.00', 'quantity' => 1, 'description' => 'Synthetic domain']);
        Invoice::withoutEvents(fn () => $invoice->update(['status' => Invoice::STATUS_PAID]));

        return [$service->fresh(), $item->fresh(), $server];
    }

    public function test_native_paid_invoice_defers_registrar_activation_until_provider_confirmation(): void
    {
        Queue::fake();
        [$service, $item] = $this->fixture();
        (new ProcessPaidInvoiceService)->handle($item->invoice);
        $this->assertSame('pending', $service->fresh()->status);
        $this->assertNull($service->fresh()->expires_at);
        $this->assertSame('pending', ExtensionOperation::where('invoice_item_id', $item->id)->where('kind', 'paid-service')->firstOrFail()->status);
        Queue::assertPushed(PaidInvoiceJob::class, fn ($job) => $job->invoiceItemId === $item->id && $job->afterCommit === true);
    }

    public function test_non_registrar_paid_service_retains_native_renewal_behavior(): void
    {
        Queue::fake();
        [$service, $item] = $this->fixture();
        $service->product->update(['server_id' => null]);
        (new ProcessPaidInvoiceService)->handle($item->invoice);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNotNull($service->fresh()->expires_at);
        Queue::assertNothingPushed();
        $this->assertSame(0, ExtensionOperation::count());
    }

    public function test_worker_rejects_an_unpaid_invoice_before_any_provider_call(): void
    {
        [$service, $item] = $this->fixture();
        Invoice::withoutEvents(fn () => $item->invoice->update(['status' => 'pending']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paid invoice');
        (new PaidInvoiceJob($item->id))->handle();
    }

    public function test_worker_rejects_changed_invoice_ownership(): void
    {
        [$service, $item] = $this->fixture();
        Invoice::withoutEvents(fn () => $item->invoice->update(['user_id' => User::factory()->create()->id]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('identity');
        (new PaidInvoiceJob($item->id))->handle();
    }
}
