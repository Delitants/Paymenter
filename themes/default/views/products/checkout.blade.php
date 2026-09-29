@php
    $taxSettings = \App\Classes\Settings::tax();
    $taxName = is_object($taxSettings) ? $taxSettings->name : 'Tax';
    $taxRateDisplay = is_object($taxSettings) ? $taxSettings->rate : 0;
    $checkoutPricingState = [
        'checkoutConfig' => $this->checkoutConfig,
        'hasBootMedia' => collect($this->getCheckoutConfig())->contains(fn ($field) => in_array($field['name'] ?? '', ['cloud_image', 'iso_image'], true)),
    ];
@endphp

@script
<script>
    // Only presentation dependencies are local; all displayed prices come from Price.
    Alpine.data('checkoutPricing', (state) => ({
        checkoutConfig: state.checkoutConfig || {},
        hasBootMedia: state.hasBootMedia,
        get cloudImage() { return this.checkoutConfig.cloud_image || ''; },
        get isoImage() { return this.checkoutConfig.iso_image || ''; },
        get cloudImageDisabled() { return this.checkoutConfig.vm_type === 'lxc' || this.isoImage !== ''; },
        get isoImageDisabled() { return this.checkoutConfig.vm_type === 'lxc' || this.cloudImage !== ''; },
        get showSelectionMessage() { return this.hasBootMedia && this.checkoutConfig.vm_type !== 'lxc' && this.cloudImage === '' && this.isoImage === ''; },
        get cloudStar() { return this.cloudImageDisabled ? '' : '*'; },
        get isoStar() { return this.isoImageDisabled ? '' : '*'; },
        setCheckoutConfig(name, value) { this.checkoutConfig[String(name)] = value; },
        syncFieldChange(detail) {
            const [group, key] = (detail?.name || '').split('.');
            if (group === 'checkoutConfig' && key) this.setCheckoutConfig(key, detail.value);
        },
    }));
</script>
@endscript

	<div class="container mt-14 grid grid-cols-1 md:grid-cols-4 gap-8"
    x-data="checkoutPricing(@js($checkoutPricingState))"
    @checkout-field-change="syncFieldChange($event.detail)">

@once
    <style>
        @media (min-width: 768px) {
            .checkout-summary-panel {
                position: fixed;
                top: 5rem;
                right: max(2rem, calc((100vw - 1280px) / 2 + 2rem));
                width: min(18rem, calc((100vw - 5.5rem) / 4));
                z-index: 20;
            }

            .checkout-form-stack {
                padding-right: 2.5rem;
                row-gap: 1rem;
            }
        }

        @media (max-width: 767px) {
            .checkout-summary-panel {
                position: static;
                width: 100%;
            }
        }

        .checkout-field-description {
            margin-top: 0.5rem;
            margin-bottom: 0.125rem;
        }

        .checkout-image-select:disabled {
            cursor: not-allowed;
            opacity: 0.55;
        }
    </style>
@endonce

    {{-- Order Summary - Right side on desktop (first in DOM) --}}
    <div class="checkout-summary-column order-last md:order-none md:col-start-4 md:row-start-1">
    <div class="checkout-summary-panel bg-background-secondary p-4 rounded-md h-fit">
        <h2 class="text-2xl font-semibold mb-3">
            {{ __('product.order_summary') }}
        </h2>
        @if ($total->total_tax > 0)
            <div class="font-semibold flex justify-between gap-3">
                <h4>{{ __('invoices.subtotal') }}:</h4> {{ $total->format($total->subtotal) }}
            </div>
            <div class="font-semibold flex justify-between gap-3">
                <h4>{{ $taxName }} ({{ $taxRateDisplay }}%):</h4> {{ $total->format($total->tax) }}
            </div>
        @endif
        <div class="text-lg font-semibold flex justify-between gap-3">
            <h4>{{ __('product.total_today') }}:</h4>
            <span data-checkout-total>{{ $total->formatted->total }}</span>
        </div>
        @if ($total->setup_fee > 0 && $plan->type == 'recurring')
            <div class="mt-2 text-sm font-semibold flex justify-between gap-3">
                <h4>{{ __('product.then_after_x', ['time' => $plan->billing_period . ' ' . trans_choice(__('services.billing_cycles.' . $plan->billing_unit), $plan->billing_period)]) }}:
                </h4> <span data-checkout-recurring>{{ $total->formatted->price }}</span>
            </div>
        @endif
        @if (($product->stock > 0 || !$product->stock) && $product->price()->available)
            <div class="mt-4">
                <x-button.primary wire:click="checkout" wire:loading.attr="disabled">
                    <x-loading target="checkout" />
                    <div wire:loading.remove wire:target="checkout">
                        {{ __('product.checkout') }}
                    </div>
                </x-button.primary>
            </div>
        @endif
    </div>
    </div>

    {{-- Main form content - Left side on desktop --}}
    <div class="checkout-form-stack md:col-span-3 flex flex-col gap-4">
        <h1 class="text-3xl font-bold">{{ $product->name }}</h1>
        @if ($product->image || filled(strip_tags($product->description ?? '')))
            <div class="flex flex-row w-full gap-4">
                @if ($product->image)
                    <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}" class="max-w-40">
                @endif
                @if (filled(strip_tags($product->description ?? '')))
                    <div class="max-h-28 overflow-y-auto w-full">
                        <article class="prose dark:prose-invert prose-sm">
                            {!! $product->description !!}
                        </article>
                    </div>
                @endif
            </div>
        @endif
        @if ($product->availablePlans()->count() > 1)
            <x-form.select wire:model.live="plan_id" class="text-white bg-primary-800 px-2.5 py-2.5 rounded-md w-full"
                name="plan_id" label="Select a plan"
                @change="window.dispatchEvent(new CustomEvent('checkout-price-update'))">
                @foreach ($product->availablePlans() as $availablePlan)
                    @php
                        $planPrice = $availablePlan->price();
                    @endphp
                    <option value="{{ $availablePlan->id }}">
                        {{ $availablePlan->name }} -
                        {{ $planPrice->formatted->price }}
                        @if ($planPrice->has_setup_fee)
                            + {{ $planPrice->formatted->setup_fee }} {{ __('product.setup_fee') }}
                        @endif
                    </option>
                @endforeach
            </x-form.select>
        @endif

        @foreach ($this->getCheckoutConfig() as $configOption)
            @if(($configOption['name'] ?? '') === 'hostname')
                <x-form.checkout-field :field="$configOption" />
            @endif
        @endforeach

        @foreach ($product->configOptions as $configOption)
            @php
                // Pre-calculate all prices for this config option to avoid repeated DB queries
                $configOptionPrices = [];
                $hasPaidOptions = false;
                foreach ($configOption->children as $child) {
                    $childPrice = $child->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit);
                    $configOptionPrices[$child->id] = $childPrice;
                    if (!$childPrice->is_free) {
                        $hasPaidOptions = true;
                    }
                }
                $showPriceTag = $hasPaidOptions;
            @endphp
            <x-form.configoption :config="$configOption" :name="'configOptions.' . $configOption->id" :showPriceTag="$showPriceTag" :plan="$plan">
                @if ($configOption->type == 'select')
                    @foreach ($configOption->children as $configOptionValue)
                        @php
                            $price = $configOptionPrices[$configOptionValue->id];
                            $priceDisplay = ($showPriceTag && $price->available) ? ' - ' . $price : '';
                        @endphp
                        <option value="{{ $configOptionValue->id }}">
                            {{ $configOptionValue->name }}{{ $priceDisplay }}
                        </option>
                    @endforeach
                @elseif($configOption->type == 'radio')
                    @foreach ($configOption->children as $configOptionValue)
                        @php
                            $price = $configOptionPrices[$configOptionValue->id];
                            $priceDisplay = ($showPriceTag && $price->available) ? ' - ' . $price : '';
                        @endphp
                        <div class="flex items-center gap-2">
                            <input type="radio" id="{{ $configOptionValue->id }}" name="configOptions[{{ $configOption->id }}]"
                                wire:model.live="configOptions.{{ $configOption->id }}"
                                value="{{ $configOptionValue->id }}"

                                @if(($this->configOptions[$configOption->id] ?? null) == $configOptionValue->id) checked @endif />
                            <label for="{{ $configOptionValue->id }}">
                                {{ $configOptionValue->name }}{{ $priceDisplay }}
                            </label>
                        </div>
                    @endforeach
                @endif
            </x-form.configoption>
        @endforeach

        {{-- Render every ordinary provider field, including nested sections. --}}
        @foreach ($this->getCheckoutConfig() as $configOption)
            @if(!in_array($configOption['name'] ?? '', ['hostname', 'cloud_image', 'iso_image'], true))
                <x-form.checkout-field :field="$configOption" />
            @endif
        @endforeach

        @if($checkoutPricingState['hasBootMedia'])
        {{-- Cloud Image / ISO Image selection with instant client-side disabling --}}
        <div class="flex flex-col gap-4">
            {{-- Selection message --}}
            <template x-if="showSelectionMessage">
                <p class="text-sm text-red-500 font-semibold">
                    You must select either a Cloud Image or an ISO Image to continue:
                </p>
            </template>

            @foreach ($this->getCheckoutConfig() as $configOption)
                @php $configOption = (object) $configOption; @endphp
                @php
                    $isCloudImage = $configOption->name === 'cloud_image';
                    $isIsoImage = $configOption->name === 'iso_image';
                @endphp
                @if($isCloudImage)
                    @php
                        $checkoutCloudBaseLabel = $configOption->label ?? 'Cloud Image';
                        $checkoutCloudDescription = trim((string) ($configOption->description ?? ''));
                        $checkoutCloudLabel = $checkoutCloudDescription !== ''
                            ? $checkoutCloudBaseLabel . ' - ' . $checkoutCloudDescription
                            : $checkoutCloudBaseLabel;
                    @endphp
                    <div class="space-y-2">
                        <label class="text-sm text-primary-100 block">
                            <span>{{ $checkoutCloudLabel }}</span><span class="text-red-500 ml-1" x-text="cloudStar"></span>
                        </label>
                        <x-form.select
                            name="checkoutConfig.cloud_image"
                            wire:model.live="checkoutConfig.cloud_image"
                            :placeholder="$configOption->placeholder ?? ''"
                            x-bind:disabled="cloudImageDisabled"
                            @change="setCheckoutConfig('cloud_image', $event.target.value)"
                            class="cloud-image-select checkout-image-select">
                            @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                                <option value="{{ $configOptionValue }}">
                                    {{ $configOptionValueName }}
                                </option>
                            @endforeach
                        </x-form.select>
                    </div>
                @elseif($isIsoImage)
                    @php
                        $checkoutIsoBaseLabel = $configOption->label ?? 'ISO Image (QEMU)';
                        $checkoutIsoDescription = trim((string) ($configOption->description ?? ''));
                        $checkoutIsoLabel = $checkoutIsoDescription !== ''
                            ? $checkoutIsoBaseLabel . ' - ' . $checkoutIsoDescription
                            : $checkoutIsoBaseLabel;
                    @endphp
                    <div class="space-y-2">
                        <label class="text-sm text-primary-100 block">
                            <span>{{ $checkoutIsoLabel }}</span><span class="text-red-500 ml-1" x-text="isoStar"></span>
                        </label>
                        <x-form.select
                            name="checkoutConfig.iso_image"
                            wire:model.live="checkoutConfig.iso_image"
                            :placeholder="$configOption->placeholder ?? ''"
                            x-bind:disabled="isoImageDisabled"
                            @change="setCheckoutConfig('iso_image', $event.target.value)"
                            class="iso-image-select checkout-image-select">
                            @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                                <option value="{{ $configOptionValue }}">
                                    {{ $configOptionValueName }}
                                </option>
                            @endforeach
                        </x-form.select>
                    </div>
                @endif
            @endforeach
        </div>
        @endif
    </div>
</div>
