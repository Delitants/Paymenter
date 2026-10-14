<?php

namespace Tests\Feature;

use App\Models\TaxRate;
use App\Services\Billing\CatalogPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Once;
use Tests\TestCase;

class PlanTaxBasisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.tax_scope' => 'all']);
        TaxRate::create(['name' => 'Synthetic tax', 'rate' => '4.7120', 'country' => 'all']);
        Once::flush();
    }

    public function test_inclusive_plan_keeps_exact_gross_under_exclusive_catalog_policy(): void
    {
        $fixture = $this->createProduct();
        $fixture->plan->prices()->first()->update(['price' => '3.00', 'setup_fee' => '1.00']);
        $fixture->plan->setAttribute('price_includes_tax', true);
        $price = (new CatalogPricing)->quote($fixture->product, $fixture->plan, [], [], 'USD');
        $this->assertSame('3.00', $price->price_decimal);
        $this->assertSame('1.00', $price->setup_fee_decimal);
        $this->assertSame('0.17', $price->total_tax);
    }

    public function test_default_plan_still_uses_global_exclusive_policy(): void
    {
        $fixture = $this->createProduct();
        $fixture->plan->prices()->first()->update(['price' => '100.00']);
        $price = (new CatalogPricing)->quote($fixture->product, $fixture->plan, [], [], 'USD');
        $this->assertSame('104.71', $price->price_decimal);
        $this->assertSame('4.71', $price->total_tax);
    }

    public function test_captured_tax_rate_does_not_reinterpret_inclusive_stored_price(): void
    {
        $fixture = $this->createProduct();
        $fixture->plan->prices()->first()->update(['price' => '100.00']);
        $fixture->plan->setAttribute('price_includes_tax', true);
        $price = (new CatalogPricing)->quote($fixture->product, $fixture->plan, [], [], 'USD', taxContext: ['rate' => '20.0000', 'inclusive' => false]);
        $this->assertSame('100.00', $price->price_decimal);
        $this->assertSame('16.67', $price->total_tax);
    }

    public function test_inclusive_plan_does_not_add_tax_when_tax_is_disabled(): void
    {
        $fixture = $this->createProduct();
        $fixture->plan->setAttribute('price_includes_tax', true);
        config(['settings.tax_enabled' => false]);
        $price = (new CatalogPricing)->quote($fixture->product, $fixture->plan, [], [], 'USD');
        $this->assertSame('10.00', $price->price_decimal);
        $this->assertSame('0.00', $price->total_tax);
    }
}
