<?php

/**
 * Split IP Pools by Network
 *
 * Uses CIDR from CSV filenames (e.g., 188.227.176.184:29.csv = /29)
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\IpPool;
use App\Models\IpAddress;
use Illuminate\Support\Facades\DB;

$csvDir = '/tmp/ipmanager_sync/IPManager';
$stats = [
    'pools_created' => 0,
    'ips_migrated' => 0,
    'errors' => [],
];

echo "=== Split IP Pools by Network ===" . PHP_EOL;
echo "Starting at " . now()->toDateTimeString() . PHP_EOL . PHP_EOL;

/**
 * Parse CSV filename to get network and CIDR
 * Format: IP:CIDR.csv (e.g., 188.227.176.184:29.csv or 2a02-2658-102b--:64.csv)
 */
function parseFilename($filename): array
{
    $basename = basename($filename, '.csv');

    // Skip allocation file
    if (strpos($basename, 'IP allocation') !== false) {
        return null;
    }

    // Find CIDR - it's the number after the last colon
    $lastColon = strrpos($basename, ':');
    if ($lastColon === false) {
        return null;
    }

    $cidr = substr($basename, $lastColon + 1);
    $ipPart = substr($basename, 0, $lastColon);

    // Convert dashes back to colons for IPv6 (but preserve ::)
    // Format: 2a02-2658-102b-- means 2a02:2658:102b::
    $ip = str_replace('-', ':', $ipPart);

    // Fix double colon representation (e.g., 2a02:2658:102b: becomes 2a02:2658:102b::)
    if (substr($ip, -1) === ':' && substr($ip, -2) !== '::') {
        $ip = $ip . ':';
    }

    return [
        'ip' => $ip,
        'cidr' => $cidr,
        'network' => calculateNetwork($ip, $cidr),
    ];
}

/**
 * Calculate network address from IP and CIDR
 */
function calculateNetwork($ip, $cidr): string
{
    // IPv4
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ipLong = ip2long($ip);
        $mask = -1 << (32 - $cidr);
        $network = long2ip($ipLong & $mask);
        return "{$network}/{$cidr}";
    }

    // IPv6 - simplified, just return IP/CIDR
    return "{$ip}/{$cidr}";
}

/**
 * Get all IPs in a CIDR range
 */
function getCidrIps($network, $cidr): array
{
    $ips = [];

    // IPv4
    if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ipLong = ip2long($network);
        $broadcast = $ipLong + pow(2, (32 - $cidr)) - 1;

        for ($i = $ipLong; $i <= $broadcast; $i++) {
            $ips[] = long2ip($i);
        }
    } else {
        // IPv6 - just return the network address for now
        // Full IPv6 expansion would be too large
        $ips[] = $network;
    }

    return $ips;
}

/**
 * Get IP version
 */
function getIpVersion($ip): string
{
    // Try with original IP
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return 'ipv4';
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'ipv6';
    }

    // Try expanding :: notation for IPv6
    $expanded = expandIPv6($ip);
    if (filter_var($expanded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'ipv6';
    }

    return 'ipv6'; // Assume IPv6 if it contains colons
}

/**
 * Expand IPv6 :: notation for validation
 */
function expandIPv6($ip): string
{
    if (strpos($ip, '::') !== false) {
        $parts = explode('::', $ip);
        $left = count(explode(':', $parts[0]));
        $right = count(explode(':', $parts[1] ?? ''));
        $missing = 8 - $left - $right;
        $middle = str_repeat('0:', $missing);
        $ip = str_replace('::', ':' . $middle, $ip);
        $ip = trim($ip, ':');
    }
    return $ip;
}

// Get all CSV files
$files = glob($csvDir . '/*.csv');
$files = array_filter($files, fn($f) => strpos(basename($f), 'IP allocation') === false);

echo "Found " . count($files) . " network CSV files" . PHP_EOL . PHP_EOL;

// Delete existing pools and migrate IPs
$oldPools = IpPool::all();
echo "Migrating from " . $oldPools->count() . " existing pool(s)" . PHP_EOL;

foreach ($files as $file) {
    $parsed = parseFilename($file);

    if (!$parsed) {
        echo "Skipping: " . basename($file) . PHP_EOL;
        continue;
    }

    $networkName = $parsed['network'];
    $ipVersion = getIpVersion($parsed['ip']);

    echo PHP_EOL . "Processing: {$networkName} ({$ipVersion})" . PHP_EOL;

    // Get all IPs in this network
    $ipsInNetwork = getCidrIps($parsed['ip'], $parsed['cidr']);
    echo "  IPs in network: " . count($ipsInNetwork) . PHP_EOL;

    // Create new pool for this network
    $pool = IpPool::create([
        'name' => $networkName,
        'description' => "Network /{$parsed['cidr']}",
        'ip_version' => $ipVersion,
        'server_id' => null,
    ]);

    $stats['pools_created']++;
    echo "  Created pool: {$pool->name} (ID: {$pool->id})" . PHP_EOL;

    // Migrate IPs from old pools to new pool
    foreach ($ipsInNetwork as $ip) {
        $oldIp = IpAddress::where('ip_address', $ip)->first();

        if ($oldIp) {
            // Update existing record
            $oldIp->update([
                'ip_pool_id' => $pool->id,
            ]);
            $stats['ips_migrated']++;
        } else {
            // Create new record
            IpAddress::create([
                'ip_pool_id' => $pool->id,
                'ip_address' => $ip,
                'is_assigned' => false,
                'hostname' => null,
                'assigned_to_type' => null,
                'assigned_to_id' => null,
            ]);
            $stats['ips_migrated']++;
        }
    }
}

// Delete old empty pools
foreach ($oldPools as $oldPool) {
    $ipCount = $oldPool->ipAddresses()->count();
    if ($ipCount === 0) {
        $oldPool->delete();
        echo PHP_EOL . "Deleted empty pool: {$oldPool->name}" . PHP_EOL;
    }
}

echo PHP_EOL . "=== Split Complete ===" . PHP_EOL;
echo "Pools created: {$stats['pools_created']}" . PHP_EOL;
echo "IPs migrated: {$stats['ips_migrated']}" . PHP_EOL;

if (!empty($stats['errors'])) {
    echo PHP_EOL . "Errors:" . PHP_EOL;
    foreach ($stats['errors'] as $error) {
        echo "  - {$error}" . PHP_EOL;
    }
}

echo PHP_EOL . "Finished at " . now()->toDateTimeString() . PHP_EOL;
