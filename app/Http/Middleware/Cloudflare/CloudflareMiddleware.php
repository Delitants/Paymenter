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

        // Check if request came through Cloudflare (check for CF headers)
        $cfConnectingIp = $request->header('CF-Connecting-IP');
        $cfRay = $request->header('CF-Ray');
        $cdnLoop = $request->header('CDN-Loop');
        $cameThroughCloudflare = !empty($cfConnectingIp) && (!empty($cfRay) || str_contains($cdnLoop ?? '', 'cloudflare'));

        // Check IP whitelist if enabled
        if ($this->isWhitelistEnabled()) {
            // If came through Cloudflare, trust the CF headers
            if (!$cameThroughCloudflare) {
                // Direct connection - check if IP is allowed
                $connectingIp = $request->server('REMOTE_ADDR') ?? $request->ip();
                $check = $this->checkWhitelist($connectingIp);

                if (!$check['allowed']) {
                    return response()->view('errors.403', [
                        'message' => $check['reason'],
                    ], 403);
                }
            }
        }

        // Restore real IP from Cloudflare headers if present
        if ($cameThroughCloudflare) {
            $_SERVER['REMOTE_ADDR'] = $cfConnectingIp;
            $_SERVER['REMOTE_HOST'] = gethostbyaddr($cfConnectingIp) ?: $cfConnectingIp;
        }

        return $next($request);
    }

    /**
     * Check if Cloudflare integration is enabled
     */
    protected function isCloudflareEnabled(): bool
    {
        // Always check DB directly, not config (config may be stale)
        $setting = Setting::where('key', 'cloudflare_enabled')->first();
        if (!$setting) {
            return false;
        }
        $value = $setting->value;
        // Handle both boolean true and string '1'
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * Check if IP whitelist is enabled
     */
    protected function isWhitelistEnabled(): bool
    {
        // Always check DB directly, not config (config may be stale)
        $setting = Setting::where('key', 'cloudflare_whitelist_enabled')->first();
        if (!$setting) {
            return false;
        }
        $value = $setting->value;
        // Handle both boolean true and string '1'
        return $value === true || $value === 1 || $value === '1';
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

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Ensure we have 16 bytes for IPv6
        if (strlen($ipBin) !== 16 || strlen($subnetBin) !== 16) {
            return false;
        }

        $maskInt = (int)$mask;
        if ($maskInt < 0 || $maskInt > 128) {
            return false;
        }

        // Compare byte by byte
        for ($i = 0; $i < 16; $i++) {
            $byteIp = ord($ipBin[$i]);
            $byteSubnet = ord($subnetBin[$i]);

            // If we're past the mask boundary, no need to check
            if ($i * 8 >= $maskInt) {
                break;
            }

            // Calculate how many bits to check in this byte
            $bitsRemaining = $maskInt - ($i * 8);
            $bitsToCheck = min(8, $bitsRemaining);
            $maskByte = (0xFF << (8 - $bitsToCheck)) & 0xFF;

            if (($byteIp & $maskByte) !== ($byteSubnet & $maskByte)) {
                return false;
            }
        }

        return true;
    }
}
