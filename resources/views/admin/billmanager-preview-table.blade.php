<form method="get" action="{{ $formUrl }}" class="my-4 flex flex-wrap items-end gap-2">
    @foreach ($formFields ?? [] as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <label for="billmanager-preview-search">
        <span>{{ $searchLabel }}</span>
        <x-filament::input.wrapper>
            <x-filament::input id="billmanager-preview-search" :name="$searchName" :value="$search" maxlength="100" />
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
