<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">
            Cloudflare IP Ranges Status
        </x-slot>

        <div class="grid gap-4 md:grid-cols-4">
            @php
                $service = app(\App\Services\CloudflareService::class);
                $counts = $service->getRangesCount();
                $lastUpdate = $service->getLastUpdate();
            @endphp

            <div class="p-4 bg-gray-50 rounded-lg">
                <div class="text-sm text-gray-500">IPv4 Ranges</div>
                <div class="text-lg font-semibold">{{ $counts['ipv4_count'] }}</div>
            </div>

            <div class="p-4 bg-gray-50 rounded-lg">
                <div class="text-sm text-gray-500">IPv6 Ranges</div>
                <div class="text-lg font-semibold">{{ $counts['ipv6_count'] }}</div>
            </div>

            <div class="p-4 bg-gray-50 rounded-lg">
                <div class="text-sm text-gray-500">Total Ranges</div>
                <div class="text-lg font-semibold">{{ $counts['total'] }}</div>
            </div>

            <div class="p-4 bg-gray-50 rounded-lg">
                <div class="text-sm text-gray-500">Last Updated</div>
                <div class="text-lg font-semibold">{{ $lastUpdate ?? 'Never' }}</div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            Cloudflare IP Ranges
        </x-slot>

        <div class="space-y-4">
            <div>
                <h4 class="font-semibold mb-2">IPv4 Ranges</h4>
                <div class="bg-gray-900 text-gray-100 p-4 rounded-lg font-mono text-xs overflow-x-auto max-h-48 overflow-y-auto">
                    @php
                        $ranges = $service->getIpRanges();
                    @endphp
                    @foreach($ranges['ipv4'] as $range)
                        <div>{{ $range }}</div>
                    @endforeach
                </div>
            </div>

            <div>
                <h4 class="font-semibold mb-2">IPv6 Ranges</h4>
                <div class="bg-gray-900 text-gray-100 p-4 rounded-lg font-mono text-xs overflow-x-auto max-h-48 overflow-y-auto">
                    @foreach($ranges['ipv6'] as $range)
                        <div>{{ $range }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            Web Server Configuration Status
        </x-slot>

        <div class="space-y-4">
            @php
                $webServerService = app(\App\Services\WebServerConfigService::class);
                $status = $webServerService->getConfigStatus();
            @endphp

            <div class="grid gap-4 md:grid-cols-3">
                <div class="p-4 bg-gray-50 rounded-lg">
                    <div class="text-sm text-gray-500">Web Server</div>
                    <div class="text-lg font-semibold">
                        @if($status['server_type'])
                            <span class="uppercase">{{ $status['server_type'] }}</span>
                        @else
                            <span class="text-gray-400">Not detected</span>
                        @endif
                    </div>
                </div>

                <div class="p-4 bg-gray-50 rounded-lg">
                    <div class="text-sm text-gray-500">Cloudflare Config</div>
                    <div class="text-lg font-semibold">
                        @if($status['config_exists'])
                            <span class="text-green-600">Installed</span>
                        @else
                            <span class="text-gray-400">Not installed</span>
                        @endif
                    </div>
                </div>

                <div class="p-4 bg-gray-50 rounded-lg">
                    <div class="text-sm text-gray-500">Last Updated</div>
                    <div class="text-lg font-semibold">
                        {{ $status['last_updated'] ?? 'Never' }}
                    </div>
                </div>
            </div>

            @if($status['config_exists'])
                <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                    <p class="text-sm text-green-700">
                        <strong>Auto-configured:</strong> Web server configuration is automatically updated when you save settings.
                        The config file is located at: <code class="bg-green-100 px-2 py-1 rounded">{{ $status['config_path'] }}</code>
                    </p>
                </div>
            @else
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <p class="text-sm text-yellow-700">
                        <strong>Manual configuration required:</strong> Web server config was not automatically created.
                        You may need to apply it manually (see below) or ensure the web server user has write permissions.
                    </p>
                </div>
            @endif

            <div class="mt-4">
                <h4 class="font-semibold mb-2">Manual Configuration (if needed)</h4>

                @if($status['server_type'] === 'nginx')
                    <div class="bg-gray-900 text-gray-100 p-4 rounded-lg font-mono text-xs overflow-x-auto">
<pre>
# Add to /etc/nginx/conf.d/cloudflare.conf
# Then: systemctl reload nginx

@php
    $ranges = $service->getIpRanges();
@endphp
@foreach($ranges['ipv4'] as $range)
set_real_ip_from {{ $range }};
@endforeach
@foreach($ranges['ipv6'] as $range)
set_real_ip_from {{ $range }};
@endforeach

real_ip_header CF-Connecting-IP;
</pre>
                    </div>
                @elseif($status['server_type'] === 'apache')
                    <div class="bg-gray-900 text-gray-100 p-4 rounded-lg font-mono text-xs overflow-x-auto">
<pre>
# Add to /etc/apache2/conf.d/cloudflare.conf
# Requires: a2enmod remoteip
# Then: systemctl reload apache2

<IfModule mod_remoteip.c>
    RemoteIPHeader CF-Connecting-IP
@php
    $ranges = $service->getIpRanges();
@endphp
@foreach($ranges['ipv4'] as $range)
    RemoteIPInternalProxy {{ $range }}
@endforeach
@foreach($ranges['ipv6'] as $range)
    RemoteIPInternalProxy {{ $range }}
@endforeach
</IfModule>
</pre>
                    </div>
                @else
                    <p class="text-sm text-gray-500">Web server type could not be detected. Please configure manually based on your server type.</p>
                @endif
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            Additional Cloudflare Features
        </x-slot>

        <div class="space-y-4">
            <p class="text-sm text-gray-600">
                These features require additional Cloudflare API integration.
            </p>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="p-4 border rounded-lg">
                    <h4 class="font-semibold mb-2">DNS Management</h4>
                    <p class="text-sm text-gray-500 mb-3">Automatically manage DNS records for customer domains</p>
                    <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded">Coming Soon</span>
                </div>

                <div class="p-4 border rounded-lg">
                    <h4 class="font-semibold mb-2">SSL/TLS Automation</h4>
                    <p class="text-sm text-gray-500 mb-3">Auto-provision Edge Certificates for domains</p>
                    <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded">Coming Soon</span>
                </div>

                <div class="p-4 border rounded-lg">
                    <h4 class="font-semibold mb-2">Cache Purge</h4>
                    <p class="text-sm text-gray-500 mb-3">Purge Cloudflare cache when content updates</p>
                    <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded">Coming Soon</span>
                </div>

                <div class="p-4 border rounded-lg">
                    <h4 class="font-semibold mb-2">Security Rules</h4>
                    <p class="text-sm text-gray-500 mb-3">Manage WAF rules and security settings</p>
                    <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded">Coming Soon</span>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
