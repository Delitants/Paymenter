<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ResellerClub Extension Routes
|--------------------------------------------------------------------------
*/

// Domain search/availability check
Route::post('/api/resellerclub/check', function () {
    $domain = request('domain');

    if (!$domain) {
        return response()->json(['error' => 'Domain name required'], 400);
    }

    $gateway = \App\Models\Server::where('extension', 'ResellerClub')->first();
    if (!$gateway) {
        return response()->json(['error' => 'ResellerClub not configured'], 400);
    }

    $resellerClub = new \Paymenter\Extensions\Servers\ResellerClub\ResellerClub(
        $gateway->settings->pluck('value', 'key')->toArray()
    );

    return response()->json($resellerClub->checkAvailability($domain));
})->middleware('auth:api')->name('api.resellerclub.check');
