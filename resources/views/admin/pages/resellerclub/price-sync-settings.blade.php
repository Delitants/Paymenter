<x-filament-panels::page>
    {{ $this->form }}
    <x-filament::section heading="Catalog Sync Status">
        @php($settings = \App\Console\Commands\ResellerClubSyncPrices::settings())
        <dl class="grid gap-4 md:grid-cols-3">
            <div><dt>Last successful sync</dt><dd>{{ $settings['last_sync'] ?? 'Never' }}</dd></div>
            <div><dt>TLDs in last sync</dt><dd>{{ $settings['last_count'] ?? '0' }}</dd></div>
            <div><dt>Status</dt><dd>{{ $settings['last_status'] ?? 'Not run' }}</dd></div>
        </dl>
        @if(!empty($settings['last_error']))
            <p class="mt-4">{{ $settings['last_error'] }}</p>
        @endif
        <p class="mt-4">Automatic syncing uses Paymenter's existing scheduler. Runs are checked hourly from 03:00 in the application timezone, on the selected day, until a sync succeeds. Monthly syncing runs on the first day of the month.</p>
        <p class="mt-4">New extensions are added as hidden, out-of-stock draft products. Registration, renewal and transfer prices are retained separately. Existing customer service amounts are preserved. Enable domain sales only after checkout and registrar provisioning have been verified.</p>
    </x-filament::section>
</x-filament-panels::page>
