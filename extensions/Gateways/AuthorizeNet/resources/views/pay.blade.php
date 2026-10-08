<div>
    <form method="POST" action="{{ $formUrl }}">
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit">Pay {{ $attempt->amount }} {{ $attempt->currency_code }} with Authorize.Net</button>
    </form>
</div>
