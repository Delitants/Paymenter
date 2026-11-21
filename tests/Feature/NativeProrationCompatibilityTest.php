<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NativeProrationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_upgrade_and_downgrade_keep_signed_rounded_proration(): void
    {
        Bus::fake();
        Mail::fake();
        config(['settings.tax_enabled' => false]);
        Carbon::setTestNow('2026-10-01 12:00:00');
        try {
            $old = $this->createProduct();
            $new = $this->createProduct();
            $new->plan->prices()->first()->update(['price' => '20.00', 'setup_fee' => '0.00']);
            $old->plan->prices()->first()->update(['setup_fee' => '0.00']);
            $service = Service::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $old->product->id, 'plan_id' => $old->plan->id, 'currency_code' => 'USD', 'expires_at' => now()->addDay()]);
            $upgrade = new ServiceUpgrade;
            $upgrade->setRelation('service', $service);
            $this->assertSame('0.33', $upgrade->calculateProratedAmount($old->product, $new->product)->price_decimal);
            $this->assertSame('-0.33', $upgrade->calculateProratedAmount($new->product, $old->product)->price_decimal);
            $this->assertSame('$-0.33', $upgrade->calculateProratedAmount($new->product, $old->product)->formatted->total);
        } finally {
            Carbon::setTestNow();
        }
    }
}
