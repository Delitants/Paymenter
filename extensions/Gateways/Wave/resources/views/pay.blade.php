<div>
    <a href="{{ $viewUrl }}" rel="noreferrer">Pay {{ $attempt->amount }} {{ $attempt->currency_code }} with Wave</a>
    <p class="mt-3 text-sm text-neutral-500 dark:text-neutral-400">{{ __('After you pay your Wave invoice, please allow up to 5 minutes for your payment to appear in your account.') }}</p>
</div>
