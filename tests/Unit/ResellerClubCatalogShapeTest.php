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
}
