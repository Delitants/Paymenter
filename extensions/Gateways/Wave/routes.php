<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Wave\Wave;

Route::post('/extensions/wave/{gateway}/notify', [Wave::class, 'notify'])->middleware(SubstituteBindings::class)->name('extensions.gateways.wave.notify');
