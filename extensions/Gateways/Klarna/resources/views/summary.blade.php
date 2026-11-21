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
@if(isset($attempt->provider_payload['conversion_quote']))
    @php($quote = $attempt->provider_payload['conversion_quote'])
    <div class="rounded-lg border border-primary/20 bg-primary/5 p-4 space-y-2">
        <p class="text-sm font-semibold">{{ __('You pay with Klarna') }}</p>
        <p class="text-xl font-bold tabular-nums">{{ \Brick\Math\BigDecimal::of($quote['allocation']['order_amount'])->dividedBy(100, 2) }} {{ $quote['provider_currency'] }}</p>
        <p class="text-xs text-base/70">{{ __('Reference pricing rate: 1 USD ≈ :rate :currency · :date', ['rate' => \Brick\Math\BigRational::of($quote['rates']['numerator'])->dividedBy($quote['rates']['denominator'])->toScale(8, \Brick\Math\RoundingMode::HALF_UP), 'currency' => $quote['provider_currency'], 'date' => $quote['rates']['date']]) }}</p>
        <p class="text-sm">{{ __('Your invoice stays in USD. This payment will be applied to the original USD invoice total. No additional currency markup is included.') }}</p>
    </div>
@elseif(($localCurrency ?? false))
    <p class="text-sm text-base/70">{{ __('Your invoice stays in USD. Review the local-currency amount before confirming your Klarna payment.') }}</p>
@elseif(!$redirectUrl || isset($attempt->provider_payload['billing_currency']))
    <p class="rounded-md border border-primary/20 bg-primary/5 p-3.5 text-sm leading-relaxed text-base/80">{{ __('Your Paymenter invoice stays in USD. Klarna shows the converted amount and exchange rate before you confirm; the final rate is set when the payment is captured.') }}</p>
@endif
