@php
    $taxSettings = \App\Classes\Settings::tax();
    $taxName = is_object($taxSettings) ? $taxSettings->name : 'Tax';
    $taxRateDisplay = is_object($taxSettings) ? $taxSettings->rate : 0;
    if (str_contains((string) $taxRateDisplay, '.')) {
        $taxRateDisplay = rtrim(rtrim((string) $taxRateDisplay, '0'), '.');
    }
    $checkoutFields = $this->getCheckoutConfig();
    $checkoutPresentation = collect($checkoutFields)->first(fn ($field) => isset($field['summary'])) ?? [];
    $checkoutSummary = $checkoutPresentation['summary'] ?? null;
    $planInSection = collect($checkoutFields)->contains(fn ($field) => $field['include_plan'] ?? false);
    $checkoutSectionNumber = 0;
    $checkoutPricingState = [
        'hasBootMedia' => collect($this->getCheckoutConfig())->contains(fn ($field) => in_array($field['name'] ?? '', ['cloud_image', 'iso_image'], true)),
    ];
@endphp

@script
<script>
    // Only presentation dependencies are local; all displayed prices come from Price.
    Alpine.data('checkoutPricing', (checkoutConfig, hasBootMedia) => ({
        checkoutConfig,
        hasBootMedia,
        init() {
            this.$watch('checkoutConfig.vm_type', (value, previous) => {
                if (value === previous) return;
                for (const name of ['cloud_image', 'iso_image', 'os_template']) {
                    if (name in this.checkoutConfig) this.checkoutConfig[name] = '';
                }
            });
        },
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

	<div class="checkout-page"
    x-data="checkoutPricing($wire.entangle('checkoutConfig'), @js($checkoutPricingState['hasBootMedia']))"
    @checkout-field-change="syncFieldChange($event.detail)">

@once
    <style>
        .checkout-page { max-width: 1120px; margin: 2rem auto 4rem; padding: 0 1.5rem; display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 2rem; align-items: start; }
        .checkout-summary-column { grid-column: 2; grid-row: 1; position: sticky; top: 6rem; }
        .checkout-summary-panel { padding: 1.5rem; border: 1px solid hsl(var(--color-neutral)); border-radius: .75rem; background: hsl(var(--color-background)); }
        .checkout-form-stack { grid-column: 1; grid-row: 1; min-width: 0; display: flex; flex-direction: column; gap: 1.5rem; }
        .checkout-section { min-width: 0; padding: 1.5rem; border: 1px solid hsl(var(--color-neutral)); border-radius: .75rem; background: hsl(var(--color-background)); }
        .checkout-section-title { float: left; width: 100%; display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; font-size: 1.125rem; font-weight: 700; }
        .checkout-step { display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 50%; background: hsl(var(--color-primary)); color: white; font-size: .875rem; }
        .checkout-section > p { clear: both; margin-bottom: 1rem; color: hsl(var(--color-muted)); line-height: 1.5; }
        .checkout-section-fields { clear: both; display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
        .checkout-fields-two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .checkout-field-wide { grid-column: 1 / -1; }
        .checkout-page label { font-weight: 600; color: hsl(var(--color-base)); }
        .checkout-page input:not([type=checkbox]), .checkout-page select, .checkout-static-field { min-height: 44px; border-width: 1px; background: hsl(var(--color-background)); padding: .625rem .75rem; }
        .checkout-static-field { border: 1px solid hsl(var(--color-neutral)); border-radius: .375rem; font-size: .875rem; }
        .checkout-domain-input { display: flex; min-width: 0; border: 1px solid hsl(var(--color-neutral)); border-radius: .375rem; overflow: hidden; }
        .checkout-domain-input input { flex: 1; min-width: 0; width: 100%; border: 0; outline-offset: -2px; }
        .checkout-domain-input span { display: flex; align-items: center; padding: .625rem .875rem; border-left: 1px solid hsl(var(--color-neutral)); background: hsl(var(--color-background-secondary)); font-size: .875rem; font-weight: 700; }
        .checkout-page input:focus-visible, .checkout-page select:focus-visible, .checkout-page button:focus-visible, .checkout-page a:focus-visible, .checkout-page summary:focus-visible { outline: 2px solid hsl(var(--color-primary)); outline-offset: 2px; }
        @media (max-width: 1023px) { .checkout-page { grid-template-columns: minmax(0, 1fr); max-width: 760px; } .checkout-summary-column { grid-column: 1; grid-row: 2; position: static; } }
        @media (max-width: 639px) { .checkout-page { margin-top: 1.5rem; padding: 0 1rem; gap: 1.5rem; } .checkout-fields-two { grid-template-columns: minmax(0, 1fr); } .checkout-section, .checkout-summary-panel { padding: 1.25rem; } }

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
    <div class="checkout-summary-column">
    <div class="checkout-summary-panel h-fit">
        <h2 class="text-xl font-bold mb-5 border-b border-neutral pb-4">
            {{ __('product.order_summary') }}
        </h2>
        @if($checkoutSummary)
            <p class="mb-1 break-all font-semibold">{{ $checkoutSummary['domain'] ?: __('Your domain') }}</p>
            <p class="mb-5 text-sm text-muted">{{ trans_choice(':count year registration|:count years registration', $checkoutSummary['years']) }}</p>
            <dl class="mb-5 space-y-3 text-sm tabular-nums">
                <div class="flex justify-between gap-3"><dt>{{ __('Registration') }}</dt><dd>{{ $total->format($checkoutSummary['registration']) }}</dd></div>
                @if((float) $checkoutSummary['privacy'] > 0)
                    <div class="flex justify-between gap-3"><dt>{{ __('WHOIS protection') }}</dt><dd data-privacy-price>{{ $total->format($checkoutSummary['privacy']) }}</dd></div>
                @endif
            </dl>
        @endif
        @if ($total->total_tax > 0)
            <div class="text-sm flex justify-between gap-3 mb-3 tabular-nums">
                <span>{{ __('invoices.subtotal') }}</span> <span>{{ $total->format($total->subtotal) }}</span>
            </div>
            <div class="text-sm flex justify-between gap-3 mb-4 tabular-nums">
                <span>{{ $taxName }} ({{ $taxRateDisplay }}%)</span> <span>{{ $total->format($total->total_tax) }}</span>
            </div>
        @endif
        <div class="font-semibold flex justify-between gap-3 border-t border-neutral pt-4 tabular-nums">
            <span>{{ __('Total before payment fee') }}</span>
            <span data-checkout-total>{{ $total->formatted->total }}</span>
        </div>
        <p class="mt-3 text-xs leading-relaxed text-muted">{{ __('Payment method and its untaxed gateway fee are selected in the cart.') }}</p>
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
                        {{ $checkoutSummary ? __('Continue to cart') : __('product.checkout') }}
                    </div>
                </x-button.primary>
            </div>
        @endif
        @if($checkoutSummary && $checkoutSummary['renewal'])
            @php $renewalPrice = new \App\Classes\Price(['price' => $checkoutSummary['renewal'], 'currency' => $total->currency], apply_exclusive_tax: true); @endphp
            <div class="mt-5 border-t border-neutral pt-4 text-sm flex justify-between gap-3 tabular-nums"><span>{{ __('Current renewal price') }}</span><span data-domain-renewal>{{ $renewalPrice->formatted->price }}</span></div>
            <p class="mt-2 text-xs leading-relaxed text-muted">{{ __('Includes product tax and selected extras. Renewal prices may change before the next invoice; future gateway fees are excluded.') }}</p>
        @endif
    </div>
    </div>

    {{-- Main form content - Left side on desktop --}}
    <div class="checkout-form-stack">
        <h1 class="text-3xl font-bold">{{ __($checkoutPresentation['checkout_title'] ?? $product->name) }}</h1>
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
        @if (!$planInSection && $product->availablePlans()->count() > 1)
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
                @php $sectionNumber = ($configOption['type'] ?? '') === 'section' ? ++$checkoutSectionNumber : null; @endphp
                <x-form.checkout-field :field="$configOption" :number="$sectionNumber" />
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
