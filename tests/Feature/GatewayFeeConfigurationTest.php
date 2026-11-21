<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Services\Billing\PaymentSummary;
use App\Services\Gateways\GatewayFeePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GatewayFeeConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function gateway(array $settings = [], string $extension = 'AuthorizeNet'): Gateway
    {
        $g = Gateway::create(['name' => 'Synthetic ' . Gateway::count(), 'extension' => $extension, 'type' => 'gateway', 'enabled' => true]);
        foreach ($settings as $key => $value) {
            $g->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $g;
    }

    public function test_common_fee_fields_default_to_zero(): void
    {
        $g = $this->gateway();
        $policy = new GatewayFeePolicy;
        $this->assertSame(['enabled' => false, 'percent' => '0.0000', 'fixed' => '0.00', 'currency' => ''], $policy->values($g, 'USD'));
        $names = array_column(ExtensionHelper::getConfig('gateway', 'AuthorizeNet'), 'name');
        foreach (['customer_fee_enabled', 'customer_fee_percent', 'customer_fee_fixed', 'customer_fee_currency'] as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function test_quotes_replace_the_preview_fee_and_use_only_unpaid_net(): void
    {
        $g = $this->gateway(['customer_fee_enabled' => '1', 'customer_fee_percent' => '2.5', 'customer_fee_fixed' => '0.25', 'customer_fee_currency' => 'USD']);
        $policy = new GatewayFeePolicy;
        $base = new PaymentSummary('USD', '100.00', '7.13', '107.13', '100.00', '7.13', '0.00', '107.13', '0.00', '107.13');
        $quote = $policy->quote($base, $g);
        $this->assertSame('2.75', $quote->gatewayFee);
        $this->assertSame('109.88', $quote->total);
        $this->assertSame('109.88', $quote->payable);
        $this->assertSame('109.88', $policy->quote($quote, $g)->payable);
        $partial = new PaymentSummary('USD', '100.00', '7.13', '107.13', '46.67', '3.33', '0.00', '107.13', '57.13', '50.00');
        $this->assertSame('51.42', $policy->quote($partial, $g)->payable);
        $paid = new PaymentSummary('USD', '100.00', '7.13', '107.13', '0.00', '0.00', '2.75', '109.88', '109.88', '0.00');
        $this->assertSame('109.88', $policy->quote($paid, $g)->total);
    }

    #[DataProvider('badPolicies')]
    public function test_invalid_and_mismatched_fee_policy_is_unavailable(array $settings): void
    {
        $g = $this->gateway($settings + ['customer_fee_enabled' => '1']);
        $this->expectException(\InvalidArgumentException::class);
        (new GatewayFeePolicy)->values($g, 'USD');
    }

    public static function badPolicies(): array
    {
        return array_map(fn ($settings) => [$settings], [
            ['customer_fee_percent' => '-1'], ['customer_fee_percent' => '1e2'],
            ['customer_fee_percent' => '100'], ['customer_fee_percent' => 'NaN'],
            ['customer_fee_percent' => '2.50001'], ['customer_fee_fixed' => '-0.25'],
            ['customer_fee_fixed' => '0.251'], ['customer_fee_fixed' => '0.25', 'customer_fee_currency' => 'EUR'],
            ['customer_fee_enabled' => 'unknown'],
        ]);
    }

    public function test_fee_policy_uses_the_exact_gateway_record(): void
    {
        $this->gateway(['customer_fee_enabled' => '1', 'customer_fee_percent' => '9.5']);
        $selected = $this->gateway(['customer_fee_enabled' => '1', 'customer_fee_percent' => '2.5']);
        $this->assertSame('2.5000', (new GatewayFeePolicy)->values($selected, 'USD')['percent']);
    }

    public function test_unverified_adapter_cannot_collect_a_configured_fee(): void
    {
        $g = $this->gateway(['customer_fee_enabled' => '1', 'customer_fee_percent' => '2.5'], 'Stripe');
        $this->expectException(\RuntimeException::class);
        (new GatewayFeePolicy)->assertSupported($g, 'USD');
    }
}
