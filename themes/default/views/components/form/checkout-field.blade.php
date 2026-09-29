@props(['field'])
@php $field = (object) $field; @endphp
@if($field->type === 'section')
    <fieldset class="flex flex-col gap-4 border-2 border-neutral rounded-md p-3">
        <legend>{{ $field->label ?? $field->name }}</legend>
        @if(!empty($field->description))<p class="text-sm">{{ $field->description }}</p>@endif
        @foreach($field->fields ?? [] as $child)
            <x-form.checkout-field :field="$child" />
        @endforeach
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
@endif
