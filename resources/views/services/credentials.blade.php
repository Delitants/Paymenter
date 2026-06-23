@extends('layouts.app')

@section('title', 'VM Credentials')

@section('content')
<div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
    <div class="space-y-6">
        <!-- Back button -->
        <div class="flex items-center gap-2">
            <a href="{{ route('services.show', $service) }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
                &larr; Back to Service
            </a>
        </div>

        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            VM Credentials - {{ $service->product->name }}
        </h1>

        @if(empty($credentials))
            <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-6">
                <div class="flex items-start gap-3">
                    <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div>
                        <h3 class="text-lg font-medium text-yellow-800 dark:text-yellow-400">No Credentials Found</h3>
                        <p class="mt-1 text-yellow-700 dark:text-yellow-500">
                            No credentials are stored for this service. The VM may not have been provisioned yet, or it doesn't use cloud-init.
                        </p>
                    </div>
                </div>
            </div>
        @else
            <!-- Cloud-Init Credentials Section -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                        </svg>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-white">Cloud-Init Credentials</h2>
                    </div>
                </div>
                <div class="px-6 py-4 space-y-4">
                    @if(isset($credentials['cloud_init_password']))
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Root Password</p>
                                <p class="text-lg font-mono text-gray-900 dark:text-gray-100">
                                    {{ $credentials['cloud_init_password'] }}
                                </p>
                            </div>
                            <button type="button"
                                onclick="navigator.clipboard.writeText('{{ $credentials['cloud_init_password'] }}'); alert('Password copied to clipboard!')"
                                class="px-3 py-1.5 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                                Copy
                            </button>
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
                            <button type="button"
                                onclick="navigator.clipboard.writeText('{{ $credentials['assigned_ipv4'] }}'); alert('IPv4 copied to clipboard!')"
                                class="px-3 py-1.5 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                                Copy
                            </button>
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
                            <button type="button"
                                onclick="navigator.clipboard.writeText('{{ $credentials['assigned_ipv6'] }}'); alert('IPv6 copied to clipboard!')"
                                class="px-3 py-1.5 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                                Copy
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Proxmox VM Information Section -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                        </svg>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-white">Proxmox VM Information</h2>
                    </div>
                </div>
                <div class="px-6 py-4 space-y-4">
                    @if(isset($credentials['proxmox_vm_id']))
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">VM ID</p>
                                <p class="text-lg font-mono text-gray-900 dark:text-gray-100">
                                    {{ $credentials['proxmox_vm_id'] }}
                                </p>
                            </div>
                            <button type="button"
                                onclick="navigator.clipboard.writeText('{{ $credentials['proxmox_vm_id'] }}'); alert('VM ID copied to clipboard!')"
                                class="px-3 py-1.5 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                                Copy
                            </button>
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
                            <button type="button"
                                onclick="navigator.clipboard.writeText('{{ $credentials['proxmox_node'] }}'); alert('Node copied to clipboard!')"
                                class="px-3 py-1.5 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                                Copy
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Additional Information Section -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <h2 class="text-lg font-medium text-gray-900 dark:text-white">Additional Information</h2>
                    </div>
                </div>
                <div class="px-6 py-4 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                    <p>
                        <strong>Username:</strong>
                        @php
                            $isoImage = $service->product->settings->where('key', 'iso_image')->first()?->value;
                        @endphp
                        @if($isoImage && str_contains(strtolower($isoImage), 'ubuntu'))
                            ubuntu
                        @else
                            root
                        @endif
                    </p>
                    <p><strong>Hostname:</strong> vm{{ $credentials['proxmox_vm_id'] ?? 'N/A' }}</p>
                    <p><strong>Product:</strong> {{ $service->product->name }}</p>
                </div>
            </div>

            <!-- Regenerate Password Button -->
            <div class="flex justify-end">
                <button type="button"
                    wire:click="regeneratePassword"
                    class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Regenerate Password
                </button>
            </div>
        @endif
    </div>
</div>
@endsection
