<div class="space-y-4">
    <p>Your invoice total is <strong>{{ $attempt->amount }} USD</strong>.</p>
    <p>Pay <strong>{{ $quote['provider_amount'] }} EUR</strong> with WebMoney. Your payment will settle the original USD invoice amount.</p>
    <p class="text-sm">Exchange rate: 1 EUR = {{ $quote['rates']['denominator'] }} USD ({{ $quote['rates']['date'] }}). Confirm within 30 minutes.</p>
    <form method="POST" action="{{ route('extensions.gateways.webmoney.checkout', ['gateway' => $gateway->id, 'invoice' => $invoice->id, 'reference' => $attempt->reference]) }}">
        @csrf
        <input type="hidden" name="quote_fingerprint" value="{{ $quote['fingerprint'] }}">
        <x-button.primary type="submit">Continue to WebMoney — {{ $quote['provider_amount'] }} EUR</x-button.primary>
    </form>
</div>
