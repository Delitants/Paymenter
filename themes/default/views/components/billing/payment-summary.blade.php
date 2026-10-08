@props(['summary', 'formatter', 'taxName' => 'Tax', 'taxRate' => '0', 'paidLabel' => 'Paid'])
<dl class="space-y-3 text-sm [&_dd]:whitespace-nowrap" aria-live="polite">
    <div class="flex justify-between gap-4">
        <dt class="text-base/70">{{ __('invoices.subtotal') }}</dt>
        <dd class="font-medium tabular-nums">{{ $formatter->format($summary->productNet) }}</dd>
    </div>
    @if(\Brick\Math\BigDecimal::of($summary->productTax)->isPositive())
    <div class="flex justify-between gap-4">
        <dt class="text-base/70">{{ $taxName }}@if($taxRate !== null && $taxRate !== '' && \Brick\Math\BigDecimal::of((string) $taxRate)->isPositive()) ({{ str_contains($taxRate, '.') ? rtrim(rtrim($taxRate, '0'), '.') : $taxRate }}%)@endif</dt>
        <dd class="font-medium tabular-nums">{{ $formatter->format($summary->productTax) }}</dd>
    </div>
    @endif
    <div class="flex justify-between gap-4">
        <dt class="text-base/70">{{ __('Gateway fee') }}</dt>
        <dd class="font-medium tabular-nums">{{ $formatter->format($summary->gatewayFee) }}</dd>
    </div>
    <div class="flex justify-between gap-4 border-t border-neutral pt-3 text-base">
        <dt class="font-semibold">{{ __('invoices.total') }}</dt>
        <dd class="font-semibold tabular-nums">{{ $formatter->format($summary->total) }}</dd>
    </div>
    @if(\Brick\Math\BigDecimal::of($summary->paid)->isPositive())
    <div class="flex justify-between gap-4">
        <dt class="text-base/70">{{ __($paidLabel) }}</dt>
        <dd class="font-medium tabular-nums">{{ $formatter->format($summary->paid) }}</dd>
    </div>
    <div class="flex justify-between gap-4 text-base">
        <dt class="font-semibold">{{ __('Amount due') }}</dt>
        <dd class="font-semibold tabular-nums">{{ $formatter->format($summary->payable) }}</dd>
    </div>
    @endif
</dl>
