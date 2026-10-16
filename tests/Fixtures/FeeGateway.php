<?php

namespace Tests\Fixtures;

use App\Classes\Extension\Gateway;
use App\Models\Invoice;
use App\Services\Gateways\PaymentAttempts;

class FeeGateway extends Gateway
{
    public function supportsCustomerFeeCollection(): bool
    {
        return true;
    }

    public function pay(Invoice $invoice, $total)
    {
        return (new PaymentAttempts)->begin($this->gatewayRecord, $invoice, hash('sha256', 'synthetic merchant'), 'USD')->reference;
    }
}

class_alias(FeeGateway::class, 'Paymenter\\Extensions\\Gateways\\FeeGateway\\FeeGateway');
