<div class="container mt-14">
    <a href="{{ route('services.show', $service) }}" class="text-primary underline">{{ __('Back to service') }}</a>
    <section class="bg-background-secondary border border-neutral rounded-lg p-6 mt-4">
        <h1 class="text-2xl font-semibold">{{ __('VM credentials') }}</h1>
        <p class="mt-2">{{ $service->label }}</p>
        @if(empty($credentials))
            <p class="mt-4">{{ __('No VM credentials have been saved for this service.') }}</p>
        @else
            <dl class="grid gap-4 mt-4">
                @foreach(['cloud_init_username' => 'SSH username', 'assigned_ipv4_list' => 'IPv4 addresses', 'assigned_ipv4' => 'Primary IPv4 address', 'assigned_ipv4_private' => 'Private IPv4 address', 'assigned_ipv6' => 'IPv6 address', 'proxmox_vm_id' => 'VM ID', 'proxmox_node' => 'Proxmox node'] as $key => $label)
                    @if(isset($credentials[$key]))
                    <div>
                        <dt class="font-semibold">{{ __($label) }}</dt>
                        <dd class="break-all font-mono">{{ $credentials[$key] }}</dd>
                    </div>
                    @endif
                @endforeach
            </dl>
            @if(isset($credentials['cloud_init_password']))
            <details class="mt-4">
                <summary class="cursor-pointer text-primary">{{ __('Show saved password') }}</summary>
                <p class="font-mono break-all mt-2">{{ $credentials['cloud_init_password'] }}</p>
                <p>{{ __('A password changed inside the VM may differ from this saved password.') }}</p>
            </details>
            @endif
        @endif
    </section>
</div>
