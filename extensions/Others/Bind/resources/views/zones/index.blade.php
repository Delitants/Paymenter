<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb />
    <h1 class="text-2xl font-semibold">{{ __('DNS zones') }}</h1>
    @forelse ($zones as $zone)
        <a href="{{ route('bind.zones.show', $zone) }}" wire:navigate class="block bg-background-secondary border border-neutral rounded-lg p-4 hover:bg-background-secondary/80">
            <span class="font-medium break-all">{{ $zone->zone }}</span>
            <span class="block text-sm mt-1">{{ $zone->service->label }}</span>
        </a>
    @empty
        <p class="bg-background-secondary border border-neutral rounded-lg p-4">{{ __('No DNS zones are available for this account.') }}</p>
    @endforelse
</div>
