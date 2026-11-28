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

        <form method="get" action="{{ $previewUrl }}" class="my-4 flex flex-wrap items-end gap-2">
            <input type="hidden" name="dataset" value="{{ $dataset }}">
            <label for="billmanager-preview-search">
                <span>Search {{ strtolower($datasets[$dataset]) }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input id="billmanager-preview-search" name="q" :value="$search" maxlength="100" />
                </x-filament::input.wrapper>
            </label>
            <x-filament::button type="submit">Search</x-filament::button>
        </form>

        @if ($records === null)
            <p>The copy is temporarily unavailable.</p>
        @elseif ($records->isEmpty())
            <p>No matching copied records.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            @foreach ($columns as $label)
                                <th scope="col" class="px-3 py-2 text-start">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                @foreach ($columns as $field => $label)
                                    <td class="px-3 py-2">{{ $record->{$field} ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex gap-2">
                @if ($records->previousPageUrl())
                    <x-filament::button tag="a" color="gray" :href="$records->previousPageUrl()">Previous</x-filament::button>
                @endif
                @if ($records->nextPageUrl())
                    <x-filament::button tag="a" color="gray" :href="$records->nextPageUrl()">Next</x-filament::button>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
