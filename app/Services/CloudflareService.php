<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareService
{
    const CACHE_KEY = 'cloudflare.ip_ranges';
    const CACHE_TIME_KEY = 'cloudflare.ip_ranges_time';
    const CACHE_TTL = 86400; // 24 hours

    /**
     * Get all Cloudflare IP ranges (IPv4 and IPv6)
     */
    public function getIpRanges(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            // Store the timestamp when ranges are fetched
            Cache::put(self::CACHE_TIME_KEY, time(), self::CACHE_TTL);

            $ranges = [
                'ipv4' => $this->fetchIpv4Ranges(),
                'ipv6' => $this->fetchIpv6Ranges(),
            ];

            return $ranges;
        });
    }

    /**
     * Fetch IPv4 ranges from Cloudflare
     */
    private function fetchIpv4Ranges(): array
    {
        try {
            $response = Http::timeout(10)->get('https://www.cloudflare.com/ips-v4');

            if ($response->successful()) {
                $lines = array_filter(
                    array_map('trim', explode("\n", $response->body())),
                    fn($line) => !empty($line) && !str_starts_with($line, '#')
                );

                return array_values($lines);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch Cloudflare IPv4 ranges: ' . $e->getMessage());
        }

        // Fallback to known ranges
        return $this->getFallbackIpv4Ranges();
    }

    /**
     * Fetch IPv6 ranges from Cloudflare
     */
    private function fetchIpv6Ranges(): array
    {
        try {
            $response = Http::timeout(10)->get('https://www.cloudflare.com/ips-v6');

            if ($response->successful()) {
                $lines = array_filter(
                    array_map('trim', explode("\n", $response->body())),
                    fn($line) => !empty($line) && !str_starts_with($line, '#')
                );

                return array_values($lines);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch Cloudflare IPv6 ranges: ' . $e->getMessage());
        }

        // Fallback to known ranges
        return $this->getFallbackIpv6Ranges();
    }

    /**
     * Fallback IPv4 ranges
     */
    private function getFallbackIpv4Ranges(): array
    {
        return [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        ];
    }

    /**
     * Fallback IPv6 ranges
     */
    private function getFallbackIpv6Ranges(): array
    {
        return [
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
    }

    /**
     * Check if IP is a Cloudflare IP
     */
    public function isCloudflareIp(string $ip): bool
    {
        $ranges = $this->getIpRanges();

        foreach ($ranges['ipv4'] as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        foreach ($ranges['ipv6'] as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is within CIDR range
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (str_contains($ip, ':')) {
            return $this->ipv6InCidr($ip, $cidr);
        }

        return $this->ipv4InCidr($ip, $cidr);
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

    /**
     * Get real client IP from Cloudflare headers
     */
    public function getRealIp(): ?string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            $ip = $_SERVER[$header] ?? null;

            if ($ip) {
                // Handle X-Forwarded-For with multiple IPs
                if (str_contains($ip, ',')) {
                    $ips = array_map('trim', explode(',', $ip));
                    $ip = $ips[0];
                }

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Refresh IP ranges from Cloudflare
     */
    public function refreshIpRanges(): bool
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_TIME_KEY);
        $this->getIpRanges();

        return true;
    }

    /**
     * Get IP ranges count
     */
    public function getRangesCount(): array
    {
        $ranges = $this->getIpRanges();

        return [
            'ipv4_count' => count($ranges['ipv4']),
            'ipv6_count' => count($ranges['ipv6']),
            'total' => count($ranges['ipv4']) + count($ranges['ipv6']),
        ];
    }

    /**
     * Get last update time
     */
    public function getLastUpdate(): ?string
    {
        $lastUpdate = Cache::get(self::CACHE_TIME_KEY);
        return $lastUpdate ? date('Y-m-d H:i:s', $lastUpdate) : null;
    }
}
