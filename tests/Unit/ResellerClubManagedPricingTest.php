<?php

namespace Tests\Unit;

use Paymenter\Extensions\Servers\ResellerClub\Catalog;
use Paymenter\Extensions\Servers\ResellerClub\ManagedPricing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ResellerClubManagedPricingTest extends TestCase
{
    private function quote(string $retail = '12', string $cost = '10'): array
    {
        return ['product_key' => 'bundle', 'register' => [1 => $retail], 'renew' => [], 'transfer' => [],
            'cost' => ['register' => [1 => $cost], 'renew' => [], 'transfer' => []]];
    }

    private function price(array $quote, ?array $policy = null, array $previous = []): array
    {
        return ManagedPricing::quote($quote, 'USD', str_repeat('a', 64), $policy, $previous);
    }

    public function test_cost_increases_preserve_original_markup_even_when_retail_is_now_below_cost(): void
    {
        $first = $this->price($this->quote());
        $this->assertSame('12.00', $first['quote']['register'][1]);
        $rise = $this->price($this->quote('12', '11'), $first['policy']);
        $this->assertSame('13.20', $rise['quote']['register'][1]);
        $next = $this->price($this->quote('12', '15'), $rise['policy']);
        $this->assertSame('18.00', $next['quote']['register'][1]);
        $this->assertSame([], $next['review']);
        $this->assertSame(1, $next['raised']);
    }

    public function test_cost_decreases_do_not_lower_price_and_numeric_formatting_does_not_reset_policy(): void
    {
        $first = $this->price($this->quote());
        $rise = $this->price($this->quote('12.000', '15'), $first['policy']);
        $fall = $this->price($this->quote('12.00', '5'), $rise['policy']);
        $this->assertSame('18.00', $fall['quote']['register'][1]);
        $this->assertSame(0, $fall['adopted']);
        $this->assertSame('18.00', $this->price($this->quote('12', '0'), $fall['policy'])['quote']['register'][1]);
    }

    public function test_changed_provider_retail_recaptures_markup_and_can_deliberately_lower_price(): void
    {
        $first = $this->price($this->quote());
        $rise = $this->price($this->quote('12', '15'), $first['policy']);
        $edit = $this->price($this->quote('16', '15'), $rise['policy']);
        $this->assertSame('16.00', $edit['quote']['register'][1]);
        $this->assertSame(1, $edit['adopted']);
        $this->assertSame('17.07', $this->price($this->quote('16', '16'), $edit['policy'])['quote']['register'][1]);
    }

    public function test_each_action_and_term_uses_its_own_markup_and_exact_or_flat_annual_cost(): void
    {
        $quote = $this->quote();
        $quote['register'][2] = '11';
        $quote['renew'] = [1 => '15'];
        $quote['cost']['renew'] = [1 => '10'];
        $first = $this->price($quote);
        $quote['cost']['register'] = [1 => '12'];
        $quote['cost']['renew'] = [1 => '11'];
        $rise = $this->price($quote, $first['policy']);
        $this->assertSame([1 => '14.40', 2 => '26.40'], $rise['quote']['register']);
        $this->assertSame([1 => '16.50'], $rise['quote']['renew']);
        $quote['cost']['register'] = [1 => '12', 2 => '10'];
        $this->assertSame('26.40', $this->price($quote, $rise['policy'])['quote']['register'][2]);
    }

    public function test_term_totals_round_only_after_exact_ratio_calculation(): void
    {
        $first = $this->price($this->quote('1', '0.3'));
        $this->assertSame('1.67', $this->price($this->quote('1', '0.5'), $first['policy'])['quote']['register'][1]);
        $quote = $this->quote('0.335', '0.1');
        $quote['register'] = [3 => '0.335'];
        $this->assertSame('1.01', $this->price($quote)['quote']['register'][3]);
    }

    public function test_unusable_initial_policy_is_reviewed_and_previous_quotes_are_preserved(): void
    {
        foreach ([['9', '10'], ['12', '0'], ['0', '10']] as [$retail, $cost]) {
            $result = $this->price($this->quote($retail, $cost), previous: ['register' => [1 => '14.00']]);
            $this->assertCount(1, $result['review']);
            $this->assertSame('14.00', $result['quote']['register'][1]);
            $this->assertSame([], $result['policy']['entries']);
        }
    }

    public function test_missing_or_ambiguous_cost_requires_review_instead_of_interpolation(): void
    {
        $quote = $this->quote();
        $quote['register'] = [3 => '12'];
        foreach ([[], [1 => '10', 2 => '9']] as $costs) {
            $quote['cost']['register'] = $costs;
            $result = $this->price($quote);
            $this->assertCount(1, $result['review']);
            $this->assertSame([], $result['quote']['register']);
        }
    }

    public function test_zero_retail_and_cost_do_not_invent_a_margin_when_cost_later_increases(): void
    {
        $first = $this->price($this->quote('0', '0'));
        $this->assertSame('0.00', $first['quote']['register'][1]);
        $this->assertSame([], $first['review']);
        $this->assertCount(1, $this->price($this->quote('0', '1'), $first['policy'])['review']);
    }

    public function test_bad_retail_edit_holds_previous_price_without_replacing_the_valid_baseline(): void
    {
        $first = $this->price($this->quote());
        $bad = $this->price($this->quote('9', '10'), $first['policy']);
        $this->assertCount(1, $bad['review']);
        $this->assertSame('12.00', $bad['quote']['register'][1]);
        $this->assertSame('13.20', $this->price($this->quote('12', '11'), $bad['policy'])['quote']['register'][1]);
    }

    public function test_currency_provider_product_and_corrupt_baselines_fail_closed(): void
    {
        $policy = $this->price($this->quote())['policy'];
        foreach ([['currency', 'EUR'], ['provider_identity', str_repeat('b', 64)], ['product_key', 'other'], ['version', 99], ['entries', ['register' => [1 => ['retail' => '12', 'cost' => '0', 'total' => '12.00', 'cost_term' => 1]]]]] as [$key, $value]) {
            $rejected = false;
            try {
                $this->price($this->quote(), array_replace($policy, [$key => $value]));
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Invalid policy accepted');
        }
    }

    public function test_repricing_beyond_native_decimal_capacity_fails_closed(): void
    {
        $first = $this->price($this->quote('99999999', '0.000001'));
        $this->expectException(RuntimeException::class);
        $this->price($this->quote('99999999', '99999999'), $first['policy']);
    }

    public function test_lost_individual_or_all_baseline_terms_cannot_reset_a_raised_floor(): void
    {
        $first = $this->price($this->quote());
        $rise = $this->price($this->quote('12', '15'), $first['policy']);
        foreach ([false, true] as $all) {
            $policy = $rise['policy'];
            if ($all) {
                $policy['entries'] = [];
            } else {
                unset($policy['entries']['register'][1]);
            }
            $rejected = false;
            try {
                $this->price($this->quote(), $policy, $rise['quote']);
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Lost baseline reset the raised floor');
        }
    }

    public function test_present_null_action_tables_are_malformed_for_retail_and_wholesale(): void
    {
        foreach (['customer', 'cost'] as $source) {
            $rejected = false;
            try {
                Catalog::normalize(['bundle' => ['tldlist' => ['test']]], ['bundle' => ['addnewdomain' => [1 => '12'], 'renewdomain' => null]], 'USD', $source, wholesaleBasis: $source === 'cost');
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Malformed null action table accepted');
        }
    }

    public function test_wholesale_annual_default_is_retained_for_tlds_with_longer_minimum_registration(): void
    {
        $products = ['bundle' => ['tldlist' => ['test'], 'minregistrationyear' => 2]];
        $prices = ['bundle' => ['addnewdomain' => [1 => '10']]];
        $this->assertSame([1 => '10'], Catalog::normalize($products, $prices, 'USD', 'cost', wholesaleBasis: true)['tlds']['.test']['register']);
        $this->assertSame([], Catalog::normalize($products, $prices, 'USD', 'cost')['tlds']['.test']['register']);
        $this->assertSame([], Catalog::normalize($products, $prices, 'USD', 'customer')['tlds']['.test']['register']);
    }
}
