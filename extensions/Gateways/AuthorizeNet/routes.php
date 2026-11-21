<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\AuthorizeNet\AuthorizeNet;

Route::post('/extensions/authorizenet/{gateway}/notify', [AuthorizeNet::class, 'notify'])->middleware(SubstituteBindings::class)->name('extensions.gateways.authorizenet.notify');
