<div class="container mt-14">
    <div class="flex flex-col lg:grid lg:grid-cols-[minmax(0,1fr)_340px] gap-8">
        <div class="flex flex-col min-w-0 gap-4">
            @if (Cart::items()->count() === 0)
            <h1 class="text-2xl font-semibold">
                {{ __('product.empty_cart') }}
            </h1>
            @endif
            @foreach (Cart::items() as $item)
            <div class="flex flex-col sm:flex-row justify-between gap-4 min-w-0 w-full bg-background-secondary p-5 rounded-xl border border-neutral">
                <div class="flex flex-col gap-1">
                    <h2 class="text-2xl font-semibold">
                        {{ $item->product->name }}
                    </h2>
                    <p class="text-sm">
                        @foreach ($item->config_options as $option)
                        {{ $option['option_name'] }}: {{ $option['value_name'] }}<br>
                        @endforeach
                        @if(isset($item->checkout_config))
                            @php
                                $checkoutConfig = is_array($item->checkout_config) ? $item->checkout_config : json_decode($item->checkout_config, true);
                                $extensionConfig = \App\Helpers\ExtensionHelper::getCheckoutConfig($item->product, $checkoutConfig, $item->plan);
                            @endphp
                            @foreach($extensionConfig as $config)
                                @if(isset($config['type']) && $config['type'] === 'section' && isset($config['fields']))
                                    @foreach($config['fields'] as $field)
                                        @if(isset($checkoutConfig[$field['name']]))
                                            @if($field['type'] === 'radio' && isset($field['options'][$checkoutConfig[$field['name']]]))
                                                {{ $field['label'] ?? $field['name'] }}: {{ $field['options'][$checkoutConfig[$field['name']]] }}
                                                @if(isset($field['prices'][$checkoutConfig[$field['name']]]))
                                                    @php
                                                        $price = $field['prices'][$checkoutConfig[$field['name']]];
                                                        $priceDisplay = $price === 0 ? 'Free' : '$' . ($price / 100) . '/month';
                                                    @endphp
                                                    - {{ $priceDisplay }}
                                                @endif
                                                <br>
                                            @elseif($field['type'] === 'checkbox')
                                                {{ $field['label'] ?? $field['name'] }}: {{ $checkoutConfig[$field['name']] ? (empty($field['prices'][$checkoutConfig[$field['name']]]) ? __('Enabled (Included)') : __('Enabled')) : __('Disabled') }}<br>
                                            @elseif($field['type'] === 'text')
                                                {{ $field['label'] ?? $field['name'] }}: {{ $checkoutConfig[$field['name']] }}<br>
                                            @endif
                                        @endif
                                    @endforeach
                                @elseif(isset($checkoutConfig[$config['name']]))
                                    @if($config['type'] === 'text')
                                        {{ $config['label'] ?? $config['name'] }}: {{ $checkoutConfig[$config['name']] }}<br>
                                    @endif
                                @endif
                            @endforeach
                        @endif
                    </p>
                </div>
                <div class="flex flex-col justify-between items-end gap-4">
                    <h3 class="text-xl font-semibold p-1">
                        {{ $item->price->format((string) \Brick\Math\BigDecimal::of($item->price->total)->multipliedBy($item->quantity)) }} @if ($item->quantity > 1)
                        ({{ $item->price }} each)
                        @endif
                    </h3>
                    <div class="flex flex-wrap justify-end gap-2">
                        @if ($item->product->allow_quantity == 'combined')
                        <div class="flex flex-row gap-1 items-center mr-4">
                            <x-button.secondary
                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity - 1 }})"
                                class="h-full !w-fit">
                                -
                            </x-button.secondary>
                            <x-form.input class="h-10 text-center" disabled divClass="!mt-0 !w-14" value="{{ $item->quantity }}" name="quantity" />
                            <x-button.secondary
                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity + 1 }});"
                                class="h-full !w-fit">
                                +
                            </x-button.secondary>
                        </div>
                        @endif
                        <a href="{{ route('products.checkout', [$item->product->category, $item->product, 'edit' => $item->id]) }}"
                            wire:navigate>
                            <x-button.primary class="h-fit w-fit">
                                {{ __('product.edit') }}
                            </x-button.primary>
                        </a>
                        <x-button.danger wire:click="removeProduct({{ $item->id }})" class="h-fit !w-fit">
                            <x-loading target="removeProduct({{ $item->id }})" />
                            <div wire:loading.remove wire:target="removeProduct({{ $item->id }})">
                                {{ __('product.remove') }}
                            </div>
                        </x-button.danger>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        <div class="flex flex-col gap-4">
            @if (Cart::items()->count() > 0)
            <div class="flex flex-col gap-2 w-full bg-background-secondary p-5 rounded-xl lg:sticky lg:top-8 border border-neutral">
                <h2 class="text-2xl font-semibold mb-3">
                    {{ __('product.order_summary') }}
                </h2>
                <div class="font-semibold flex items-end gap-2">
                    @if(!$coupon)
                    <x-form.input wire:model="coupon" name="coupon" label="Coupon" />
                    <x-button.primary wire:click="applyCoupon" class="h-fit !w-fit mb-0.5" wire:loading.attr="disabled">
                        <x-loading target="applyCoupon" />
                        <div wire:loading.remove wire:target="applyCoupon">
                            {{ __('product.apply') }}
                        </div>
                    </x-button.primary>
                    @else
                    <div class="flex justify-between items-center w-full">
                        <h4 class="text-center w-full">{{ $coupon->code }}</h4>
                        <x-button.secondary wire:click="removeCoupon" class="h-fit !w-fit">
                            {{ __('product.remove') }}
                        </x-button.secondary>
                    </div>
                    @endif
                </div>
                <div class="space-y-4 my-3">
                    @if(count($this->gateways) > 0)
                    <x-form.select name="gateway" wire:model.live="gateway" :label="__('Payment method')">
                        @foreach($this->gateways as $method)
                        <option value="{{ $method->id }}">{{ $method->name }}</option>
                        @endforeach
                    </x-form.select>
                    @else
                    <p class="text-sm text-base/70">{{ __('No payment method is currently available.') }}</p>
                    @endif
                    @if(Auth::check() && config('settings.credits_enabled') && Auth::user()->credits()->where('currency_code', Cart::get()->currency_code)->where('amount', '>', 0)->exists())
                    <x-form.checkbox name="use_credits" wire:model.live="use_credits">{{ __('Use available account credits') }}</x-form.checkbox>
                    @endif
                    @php($currentTax = \App\Classes\Settings::tax())
                    <x-billing.payment-summary :summary="$this->paymentSummary" :formatter="$total" :tax-name="$currentTax?->name ?? 'Tax'" :tax-rate="(string) ($currentTax?->rate ?? '0')" paid-label="Account credits applied" />
                    <p class="text-xs text-base/60">{{ __('Gateway fees are not taxed. The fee is confirmed when payment starts.') }}</p>
                </div>

                <div class="flex flex-col gap-2 w-full col-span-1">
                    @if(config('settings.tos'))
                    <x-form.checkbox wire:model="tos" name="tos">
                        {{ __('product.tos') }}
                        <a href="{{ config('settings.tos') }}" target="_blank" class="text-primary hover:text-primary/80">
                            {{ __('product.tos_link') }}
                        </a>
                    </x-form.checkbox>
                    @endif

                    <div class="flex flex-row justify-end gap-2">
                        <x-button.primary wire:click="checkout" class="h-fit" wire:loading.attr="disabled">
                            <x-loading target="checkout" />
                            <div wire:loading.remove wire:target="checkout">
                                {{ __('product.checkout') }}
                            </div>
                        </x-button.primary>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
