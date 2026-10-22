<?php

use App\Http\Middleware\MustVerfiyEmail;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Klarna\Klarna;

Route::post('/extensions/klarna/{gateway}/notify/{reference}', [Klarna::class, 'notify'])->middleware(SubstituteBindings::class)->name('extensions.gateways.klarna.notify');

Route::post('/extensions/klarna/{gateway}/checkout/{invoice:id}/{reference}', [Klarna::class, 'checkout'])
    ->withoutScopedBindings()
    ->middleware(['web', 'auth', MustVerfiyEmail::class, 'can:update,invoice'])
    ->name('extensions.gateways.klarna.checkout');
