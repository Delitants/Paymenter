@php
    // Pre-calculate config option prices for client-side calculation
    $configPriceMap = [];
    foreach ($this->product->configOptions as $option) {
        foreach ($option->children as $child) {
            $childPrice = $child->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit);
            $configPriceMap[$child->id] = [
                'price' => (int) round($childPrice->price * 100),
                'setup_fee' => (int) round($childPrice->setup_fee * 100)
            ];
        }
    }
    // Add checkout config prices (for boxed radio groups like IP addresses)
    $checkoutConfigPrices = [];
    foreach ($this->getCheckoutConfig() as $config) {
        // Handle section type with nested fields
        if (isset($config['type']) && $config['type'] === 'section' && isset($config['fields'])) {
            foreach ($config['fields'] as $field) {
                if (isset($field['prices']) && is_array($field['prices'])) {
                    foreach ($field['prices'] as $value => $priceCents) {
                        $checkoutConfigPrices[$field['name'] . '_' . $value] = $priceCents;
                    }
                }
            }
        }
        // Handle simple priced fields (backward compatibility)
        elseif (isset($config['prices']) && is_array($config['prices'])) {
            foreach ($config['prices'] as $value => $priceCents) {
                $checkoutConfigPrices[$config['name'] . '_' . $value] = $priceCents;
            }
        }
    }
    $taxSettings = \App\Classes\Settings::tax();
    $taxName = is_object($taxSettings) ? $taxSettings->name : 'Tax';
    $taxRateDisplay = is_object($taxSettings) ? $taxSettings->rate : 0;
    $planPrice = $plan->price();
    $selectedConfigCents = 0;
    foreach ($this->configOptions as $selectedId) {
        $selectedConfigCents += ($configPriceMap[$selectedId]['price'] ?? 0) + ($configPriceMap[$selectedId]['setup_fee'] ?? 0);
    }
    $selectedCheckoutCents = 0;
    foreach ($this->checkoutConfig as $name => $value) {
        $normalizedValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $selectedCheckoutCents += $checkoutConfigPrices[$name . '_' . $normalizedValue] ?? 0;
    }
    $displayRecurringCents = (int) round($planPrice->price * 100) + $selectedConfigCents + $selectedCheckoutCents;
    $displayTotalCents = $displayRecurringCents + (int) round($planPrice->setup_fee * 100);
    $formatCheckoutCents = function ($cents) use ($planPrice) {
        $format = $planPrice->currency->format ?? '1,000.00';
        $amount = $cents / 100;

        $formatted = match ($format) {
            '1.000,00' => number_format($amount, 2, ',', '.'),
            '1 000,00' => number_format($amount, 2, ',', ' '),
            '1 000.00' => number_format($amount, 2, '.', ' '),
            default => number_format($amount, 2, '.', ','),
        };

        return ($planPrice->currency->prefix ?? '') . $formatted . ($planPrice->currency->suffix ?? '');
    };
    $checkoutPricingState = [
        'basePriceCents' => (int) round($planPrice->price * 100),
        'setupFeeCents' => (int) round($planPrice->setup_fee * 100),
        'configPriceMap' => $configPriceMap,
        'checkoutConfigPrices' => $checkoutConfigPrices,
        'configOptions' => collect($this->configOptions)->mapWithKeys(fn ($value, $key) => [(string) $key => (string) $value])->toArray(),
        'checkoutConfig' => collect($this->checkoutConfig)->mapWithKeys(fn ($value, $key) => [(string) $key => is_bool($value) ? $value : (string) $value])->toArray(),
        'currency' => [
            'prefix' => $planPrice->currency->prefix ?? '',
            'suffix' => $planPrice->currency->suffix ?? '',
            'format' => $planPrice->currency->format ?? '1,000.00',
        ],
    ];
@endphp

@script
    <script>
        Alpine.data('checkoutPricing', (state) => ({
            basePriceCents: Number(state.basePriceCents || 0),
            setupFeeCents: Number(state.setupFeeCents || 0),
            configPriceMap: state.configPriceMap || {},
            checkoutConfigPrices: state.checkoutConfigPrices || {},
            configOptions: state.configOptions || {},
            checkoutConfig: state.checkoutConfig || {},
            currency: state.currency || {},

            get recurringCents() {
                return this.basePriceCents + this.configOptionsCents + this.checkoutConfigCents;
            },

            get totalCents() {
                return this.recurringCents + this.setupFeeCents;
            },

            get cloudImage() {
                return this.checkoutConfig.cloud_image || '';
            },

            get isoImage() {
                return this.checkoutConfig.iso_image || '';
            },

            get cloudImageDisabled() {
                return this.isoImage !== '';
            },

            get isoImageDisabled() {
                return this.cloudImage !== '';
            },

            get showSelectionMessage() {
                return this.checkoutConfig.vm_type !== 'lxc' && this.cloudImage === '' && this.isoImage === '';
            },

            get cloudLabel() {
                return 'Cloud Image';
            },

            get isoLabel() {
                return 'ISO Image (QEMU)';
            },

            get cloudStar() {
                return this.cloudImageDisabled ? '' : '*';
            },

            get isoStar() {
                return this.isoImageDisabled ? '' : '*';
            },

            get configOptionsCents() {
                return Object.values(this.configOptions).reduce((total, selectedId) => {
                    const price = this.configPriceMap[String(selectedId)];

                    return total + Number(price?.price || 0) + Number(price?.setup_fee || 0);
                }, 0);
            },

            get checkoutConfigCents() {
                return Object.entries(this.checkoutConfig).reduce((total, [name, value]) => {
                    const key = `${name}_${String(value)}`;

                    return total + Number(this.checkoutConfigPrices[key] || 0);
                }, 0);
            },

            setConfigOption(optionId, value) {
                this.configOptions[String(optionId)] = String(value);
            },

            setCheckoutConfig(name, value) {
                this.checkoutConfig[String(name)] = typeof value === 'boolean' ? value : String(value);
            },

            syncFieldChange(detail) {
                if (!detail?.name) {
                    return;
                }

                const [group, key] = detail.name.split('.');
                if (group === 'configOptions' && key) {
                    this.setConfigOption(key, detail.value);
                }
                if (group === 'checkoutConfig' && key) {
                    this.setCheckoutConfig(key, detail.value);
                }
            },

            formatPrice(cents) {
                const amount = Number(cents || 0) / 100;

                return `${this.currency.prefix || ''}${this.formatNumber(amount)}${this.currency.suffix || ''}`;
            },

            formatNumber(amount) {
                const format = this.currency.format || '1,000.00';
                const lastDot = format.lastIndexOf('.');
                const lastComma = format.lastIndexOf(',');
                const decimal = lastComma > lastDot ? ',' : '.';
                const thousands = format.includes(' ') ? ' ' : (decimal === ',' ? '.' : ',');
                const [whole, fraction] = amount.toFixed(2).split('.');

                return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousands)}${decimal}${fraction}`;
            },
        }));
    </script>
@endscript

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

	<div class="container mt-14 grid grid-cols-1 md:grid-cols-4 gap-8"
    x-data="checkoutPricing(@js($checkoutPricingState))"
    @checkout-field-change="syncFieldChange($event.detail)">
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
        <div class="text-lg font-semibold flex justify-between gap-3" wire:ignore>
            <h4>{{ __('product.total_today') }}:</h4>
            <span x-text="formatPrice(totalCents)">{{ $formatCheckoutCents($displayTotalCents) }}</span>
        </div>
        @if ($total->setup_fee > 0 && $plan->type == 'recurring')
            <div class="mt-2 text-sm font-semibold flex justify-between gap-3" wire:ignore>
                <h4>{{ __('product.then_after_x', ['time' => $plan->billing_period . ' ' . trans_choice(__('services.billing_cycles.' . $plan->billing_unit), $plan->billing_period)]) }}:
                </h4> <span x-text="formatPrice(recurringCents)">{{ $formatCheckoutCents($displayRecurringCents) }}</span>
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

        {{-- Hostname field - moved to top --}}
        @foreach ($this->getCheckoutConfig() as $configOption)
            @php $configOption = (object) $configOption; @endphp
            @if($configOption->name === 'hostname')
                <div>
                    <x-form.configoption :config="$configOption" :name="'checkoutConfig.' . $configOption->name">
                    @if ($configOption->type == 'select')
                        @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                            <option value="{{ $configOptionValue }}">
                                {{ $configOptionValueName }}
                            </option>
                        @endforeach
                    @elseif($configOption->type == 'text')
                        <input type="text" id="{{ $configOption->name }}" name="{{ $configOption->name }}"
                            wire:model.live="checkoutConfig.{{ $configOption->name }}"
                            class="block px-3 py-3 w-full text-sm text-primary-100 bg-background-secondary border-2 border-neutral rounded-md outline-none focus:outline-none focus:border-secondary transition-all duration-300 ease-in-out"
                            placeholder="{{ $configOption->placeholder ?? '' }}" />
	                    @endif
	                </x-form.configoption>
	                </div>
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
                                @change="setConfigOption('{{ $configOption->id }}', $event.target.value)"
                                @if(($this->configOptions[$configOption->id] ?? null) == $configOptionValue->id) checked @endif />
                            <label for="{{ $configOptionValue->id }}">
                                {{ $configOptionValue->name }}{{ $priceDisplay }}
                            </label>
                        </div>
                    @endforeach
                @endif
            </x-form.configoption>
        @endforeach

        {{-- IP Addresses Section - before Cloud/ISO Image --}}
        @foreach ($this->getCheckoutConfig() as $configOption)
            @php $configOption = (object) $configOption; @endphp
            @if(isset($configOption->type) && $configOption->type === 'section' && isset($configOption->fields))
                @php
                    $checkoutSectionBaseLabel = $configOption->label ?? 'IP Addresses';
                    $checkoutSectionDescription = trim((string) ($configOption->description ?? ''));
                    $checkoutSectionLabel = $checkoutSectionDescription !== ''
                        ? $checkoutSectionBaseLabel . ' - ' . $checkoutSectionDescription
                        : $checkoutSectionBaseLabel;
                @endphp
                <div>
                    <label class="mb-2 text-sm text-primary-100 block">
                        {{ $checkoutSectionLabel }}
                    </label>
                    <div class="block px-3 py-2.5 w-full text-sm text-primary-100 bg-background-secondary border-2 border-neutral rounded-md outline-none focus:outline-none focus:border-secondary transition-all duration-300 ease-in-out">
                        @foreach ($configOption->fields as $field)
                            @if($field['type'] === 'radio')
                                <div class="space-y-1.5 mb-2.5 last:mb-0">
                                    @foreach ($field['options'] as $fieldValue => $fieldLabel)
                                        @php
                                            $fieldPrice = $field['prices'][$fieldValue] ?? 0;
                                            $priceDisplay = $fieldPrice > 0 ? ' - $' . ($fieldPrice / 100) . '/month' : '';
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            <input type="radio" id="checkoutConfig.{{ $field['name'] }}_{{ $fieldValue }}"
                                                name="checkoutConfig.{{ $field['name'] }}"
                                                value="{{ $fieldValue }}"
                                                wire:model.live="checkoutConfig.{{ $field['name'] }}"
                                                @change="setCheckoutConfig('{{ $field['name'] }}', $event.target.value)"
                                                @if(($this->checkoutConfig[$field['name']] ?? $field['default']) == $fieldValue) checked @endif
                                                class="text-secondary focus:ring-secondary" />
                                            <label for="checkoutConfig.{{ $field['name'] }}_{{ $fieldValue }}" class="cursor-pointer text-sm">
                                                {{ $fieldLabel }}
                                                <span class="text-primary-500 text-xs">{{ $priceDisplay }}</span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            @elseif($field['type'] === 'checkbox')
                                <div class="mt-2 flex items-center gap-2 border-t border-neutral pt-2.5">
                                    <input type="checkbox" id="checkoutConfig.{{ $field['name'] }}"
                                        wire:model.live="checkoutConfig.{{ $field['name'] }}"
                                        @change="setCheckoutConfig('{{ $field['name'] }}', $event.target.checked)"
                                        class="text-secondary focus:ring-secondary" />
                                    <label for="checkoutConfig.{{ $field['name'] }}" class="cursor-pointer text-sm">
                                        {{ $field['label'] ?? $field['name'] }}
                                    </label>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach

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
    </div>
</div>
