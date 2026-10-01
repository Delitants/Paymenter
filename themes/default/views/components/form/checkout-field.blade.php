@props(['field', 'number' => null])
@php $field = (object) $field; @endphp
@if($field->type === 'section')
    <fieldset class="checkout-section">
        <legend class="checkout-section-title">@if($number)<span class="checkout-step" aria-hidden="true">{{ $number }}</span>@endif {{ __($field->label ?? $field->name) }}</legend>
        @if(!empty($field->description))<p class="text-sm">{{ $field->description }}</p>@endif
        <div class="checkout-section-fields {{ ($field->columns ?? 1) === 2 ? 'checkout-fields-two' : '' }}">
        @foreach($field->fields ?? [] as $child)
            <div @class(['checkout-field-wide' => $child['wide'] ?? false])><x-form.checkout-field :field="$child" /></div>
        @endforeach
        @if($field->include_plan ?? false)
            <x-form.checkout-plan />
        @endif
        </div>
    </fieldset>
@elseif(!empty($field->suffix))
    <fieldset class="flex flex-col w-full" x-data="{ full: $wire.entangle('checkoutConfig.{{ $field->name }}').live, suffix: @js($field->suffix), get label() { const value = this.full || ''; return value.endsWith(this.suffix) ? value.slice(0, -this.suffix.length) : value; } }">
        <label for="checkoutConfig.{{ $field->name }}" class="mb-2 text-sm">{{ __($field->label) }} <span class="text-red-500">*</span></label>
        <div class="checkout-domain-input">
            <input type="text" id="checkoutConfig.{{ $field->name }}" name="checkoutConfig.{{ $field->name }}" :value="label"
                @input="full = $event.target.value ? $event.target.value + suffix : ''" required autocomplete="off" spellcheck="false" maxlength="63"
                aria-describedby="checkout-zone-{{ $field->name }}" placeholder="{{ __('your-domain') }}" />
            <span id="checkout-zone-{{ $field->name }}" data-domain-zone>{{ $field->suffix }}</span>
        </div>
        @error('checkoutConfig.' . $field->name)<p class="mt-1.5 text-red-500 text-xs">{{ $message }}</p>@enderror
    </fieldset>
@else
    <x-form.configoption :config="$field" :showPriceTag="false" :name="'checkoutConfig.' . $field->name">
        @if($field->type === 'select')
            @foreach($field->options ?? [] as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        @elseif($field->type === 'radio')
            @foreach($field->options ?? [] as $value => $label)
                <label class="flex gap-2 items-center">
                    <input type="radio" name="checkoutConfig.{{ $field->name }}" value="{{ $value }}"
                        wire:model.live="checkoutConfig.{{ $field->name }}"
                        @change="setCheckoutConfig(@js($field->name), $event.target.value)" />
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        @endif
    </x-form.configoption>
    @if(!empty($field->agreement_url))
        <a href="{{ $field->agreement_url }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-sm text-primary underline">{{ __('Read the domain registration terms and privacy policy') }}</a>
    @endif
@endif
