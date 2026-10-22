@php $plans = $this->product->availablePlans(); @endphp
@if($plans->count() > 1)
    <x-form.select wire:model.live="plan_id" name="plan_id" :label="__('Registration period')">
        @foreach($plans as $availablePlan)
            <option value="{{ $availablePlan->id }}">{{ $availablePlan->name }} — {{ $availablePlan->price()->formatted->price }}</option>
        @endforeach
    </x-form.select>
@else
    <div><p class="mb-2 text-sm">{{ __('Registration period') }}</p><p class="checkout-static-field">{{ $this->plan->name }}</p></div>
@endif
