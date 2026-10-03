<div class="rounded-lg border border-neutral bg-background p-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <span class="text-sm font-semibold text-base/80">{{ __('Invoice total') }}</span>
        <span class="text-xl font-bold tabular-nums">{{ $attempt->amount }} {{ $attempt->currency_code }}</span>
    </div>
    @if($attempt->pricing_payload !== null)
        <dl class="mt-3 space-y-1.5 text-sm text-base/70">
            <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('Products') }}</dt><dd class="tabular-nums">{{ $attempt->pricing_payload['product_net'] }} {{ $attempt->currency_code }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('Product tax') }}</dt><dd class="tabular-nums">{{ $attempt->pricing_payload['product_tax'] }} {{ $attempt->currency_code }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('Payment fee (not taxed)') }}</dt><dd class="tabular-nums">{{ $attempt->pricing_payload['gateway_fee'] }} {{ $attempt->currency_code }}</dd></div>
        </dl>
    @endif
</div>
@if(!$redirectUrl || isset($attempt->provider_payload['billing_currency']))
    <p class="rounded-md border border-primary/20 bg-primary/5 p-3.5 text-sm leading-relaxed text-base/80">{{ __('Your Paymenter invoice stays in USD. Klarna shows the converted amount and exchange rate before you confirm; the final rate is set when the payment is captured.') }}</p>
@endif
