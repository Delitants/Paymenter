<?php

use App\Http\Middleware\MustVerfiyEmail;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\WebMoney\WebMoney;

Route::post('/extensions/webmoney/{gateway}/notify', [WebMoney::class, 'notify'])->middleware(SubstituteBindings::class)->name('extensions.gateways.webmoney.notify');

Route::post('/extensions/webmoney/{gateway}/checkout/{invoice:id}/{reference}', [WebMoney::class, 'checkout'])
    ->withoutScopedBindings()
    ->middleware(['web', 'auth', MustVerfiyEmail::class, 'can:update,invoice'])
    ->name('extensions.gateways.webmoney.checkout');
