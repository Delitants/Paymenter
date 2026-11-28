<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">
            This read-only copy includes historical records. BILLmanager continues billing.
        </x-slot>

        <x-filament::tabs label="Copied records">
            @foreach ($datasets as $key => $label)
                <x-filament::tabs.item tag="a" :href="url()->query($previewUrl, ['dataset' => $key])" :active="$key === $dataset">
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        @include('admin.billmanager-preview-table', [
            'formUrl' => $previewUrl,
            'formFields' => ['dataset' => $dataset],
            'searchLabel' => 'Search '.strtolower($datasets[$dataset]),
            'searchName' => 'q',
        ])
    </x-filament::section>
</x-filament-panels::page>
