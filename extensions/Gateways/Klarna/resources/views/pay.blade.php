<div class="mx-auto max-w-md space-y-4">
    <div>
        <h3 class="text-xl font-bold">{{ __('Pay with Klarna') }}</h3>
        <p class="mt-1 text-sm text-base/70">
            {{ $redirectUrl ? __('Continue your existing Klarna checkout.') : __('Choose the country of your Klarna account.') }}
        </p>
    </div>

    @if(!$redirectUrl)
        <form method="POST" action="{{ route('extensions.gateways.klarna.checkout', ['gateway' => $gatewayId, 'invoice' => $invoice->id, 'reference' => $attempt->reference]) }}" class="space-y-4">
            @csrf
            <div class="flex flex-col gap-2">
                <label for="klarna-purchase-country" class="text-sm font-semibold">{{ __('Country of your Klarna account') }} <span aria-hidden="true" class="text-error">*</span></label>
                <select id="klarna-purchase-country" name="purchase_country" required aria-describedby="klarna-currency-note" class="form-select block min-h-11 w-full rounded-md border border-neutral bg-background px-3 py-3 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <option value="" disabled selected>{{ __('Choose your country') }}</option>
                    @foreach($countries as $code => $market)
                        <option value="{{ $code }}">{{ __($market['name']) }} — {{ $market['billing_currency'] }}</option>
                    @endforeach
                </select>
                @error('purchase_country')<p role="alert" class="text-xs text-error">{{ $message }}</p>@enderror
            </div>
            <p id="klarna-currency-note" class="text-xs text-base/70">{{ __('Billing currency is linked to your country. Klarna confirms it using your account.') }}</p>
            @include('gateways.klarna::summary')
            <x-button.primary type="submit" class="min-h-11 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('Continue with Klarna') }}</x-button.primary>
            <p class="text-center text-xs text-base/70">{{ __('Country is fixed once you continue.') }}</p>
        </form>
    @else
        @if(isset($attempt->provider_payload['billing_currency']))
            <p class="text-sm">{{ __('Selected country: :country · Billing currency: :currency', ['country' => $attempt->provider_payload['purchase_country'], 'currency' => $attempt->provider_payload['billing_currency']]) }}</p>
        @endif
        @include('gateways.klarna::summary')
        <a href="{{ $redirectUrl }}" rel="noreferrer" class="flex min-h-11 items-center justify-center rounded-md bg-primary px-4 py-3 text-sm font-semibold text-white hover:bg-primary/80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('Continue with Klarna') }}</a>
    @endif
    <a href="{{ route('invoices.show', $invoice) }}" class="block py-2 text-center text-sm font-semibold text-base/70 hover:text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('Back to invoice') }}</a>
</div>
