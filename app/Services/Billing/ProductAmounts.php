<?php

namespace App\Services\Billing;

final readonly class ProductAmounts
{
    public function __construct(
        public string $unitNet,
        public string $unitTax,
        public string $unitGross,
        public string $setupNet,
        public string $setupTax,
        public string $setupGross,
        public int $quantity,
        public string $net,
        public string $tax,
        public string $gross,
    ) {}
}
