<x-filament-panels::page>
    <div class="space-y-6">
        @if(empty($credentials))
            <x-filament::section>
                <x-slot name="heading">
                    <x-filament::icon icon="ri-error-warning-line" class="w-6 h-6 text-warning-500" />
                    No Credentials Found
                </x-slot>
                <p class="text-gray-500 dark:text-gray-400">
                    No credentials are stored for this service. The VM may not have been provisioned yet, or it doesn't use cloud-init.
                </p>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">
                    <x-filament::icon icon="ri-key-2-line" class="w-6 h-6 text-success-500" />
                    Cloud-Init Credentials
                </x-slot>
                <div class="space-y-4">
                    @if(isset($credentials['cloud_init_password']))
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Root Password</p>
                                <p class="text-lg font-mono text-gray-900 dark:text-gray-100">
                                    {{ $credentials['cloud_init_password'] }}
                                </p>
                            </div>
                            <x-filament::button
                                color="gray"
                                size="sm"
                                icon="ri-file-copy-line"
                                x-on:click="navigator.clipboard.writeText('{{ $credentials['cloud_init_password'] }}')"
                            >
                                Copy
                            </x-filament::button>
                        </div>
                    @endif

                    @if(isset($credentials['assigned_ipv4']))
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">IPv4 Address</p>
                                <p class="text-lg font-mono text-gray-900 dark:text-gray-100">
                                    {{ $credentials['assigned_ipv4'] }}
                                </p>
                            </div>
                            <x-filament::button
                                color="gray"
                                size="sm"
                                icon="ri-file-copy-line"
                                x-on:click="navigator.clipboard.writeText('{{ $credentials['assigned_ipv4'] }}')"
                            >
                                Copy
                            </x-filament::button>
                        </div>
                    @endif

                    @if(isset($credentials['assigned_ipv6']))
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">IPv6 Address</p>
                                <p class="text-lg font-mono text-gray-900 dark:text-gray-100">
                                    {{ $credentials['assigned_ipv6'] }}
                                </p>
                            </div>
                            <x-filament::button
                                color="gray"
                                size="sm"
                                icon="ri-file-copy-line"
                                x-on:click="navigator.clipboard.writeText('{{ $credentials['assigned_ipv6'] }}')"
                            >
                                Copy
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">
                    <x-filament::icon icon="ri-server-line" class="w-6 h-6 text-primary-500" />
                    Proxmox VM Information
                </x-slot>
                <div class="space-y-4">
                    @if(isset($credentials['proxmox_vm_id']))
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">VM ID</p>
                                <p class="text-lg font-mono text-gray-900 dark:text-gray-100">
                                    {{ $credentials['proxmox_vm_id'] }}
                                </p>
                            </div>
                            <x-filament::button
                                color="gray"
                                size="sm"
                                icon="ri-file-copy-line"
                                x-on:click="navigator.clipboard.writeText('{{ $credentials['proxmox_vm_id'] }}')"
                            >
                                Copy
                            </x-filament::button>
                        </div>
                    @endif

                    @if(isset($credentials['proxmox_node']))
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Proxmox Node</p>
                                <p class="text-lg font-mono text-gray-900 dark:text-gray-100">
                                    {{ $credentials['proxmox_node'] }}
                                </p>
                            </div>
                            <x-filament::button
                                color="gray"
                                size="sm"
                                icon="ri-file-copy-line"
                                x-on:click="navigator.clipboard.writeText('{{ $credentials['proxmox_node'] }}')"
                            >
                                Copy
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">
                <x-filament::icon icon="ri-information-line" class="w-6 h-6 text-info-500" />
                Additional Information
            </x-slot>
            <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                <p><strong>Username:</strong>
                    @if($record->product->settings->where('key', 'iso_image')->first()?->value)
                        {{ Str::contains($record->product->settings->where('key', 'iso_image')->first()->value, 'ubuntu') ? 'ubuntu' : 'root' }}
                    @else
                        root
                    @endif
                </p>
                <p><strong>Hostname:</strong> vm{{ $credentials['proxmox_vm_id'] ?? 'N/A' }}</p>
                <p><strong>Product:</strong> {{ $record->product->name }}</p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
