<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Klarna\Klarna;

Route::post('/extensions/klarna/{gateway}/notify/{reference}', [Klarna::class, 'notify'])->middleware(SubstituteBindings::class)->name('extensions.gateways.klarna.notify');
