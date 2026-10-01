<?php

namespace Tests\Unit;

use Paymenter\Extensions\Servers\ResellerClub\Catalog;
use PHPUnit\Framework\TestCase;

class ResellerClubCatalogShapeTest extends TestCase
{
    public function test_inactive_launch_groups_with_empty_tld_lists_do_not_block_active_domains(): void
    {
        $catalog = Catalog::normalize([
            'syntheticlaunch' => ['tldlist' => []],
            'syntheticdomain' => ['tldlist' => ['test']],
        ], ['syntheticdomain' => ['addnewdomain' => [1 => '10.00']]], 'USD', 'customer');
        $this->assertSame(['.test'], array_keys($catalog['tlds']));
    }

    public function test_malformed_tld_lists_still_fail_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        Catalog::normalize(['bad' => ['tldlist' => 'test']], ['bad' => ['addnewdomain' => [1 => '10.00']]], 'USD', 'customer');
    }

    public function test_personal_name_profile_does_not_block_ordinary_name_or_compound_zones(): void
    {
        $catalog = Catalog::normalize([
            'thirdleveldotname' => ['tldlist' => ['*.name']],
            'dotname' => ['tldlist' => ['name']],
            'thirdleveldotuk' => ['tldlist' => ['co.uk']],
        ], [
            'thirdleveldotname' => ['addnewdomain' => [1 => '8.00']],
            'dotname' => ['addnewdomain' => [1 => '10.00', 2 => '9.00']],
            'thirdleveldotuk' => ['addnewdomain' => [1 => '12.00']],
        ], 'USD', 'customer');

        $this->assertSame(['.co.uk', '.name'], array_keys($catalog['tlds']));
        $this->assertSame('dotname', $catalog['tlds']['.name']['product_key']);
        $this->assertSame([1 => '10.00', 2 => '9.00'], $catalog['tlds']['.name']['register']);
        $this->assertSame('thirdleveldotuk', $catalog['tlds']['.co.uk']['product_key']);
    }

    public function test_unknown_wildcard_profile_still_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid TLD');
        Catalog::normalize([
            'otherprofile' => ['tldlist' => ['*.name']],
        ], ['otherprofile' => ['addnewdomain' => [1 => '10.00']]], 'USD', 'customer');
    }

    public function test_mixed_personal_name_profile_still_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid TLD');
        Catalog::normalize([
            'thirdleveldotname' => ['tldlist' => ['*.name', 'name']],
        ], ['thirdleveldotname' => ['addnewdomain' => [1 => '10.00']]], 'USD', 'customer');
    }

    public function test_personal_name_profile_alone_cannot_replace_the_catalog(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty domain catalog; previous prices retained');
        Catalog::normalize([
            'thirdleveldotname' => ['tldlist' => ['*.name']],
        ], ['thirdleveldotname' => ['addnewdomain' => [1 => '8.00']]], 'USD', 'customer');
    }
}
