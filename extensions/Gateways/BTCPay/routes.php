<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\BTCPay\BTCPay;

Route::post('/extensions/btcpay/{gateway}/notify', [BTCPay::class, 'notify'])
    ->whereNumber('gateway')->middleware(SubstituteBindings::class)->name('extensions.gateways.btcpay.notify');
