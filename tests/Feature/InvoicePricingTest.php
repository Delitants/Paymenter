<?php

namespace Tests\Feature;

use App\Classes\Settings;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice\Paid;
use App\Listeners\CreateInvoiceSnapshotListener;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\InvoicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use RuntimeException;
use Tests\TestCase;

class InvoicePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Mail::fake();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.tax_scope' => 'all']);
        Once::flush();
    }

    private function fixture(string $rate = '7.1250'): Invoice
    {
        TaxRate::create(['name' => 'Synthetic sales tax', 'rate' => $rate, 'country' => 'all']);
        $user = User::factory()->create();
        $user->properties()->create(['key' => 'country', 'value' => 'US']);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '107.13', 'quantity' => 1]);

        return $invoice->fresh();
    }

    public function test_issued_tax_survives_country_and_settings_changes(): void
    {
        $invoice = $this->fixture();
        $this->assertSame('7.1250', $invoice->pricing_tax_rate);
        TaxRate::where('country', 'all')->update(['rate' => '19.5000']);
        $invoice->user->properties()->where('key', 'country')->update(['value' => 'GB']);
        config(['settings.tax_enabled' => false, 'settings.tax_type' => 'inclusive']);
        Once::flush();
        $summary = (new InvoicePricing)->summary($invoice->fresh());
        $this->assertSame('100.00', $summary->productNet);
        $this->assertSame('7.13', $summary->productTax);
        $this->assertSame('107.13', $summary->total);
        (new CreateInvoiceSnapshotListener)->handle(new Paid($invoice->fresh()));
        $this->assertSame('7.1250', $invoice->fresh()->snapshot->tax_rate);
        $this->assertSame('$7.13', $invoice->fresh()->formatted_total->formatted->tax);
    }

    public function test_zero_tax_is_frozen(): void
    {
        config(['settings.tax_enabled' => false]);
        $invoice = $this->fixture('0.0000');
        $this->assertSame('0.0000', $invoice->pricing_tax_rate);
        TaxRate::where('country', 'all')->update(['rate' => '19.5000']);
        config(['settings.tax_enabled' => true]);
        $this->assertSame('0.00', (new InvoicePricing)->summary($invoice->fresh())->productTax);
        (new CreateInvoiceSnapshotListener)->handle(new Paid($invoice->fresh()));
        $this->assertSame('0.0000', $invoice->fresh()->snapshot->tax_rate);
    }

    public function test_fee_is_excluded_from_invoice_tax(): void
    {
        $invoice = $this->fixture();
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'kind' => 'gateway_fee', 'price' => '2.75', 'quantity' => 1]);
        $s = (new InvoicePricing)->summary($invoice->fresh());
        $this->assertSame('100.00', $s->productNet);
        $this->assertSame('7.13', $s->productTax);
        $this->assertSame('2.75', $s->gatewayFee);
        $this->assertSame('109.88', $s->total);
        $this->assertSame('7.13', $invoice->fresh()->formatted_total->total_tax);
        $this->assertSame('0.00', $invoice->items()->where('kind', 'gateway_fee')->sole()->tax_amount);
        $this->assertSame('0.00', $invoice->items()->where('kind', 'gateway_fee')->sole()->formatted_total->total_tax);
    }

    public function test_explicit_tax_retains_unit_rounding_and_updates_with_price(): void
    {
        $invoice = $this->fixture();
        $invoice->items()->delete();
        $item = $invoice->items()->create(['description' => 'Tiny units', 'price' => '0.11', 'quantity' => 3, 'tax_amount' => '0.03']);
        $this->assertSame('0.03', (new InvoicePricing)->summary($invoice->fresh())->productTax);
        $this->assertSame('0.30', (new InvoicePricing)->summary($invoice->fresh())->productNet);
        $item->update(['price' => '107.13', 'quantity' => 1]);
        $this->assertSame('7.13', $item->fresh()->tax_amount);
    }

    public function test_paid_invoice_keeps_total_without_negative_remaining_allocation(): void
    {
        $invoice = $this->fixture();
        $invoice->items()->create(['description' => 'Fee', 'kind' => 'gateway_fee', 'price' => '2.75', 'quantity' => 1]);
        $invoice->transactions()->create(['amount' => '109.88', 'status' => InvoiceTransactionStatus::Succeeded]);
        $s = (new InvoicePricing)->summary($invoice->fresh());
        $this->assertSame('109.88', $s->total);
        $this->assertSame('0.00', $s->payable);
        $this->assertSame('0.00', $s->unpaidNet);
        $this->assertSame('0.00', $s->unpaidTax);
    }

    public function test_partial_credit_is_allocated_to_products_before_fee(): void
    {
        $invoice = $this->fixture();
        $invoice->transactions()->create(['amount' => '57.13', 'status' => InvoiceTransactionStatus::Succeeded]);
        $s = (new InvoicePricing)->summary($invoice->fresh());
        $this->assertSame('50.00', $s->payable);
        $this->assertSame('46.67', $s->unpaidNet);
        $this->assertSame('3.33', $s->unpaidTax);
    }

    public function test_historical_snapshot_precedes_issued_context_and_current_settings(): void
    {
        $invoice = $this->fixture();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid', 'pricing_tax_rate' => null]);
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['price' => '107.12', 'tax_amount' => null]);
        $invoice->snapshot()->create(['tax_name' => 'Historical rate', 'tax_rate' => '7.12', 'tax_country' => 'all']);
        TaxRate::where('country', 'all')->update(['rate' => '19.5000']);
        $this->assertSame('7.1200', $invoice->fresh()->snapshot->tax_rate);
        $this->assertSame('7.12', (new InvoicePricing)->summary($invoice->fresh())->productTax);
        $this->assertSame('107.12', (new InvoicePricing)->summary($invoice->fresh())->total);
    }

    public function test_all_country_policy_ignores_country_override_and_country_mode_remains_available(): void
    {
        $invoice = $this->fixture();
        TaxRate::create(['name' => 'Country tax', 'rate' => '19.5000', 'country' => 'US']);
        $this->assertSame('7.1250', Settings::tax($invoice->user)->rate);
        config(['settings.tax_scope' => 'country']);
        Once::flush();
        $this->assertSame('19.5000', Settings::tax($invoice->user)->rate);
        $other = User::factory()->create();
        $other->properties()->create(['key' => 'country', 'value' => 'GB']);
        $this->assertSame('7.1250', Settings::tax($other)->rate);
    }

    public function test_untouched_historical_rows_retain_aggregate_tax_rounding(): void
    {
        $invoice = $this->fixture();
        $invoice->items()->delete();
        $invoice->items()->create(['description' => 'Historical quantity', 'price' => '0.10', 'quantity' => 3]);
        $invoice->items()->create(['description' => 'Historical single', 'price' => '0.10', 'quantity' => 1]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid', 'pricing_tax_rate' => null, 'pricing_tax_name' => null, 'pricing_tax_country' => null, 'pricing_tax_inclusive' => null]);
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['tax_amount' => null]);
        $invoice->snapshot()->create(['tax_name' => 'Historical rate', 'tax_rate' => '7.12', 'tax_country' => 'all']);
        $summary = (new InvoicePricing)->summary($invoice->fresh());
        $this->assertSame('0.40', $summary->total);
        $this->assertSame('0.03', $summary->productTax);
        $this->assertSame('0.37', $summary->productNet);
        $this->assertSame('$0.03', $invoice->fresh()->formatted_total->formatted->tax);
        $this->assertSame(2, $invoice->items()->whereNull('tax_amount')->count());
    }

    public function test_fingerprint_tracks_finances_but_not_descriptions(): void
    {
        $invoice = $this->fixture();
        $pricing = new InvoicePricing;
        $before = $pricing->fingerprint($invoice);
        $invoice->items()->first()->update(['description' => 'Renamed product']);
        $this->assertSame($before, $pricing->fingerprint($invoice->fresh()));
        $invoice->items()->first()->update(['price' => '107.14']);
        $this->assertNotSame($before, $pricing->fingerprint($invoice->fresh()));
    }

    public function test_payment_snapshot_does_not_change_the_pricing_fingerprint(): void
    {
        $invoice = $this->fixture();
        $pricing = new InvoicePricing;
        $before = $pricing->fingerprint($invoice);
        $invoice->transactions()->create(['amount' => '107.13', 'status' => InvoiceTransactionStatus::Succeeded]);
        $this->assertSame($before, $pricing->fingerprint($invoice->fresh()));
    }

    public function test_native_line_default_quantity_is_preserved(): void
    {
        $invoice = $this->fixture();
        $item = $invoice->items()->create(['description' => 'Default quantity', 'price' => '107.13']);
        $this->assertSame(1, $item->fresh()->quantity);
        $this->assertSame('7.13', $item->fresh()->tax_amount);
    }

    public function test_precision_migration_preserves_history_and_refuses_lossy_rollback(): void
    {
        $invoice = $this->fixture();
        $this->assertSame('7.1250', TaxRate::where('country', 'all')->sole()->rate);
        $invoice->snapshot()->create(['tax_rate' => '7.1250']);
        $migration = require database_path('migrations/2026_09_30_230000_add_invoice_pricing_context.php');
        try {
            $migration->down();
            $this->fail('Precision-losing rollback accepted');
        } catch (RuntimeException) {
            $this->assertSame('7.1250', $invoice->fresh()->snapshot->tax_rate);
            $this->assertTrue(Schema::hasColumn('invoices', 'pricing_tax_rate'));
        }
    }
}
