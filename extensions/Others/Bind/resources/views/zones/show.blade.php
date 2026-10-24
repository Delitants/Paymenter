<div class="container mt-14 space-y-6">
    <x-navigation.breadcrumb />
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="min-w-0 flex-1"><h1 class="text-2xl font-semibold">{{ __('DNS records') }}</h1><p class="break-all mt-1">{{ $zone }}</p></div>
        <div class="shrink-0"><x-button.secondary wire:click="refreshRecords" wire:loading.attr="disabled">{{ __('Refresh records') }}</x-button.secondary></div>
    </div>
    @if ($nameservers)
        <div class="bg-background-secondary border border-neutral rounded-lg p-4"><h2 class="font-medium">{{ __('Authoritative nameservers') }}</h2><ul class="mt-2 space-y-1">@foreach ($nameservers as $ns)<li class="break-all">{{ $ns }}</li>@endforeach</ul></div>
    @endif
    @if ($outcome)
        <div role="status" aria-live="polite" class="bg-background-secondary border border-neutral rounded-lg p-4 space-y-3">
            <p>{{ __('bind::bind.'.match($outcome['state'] ?? '') {'committed'=>'saved','pending_replication'=>'pending','rejected'=>($outcome['code']??'')==='REVISION_CONFLICT'?'conflict':'rejected',default=>'uncertain'}, ['id'=>$lastRequestId]) }}</p>
            @if (in_array($outcome['state'] ?? '', ['pending_replication','uncertain'], true))<x-button.secondary wire:click="reconcile" wire:loading.attr="disabled">{{ __('Check update status') }}</x-button.secondary>@endif
        </div>
    @endif
    @if ($recordsUnavailable)<p role="alert">{{ __('bind::bind.unavailable') }}</p>@endif
    <div class="relative overflow-x-auto bg-background-secondary border border-neutral rounded-lg">
        <table class="w-full min-w-xl text-sm text-left"><caption class="sr-only">{{ __('Current DNS record sets') }}</caption><thead class="border-b border-neutral"><tr><th scope="col" class="p-3">{{ __('Name') }}</th><th scope="col" class="p-3">{{ __('Type') }}</th><th scope="col" class="p-3">{{ __('TTL') }}</th><th scope="col" class="p-3">{{ __('Values') }}</th>@if ($this->canEdit)<th scope="col" class="p-3"><span class="sr-only">{{ __('Actions') }}</span></th>@endif</tr></thead>
            <tbody>@forelse ($snapshot['records'] ?? [] as $index=>$record)<tr class="border-b border-neutral last:border-0"><td class="p-3 break-all">{{ $record['owner'] }}</td><td class="p-3">{{ $record['type'] }}</td><td class="p-3">{{ $record['ttl'] }}</td><td class="p-3 break-all">@foreach ($record['records'] as $value)<span class="block">{{ $value }}</span>@endforeach</td>@if ($this->canEdit)<td class="p-3"><button type="button" wire:click="edit({{ $index }})" class="text-primary underline">{{ __('Edit') }}</button></td>@endif</tr>@empty<tr><td colspan="5" class="p-4">{{ __('There are no editable records in this zone yet.') }}</td></tr>@endforelse</tbody>
        </table>
    </div>
    <p class="text-sm">{{ __('bind::bind.cache_notice') }}</p>
    @if ($this->canEdit)
        <form wire:submit="save" class="bg-background-secondary border border-neutral rounded-lg p-4 space-y-4">
            <h2 class="text-lg font-medium">{{ __('Add or replace a record set') }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4"><x-form.input name="owner" label="Name (@ for this zone)" wire:model="owner" required /><x-form.select name="type" label="Record type" :options="['A','AAAA','CNAME','MX','TXT','CAA','SRV']" wire:model="type" required /><x-form.input name="ttl" label="TTL (seconds)" type="number" min="1" max="2147483647" wire:model="ttl" required /></div>
            <x-form.textarea name="recordsText" label="Record values (one per line)" wire:model="recordsText" rows="4" />
            <p class="text-sm">{{ __('Use one address or DNS value per line. TXT values use quoted DNS text. Empty values remove this record set. SOA and nameserver records are managed by your administrator.') }}</p>
            <x-button.primary type="submit" wire:loading.attr="disabled">{{ __('Save DNS records') }}</x-button.primary>
        </form>
    @else
        <p class="text-sm">{{ __('bind::bind.readonly') }}</p>
    @endif
</div>
