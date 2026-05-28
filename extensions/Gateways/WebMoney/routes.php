<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\WebMoney\WebMoney;

/*
|--------------------------------------------------------------------------
| WebMoney Gateway Routes
|--------------------------------------------------------------------------
*/

// Payment page - redirect to WebMoney
Route::get('/gateways/webmoney/pay/{invoice}', function ($invoiceId) {
    $invoice = \App\Models\Invoice::findOrFail($invoiceId);

    $gateway = \App\Models\Gateway::where('extension', 'WebMoney')->first();
    if (!$gateway) {
        abort(404, 'WebMoney gateway not configured');
    }

    $webmoney = new WebMoney($gateway->settings->pluck('value', 'key')->toArray());

    return $webmoney->pay($invoice, $invoice->total);
})->name('gateways.webmoney.pay');

// Webhook/Result URL for payment notifications
Route::post('/gateways/webmoney/webhook', function () {
    $gateway = \App\Models\Gateway::where('extension', 'WebMoney')->first();
    if (!$gateway) {
        Log::error('WebMoney webhook: Gateway not found');
        return response('NO', 404);
    }

    $webmoney = new WebMoney($gateway->settings->pluck('value', 'key')->toArray());

    return $webmoney->webhook(request());
})->name('gateways.webmoney.webhook');
