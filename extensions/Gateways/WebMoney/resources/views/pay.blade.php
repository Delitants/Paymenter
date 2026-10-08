<div>
    <form method="POST" action="https://merchant.wmtransfer.com/lmi/payment_utf.asp" accept-charset="UTF-8">
        <input type="hidden" name="LMI_PAYEE_PURSE" value="{{ $purse }}">
        <input type="hidden" name="LMI_PAYMENT_AMOUNT" value="{{ $amount }}">
        <input type="hidden" name="LMI_PAYMENT_NO" value="{{ $attempt->reference }}">
        <input type="hidden" name="LMI_PAYMENT_DESC_BASE64" value="{{ base64_encode('Invoice ' . ($invoice->number ?: $invoice->id)) }}">
        <input type="hidden" name="LMI_RESULT_URL" value="{{ url('/extensions/webmoney/' . $gateway->id . '/notify') }}">
        <input type="hidden" name="LMI_SUCCESS_URL" value="{{ route('invoices.show', $invoice) }}">
        <input type="hidden" name="LMI_FAIL_URL" value="{{ route('invoices.show', $invoice) }}">
        @if($testMode)<input type="hidden" name="LMI_SIM_MODE" value="0">@endif
        @if($currency !== $attempt->currency_code)
            <p>Your invoice total is {{ $attempt->amount }} {{ $attempt->currency_code }}. WebMoney will charge {{ $amount }} {{ $currency }}.</p>
        @endif
        <button type="submit">Pay {{ $amount }} {{ $currency }} with WebMoney</button>
    </form>
</div>
