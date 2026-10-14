<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $service->label }}</x-slot>
        <x-saved-service-credentials :credentials="$credentials" />
    </x-filament::section>
</x-filament-panels::page>
