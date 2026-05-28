<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">
            Last Sync Status
        </x-slot>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="p-4 bg-gray-50 rounded-lg">
                <div class="text-sm text-gray-500">Last Sync</div>
                <div class="text-lg font-semibold">
                    {{ \App\Models\Setting::where('key', 'resellerclub.last_sync')->first()?->value ?? 'Never' }}
                </div>
            </div>

            <div class="p-4 bg-gray-50 rounded-lg">
                <div class="text-sm text-gray-500">Products Synced</div>
                <div class="text-lg font-semibold">
                    {{ \App\Models\Product::where('name', 'LIKE', 'Domain%')->count() }}
                </div>
            </div>

            <div class="p-4 bg-gray-50 rounded-lg">
                <div class="text-sm text-gray-500">Next Scheduled Sync</div>
                <div class="text-lg font-semibold">
                    @php
                        $enabled = \App\Models\Setting::where('key', 'resellerclub.sync_enabled')->first()?->value === '1';
                        $frequency = \App\Models\Setting::where('key', 'resellerclub.sync_frequency')->first()?->value ?? 'weekly';
                        $day = \App\Models\Setting::where('key', 'resellerclub.sync_day')->first()?->value ?? 'monday';
                    @endphp

                    @if($enabled)
                        @if($frequency === 'daily')
                            Tomorrow
                        @elseif($frequency === 'weekly')
                            Next {{ ucfirst($day) }}
                        @elseif($frequency === 'monthly')
                            1st of next month
                        @endif
                    @else
                        Disabled
                    @endif
                </div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            Cron Job Setup
        </x-slot>

        <div class="space-y-4">
            <p class="text-sm text-gray-600">
                To enable automatic weekly price sync, add the following cron job to your server:
            </p>

            <div class="bg-gray-900 text-gray-100 p-4 rounded-lg font-mono text-sm overflow-x-auto">
                0 3 * * 1 cd /var/www/paymenter && php artisan resellerclub:sync-prices --markup={{ \App\Models\Setting::where('key', 'resellerclub.markup_percentage')->first()?->value ?? '0' }} >> /var/www/paymenter/storage/logs/resellerclub-sync.log 2>&1
            </div>

            <p class="text-sm text-gray-500">
                This runs every Monday at 3:00 AM. Adjust the schedule as needed.
            </p>

            <p class="text-sm text-yellow-600">
                <strong>Note:</strong> Make sure your web server user has permission to run artisan commands.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
