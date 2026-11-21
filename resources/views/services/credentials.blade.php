<div class="container mt-14">
    <a href="{{ route('services.show', $service) }}" class="text-primary underline">{{ __('Back to service') }}</a>
    <section class="bg-background-secondary border border-neutral rounded-lg p-6 mt-4">
        <h1 class="text-2xl font-semibold">{{ __('VM credentials') }}</h1>
        <p class="mt-2">{{ $service->label }}</p>
        <x-saved-service-credentials :credentials="$credentials" />
    </section>
</div>
