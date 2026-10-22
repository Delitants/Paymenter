<?php

use App\Http\Middleware\MustVerfiyEmail;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\Bind\Livewire\Zones\Index;
use Paymenter\Extensions\Others\Bind\Livewire\Zones\Show;

Route::middleware(['web', 'auth', MustVerfiyEmail::class])->group(function () {
    Route::get('/dns', Index::class)->name('bind.zones.index');
    Route::get('/dns/{binding}', Show::class)->name('bind.zones.show');
});
