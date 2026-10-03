<?php

namespace App\Services\Billing;

final readonly class PaymentSummary
{
    public function __construct(
        public string $currency,
        public string $productNet,
        public string $productTax,
        public string $productGross,
        public string $unpaidNet,
        public string $unpaidTax,
        public string $gatewayFee,
        public string $total,
        public string $paid,
        public string $payable,
    ) {}
}
