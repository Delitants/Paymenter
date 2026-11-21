@component('layouts.app', ['title' => __('Pay with Klarna')])
    <div class="mx-auto max-w-xl px-4 py-8">
        @include('gateways.klarna::pay')
    </div>
@endcomponent
