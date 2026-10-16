@props(['product', 'expanded' => false, 'collapsible' => false])
@php
    $zonePricing = \App\Helpers\ExtensionHelper::getProductPricing($product);
    $zoneCurrency = $zonePricing ? \App\Models\Currency::find($zonePricing['currency']) : null;
@endphp
@if($zonePricing && $zoneCurrency)
    <div class="my-3 text-sm" data-zone-pricing>
        @if($expanded && $collapsible)
            <details class="rounded-lg border border-neutral">
                <summary class="cursor-pointer px-4 py-3 text-primary">
                    <span class="font-semibold">{{ __('Selected :zone', ['zone' => $zonePricing['zone']]) }}</span>
                    <span class="block mt-1 text-xs">{{ __('View registration and renewal prices') }}</span>
                </summary>
                <div class="px-3 pb-3">
        @endif
        @if($expanded)
            <div class="overflow-x-auto rounded-lg border border-neutral">
                <table class="w-full text-left">
                    <caption class="text-left px-4 py-3 font-semibold">{{ __('Prices for :zone', ['zone' => $zonePricing['zone']]) }}</caption>
                    <thead class="bg-background-secondary"><tr>
                        <th class="px-4 py-2">{{ __('Term') }}</th><th class="px-4 py-2">{{ __('Registration') }}</th><th class="px-4 py-2">{{ __('Renewal') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($zonePricing['terms'] as $term)
                            @php
                                $registration = new \App\Classes\Price(['price' => $term['registration'], 'currency' => $zoneCurrency], apply_exclusive_tax: true);
                                $renewal = new \App\Classes\Price(['price' => $term['renewal'], 'currency' => $zoneCurrency], apply_exclusive_tax: true);
                            @endphp
                            <tr class="border-t border-neutral"><td class="px-4 py-3">{{ trans_choice(':count year|:count years', $term['years']) }}</td>
                                <td class="px-4 py-3">{{ $registration->formatted->price }}</td><td class="px-4 py-3">{{ $renewal->formatted->price }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @php
                $term = $zonePricing['terms'][0];
                $registration = new \App\Classes\Price(['price' => $term['registration'], 'currency' => $zoneCurrency], apply_exclusive_tax: true);
                $renewal = new \App\Classes\Price(['price' => $term['renewal'], 'currency' => $zoneCurrency], apply_exclusive_tax: true);
            @endphp
            <dl class="space-y-1">
                <div class="flex justify-between gap-3"><dt>{{ __('Register') }}</dt><dd class="font-semibold">{{ $registration->formatted->price }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('Renew') }}</dt><dd>{{ $renewal->formatted->price }}</dd></div>
            </dl>
            <p class="mt-1 text-muted">{{ trans_choice('For :count year|For :count years', $term['years']) }}</p>
        @endif
        @if($expanded && $collapsible)
                <p class="mt-2 text-xs text-muted">{{ __('Prices include product tax. Optional extras and gateway fees are excluded.') }}</p>
                </div>
            </details>
        @endif
        <p class="mt-2 text-xs text-muted">{{ __('WHOIS protection is optional. Renewal prices may change before the next invoice is issued.') }}</p>
    </div>
@elseif(!$expanded)
    <h3 class="text-lg font-semibold mb-2">{{ $product->price()->formatted->price }}</h3>
@endif
