<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\WebMoney\WebMoney;

Route::post('/extensions/webmoney/{gateway}/notify', [WebMoney::class, 'notify'])->middleware(SubstituteBindings::class)->name('extensions.gateways.webmoney.notify');
