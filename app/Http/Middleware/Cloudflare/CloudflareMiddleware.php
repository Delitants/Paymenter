<?php

namespace App\Http\Middleware\Cloudflare;

use App\Models\Setting;
use App\Services\CloudflareService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CloudflareMiddleware
{
    protected CloudflareService $cloudflare;

    public function __construct(CloudflareService $cloudflare)
    {
        $this->cloudflare = $cloudflare;
    }

    /**
     * Handle incoming request
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only process if Cloudflare is enabled
        if (!$this->isCloudflareEnabled()) {
            return $next($request);
        }

        // Restore real IP
        $this->restoreRealIp();

        // Check IP whitelist if enabled
        if ($this->isWhitelistEnabled()) {
            $check = $this->checkWhitelist($request->ip());

            if (!$check['allowed']) {
                return response()->view('errors.403', [
                    'message' => $check['reason'],
                ], 403);
            }
        }

        return $next($request);
    }

    /**
     * Check if Cloudflare integration is enabled
     */
    protected function isCloudflareEnabled(): bool
    {
        return Setting::where('key', 'cloudflare_enabled')->first()?->value === '1';
    }

    /**
     * Check if IP whitelist is enabled
     */
    protected function isWhitelistEnabled(): bool
    {
        return Setting::where('key', 'cloudflare_whitelist_enabled')->first()?->value === '1';
    }

    /**
     * Restore real client IP from Cloudflare headers
     */
    protected function restoreRealIp(): void
    {
        $realIp = $this->cloudflare->getRealIp();

        if ($realIp) {
            $_SERVER['REMOTE_ADDR'] = $realIp;
            $_SERVER['REMOTE_HOST'] = gethostbyaddr($realIp) ?: $realIp;
        }
    }

    /**
     * Check if IP is allowed through whitelist
     */
    protected function checkWhitelist(string $ip): array
    {
        // Always allow Cloudflare IPs
        if ($this->cloudflare->isCloudflareIp($ip)) {
            return [
                'allowed' => true,
                'reason' => null,
            ];
        }

        // Check custom whitelist
        $whitelist = Setting::where('key', 'cloudflare_custom_whitelist')->first()?->value ?? '';
        $whitelistIps = array_filter(array_map('trim', explode("\n", $whitelist)));

        foreach ($whitelistIps as $allowedIp) {
            if (empty($allowedIp) || str_starts_with($allowedIp, '#')) {
                continue;
            }

            // Check if allowed IP matches
            if ($this->ipMatches($ip, $allowedIp)) {
                return [
                    'allowed' => true,
                    'reason' => null,
                ];
            }
        }

        return [
            'allowed' => false,
            'reason' => 'Your IP address is not allowed. This site is only accessible through Cloudflare.',
        ];
    }

    /**
     * Check if IP matches allowed IP or CIDR
     */
    private function ipMatches(string $ip, string $allowedIp): bool
    {
        // Exact match
        if ($ip === $allowedIp) {
            return true;
        }

        // CIDR match
        if (str_contains($allowedIp, '/')) {
            return $this->cloudflare->isCloudflareIp($ip) || $this->ipInCidr($ip, $allowedIp);
        }

        return false;
    }

    /**
     * Check if IP is within CIDR range
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        return $this->cloudflare->isCloudflareIp($ip) ||
            (str_contains($ip, ':')
                ? $this->ipv6InCidr($ip, $cidr)
                : $this->ipv4InCidr($ip, $cidr));
    }

    /**
     * Check if IPv4 is within CIDR range
     */
    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        list($subnet, $mask) = explode('/', $cidr);

        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = ~((1 << (32 - (int)$mask)) - 1);

        return ($ip & $mask) === ($subnet & $mask);
    }

    /**
     * Check if IPv6 is within CIDR range
     */
    private function ipv6InCidr(string $ip, string $cidr): bool
    {
        list($subnet, $mask) = explode('/', $cidr);

        $ip = inet_pton($ip);
        $subnet = inet_pton($subnet);

        if ($ip === false || $subnet === false) {
            return false;
        }

        $ipBin = '';
        $subnetBin = '';

        for ($i = 0; $i < 16; $i++) {
            $ipBin .= str_pad(decbin(ord($ip[$i])), 8, '0', STR_PAD_LEFT);
            $subnetBin .= str_pad(decbin(ord($subnet[$i])), 8, '0', STR_PAD_LEFT);
        }

        $ipBin = substr($ipBin, 0, (int)$mask);
        $subnetBin = substr($subnetBin, 0, (int)$mask);

        return $ipBin === $subnetBin;
    }
}
