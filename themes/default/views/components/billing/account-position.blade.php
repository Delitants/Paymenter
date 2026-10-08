@props(['quote', 'currency'])
<section class="space-y-4" aria-label="{{ __('Current account position') }}">
    <h2 class="text-lg font-bold">{{ __('Current account position') }}</h2>
    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
        @foreach(['Cash available' => $quote->cashAvailable, 'Outstanding debt' => $quote->debt,
            'Borrowing limit' => $quote->borrowingLimit, 'Remaining borrowing allowance' => $quote->remainingAllowance,
            'Reserved refunds' => $quote->reservedPrincipal] as $label => $amount)
        <div><dt class="text-base/70">{{ __($label) }}</dt><dd class="mt-1 tabular-nums whitespace-nowrap font-medium">{{ $amount }} {{ $currency }}</dd></div>
        @endforeach
    </dl>
    @if($quote->blocked)
    <p class="text-sm text-base/70" role="status">{{ __('Account funding is unavailable. Your statement and history remain available.') }}</p>
    @endif
</section>
