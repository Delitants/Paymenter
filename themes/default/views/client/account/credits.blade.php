<div class="container mt-14">
    <x-navigation.breadcrumb />
    <div class="px-2">
        <h4 class="text-2xl font-bold pb-3">{{ __('account.credits') }}</h4>
        @if($statements->isNotEmpty())
        <section class="bg-background-secondary border border-neutral rounded-xl p-5 mb-6">
            <h2 class="text-lg font-bold">{{ __('Account statements') }}</h2>
            @foreach($statements as $statement)
            <a class="flex items-center min-h-11 text-primary underline" wire:navigate href="{{ route('account.funding', ['owner' => $statement->user_id, 'currency' => $statement->currency_code]) }}">{{ $statement->currency_code }} · {{ $statement->user_id === Auth::id() ? __('Your account statement') : __('Shared account statement') }}</a>
            @endforeach
            <p class="text-sm text-base/70 mt-3">{{ __('Deposits repay debt first. The remaining principal becomes cash. Gateway fees do not increase your account balance.') }}</p>
        </section>
        @endif
        @if (Auth::user()->credits->count() > 0)
        <div class="flex flex-wrap gap-4">
            @foreach (Auth::user()->credits as $credit)
            <div class="flex flex-col bg-background-secondary w-fit rounded-lg px-5 p-3 items-center gap-1">
                <h5 class="text-lg font-bold">{{ $credit->currency->code }}</h5>
                <p class="text-primary-100">{{ $credit->formattedAmount }}</p>
            </div>
            @endforeach
        </div>
        @else
        <p>{{ __('account.no_credit') }}</p>
        @endif

        @if($fundingBlocked)
        <p role="status" class="mt-5 text-sm text-base/70">{{ __('Account funding is unavailable. Your statement and history remain available.') }}</p>
        @else
        <h4 class="text-xl font-bold pb-3">{{ __('account.add_credit') }}</h4>

        <form wire:submit.prevent="addCredit">
            <!-- Currency and amount -->
            <div class="grid grid-cols-2 gap-4">
                <x-form.select name="currency" :label="__('account.input.currency')" wire:model.live="currency" required>
                    @foreach(\App\Models\Currency::all() as $currency)
                    <option value="{{ $currency->code }}">{{ $currency->code }}</option>
                    @endforeach
                </x-form.select>
                <x-form.input x-mask:dynamic="$money($input, '.', '', 2)" name="amount" type="number"
                    :label="__('account.input.amount')" :placeholder="__('account.input.amount_placeholder')"
                    wire:model.live.debounce.250ms="amount" required />

                <x-form.select name="gateway" :label="__('product.payment_method')" wire:model.live="gateway" required>
                    @foreach($gateways as $gatewayy)
                    <option value="{{ $gatewayy->id }}" wire:key="{{ $gatewayy->id }}" @if($gatewayy->id == $gateway) selected @endif>{{ $gatewayy->name }}</option>
                    @endforeach
                </x-form.select>
            </div>


            <x-button.primary type="submit" class="w-full mt-4">
                {{ __('account.add_credit') }}
            </x-button.primary>
        </form>
        @endif
    </div>
</div>
