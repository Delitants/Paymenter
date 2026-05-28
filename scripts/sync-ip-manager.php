<?php

/**
 * Sync IPManager data to Paymenter IP Pools
 *
 * Parses IPManager CSV files and creates IP Pools and IP Addresses
 * FREE status takes priority - IPs with "FREE" in domain name are unoccupied
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\IpPool;
use App\Models\IpAddress;
use App\Models\Server;

$csvDir = '/tmp/IPManager';
$stats = [
    'pools_created' => 0,
    'ips_created' => 0,
    'ips_free' => 0,
    'ips_assigned' => 0,
    'errors' => [],
];

echo "=== IPManager Sync Script ===" . PHP_EOL;
echo "Starting sync at " . now()->toDateTimeString() . PHP_EOL . PHP_EOL;

/**
 * Parse IP allocation.csv to get range permissions
 */
function parseAllocationFile($path): array
{
    $ranges = [];
    $content = file_get_contents($path);

    // Normalize line endings
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    $lines = explode("\n", $content);

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip header and empty lines
        if (strpos($line, 'Range;') !== false || empty($line) || strpos($line, 'Statistics') !== false) {
            continue;
        }

        // Remove trailing comma if present
        $line = rtrim($line, ',');

        // Parse: "start - end;""permissions"";" or "start - end;""permissions"";"""
        if (preg_match('/"?([\d.:a-fA-F]+)\s*-\s*([\d.:a-fA-F]+)"?;"?([^"]*)"?;?/', $line, $matches)) {
            $start = trim($matches[1]);
            $end = trim($matches[2]);
            $permissions = trim(str_replace(['""', '"'], '', $matches[3]));

            // Clean up permissions - remove trailing quotes and commas
            $permissions = rtrim($permissions, '",');

            $ranges[] = [
                'start' => $start,
                'end' => $end,
                'permissions' => $permissions,
                'type' => 'range',
            ];
        }
        // Single IP: "ip;""permissions"";"
        elseif (preg_match('/"?([\d.:a-fA-F]+)"?;"?([^"]*)"?;?/', $line, $matches)) {
            $ip = trim($matches[1]);
            $permissions = trim(str_replace(['""', '"'], '', $matches[2]));

            // Skip if it looks like a range line we already parsed
            if (strpos($ip, '-') !== false) {
                continue;
            }

            $permissions = rtrim($permissions, '",');

            $ranges[] = [
                'start' => $ip,
                'end' => $ip,
                'permissions' => $permissions,
                'type' => 'single',
            ];
        }
    }

    return $ranges;
}

/**
 * Parse individual range CSV file
 */
function parseRangeFile($path): array
{
    $ips = [];
    $content = file_get_contents($path);

    // Normalize line endings
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    $lines = explode("\n", $content);
    $header = null;

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip empty lines and title lines
        if (empty($line) || strpos($line, 'Network IP addresses') !== false) {
            continue;
        }

        // Parse header line
        if ($header === null && strpos($line, '"IP address"') !== false) {
            $header = str_getcsv($line, ';');
            $header = array_map(fn($f) => trim($f, '"'), $header);
            continue;
        }

        // Skip if no header yet
        if ($header === null) {
            continue;
        }

        // Parse data row
        $row = str_getcsv($line, ';');
        if (empty($row) || empty($row[0])) {
            continue;
        }

        // Map row to fields
        $ipData = [];
        foreach ($header as $i => $field) {
            $value = $row[$i] ?? '';
            $ipData[$field] = trim($value, '"');
        }

        $ipAddress = $ipData['IP address'] ?? '';
        $domainName = $ipData['Domain name'] ?? '';
        $status = $ipData['Status'] ?? '';
        $owner = $ipData['Owner'] ?? '';

        if (empty($ipAddress)) {
            continue;
        }

        // FREE takes priority - if domain contains "FREE", IP is unoccupied
        $isFree = stripos($domainName, 'FREE') !== false;

        $ips[] = [
            'ip' => $ipAddress,
            'domain' => $domainName,
            'status' => $status,
            'owner' => $owner,
            'is_free' => $isFree,
        ];
    }

    return $ips;
}

/**
 * Expand CIDR to IP range
 */
function cidrToRange($cidr): array
{
    $parts = explode('/', $cidr);
    $ip = $parts[0];
    $prefix = intval($parts[1] ?? 32);

    // IPv4
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ipLong = ip2long($ip);
        $mask = -1 << (32 - $prefix);
        $start = long2ip($ipLong & $mask);
        $end = long2ip($ipLong & $mask | ~$mask);
        return ['start' => $start, 'end' => $end, 'version' => 'ipv4'];
    }

    // IPv6 - simplified, just return the CIDR as-is for now
    return ['start' => $ip, 'end' => $ip, 'version' => 'ipv6'];
}

/**
 * Generate all IPs in a range
 */
function expandIpRange($start, $end): array
{
    $ips = [];

    // IPv4 range
    if (filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
        filter_var($end, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $startLong = ip2long($start);
        $endLong = ip2long($end);

        for ($i = $startLong; $i <= $endLong; $i++) {
            $ips[] = long2ip($i);
        }
    } else {
        // IPv6 or invalid - just add start
        $ips[] = $start;
    }

    return $ips;
}

// Parse allocation file
$allocationFile = $csvDir . '/IP allocation.csv';
echo "Parsing allocation file: {$allocationFile}" . PHP_EOL;
$allocations = parseAllocationFile($allocationFile);

echo "Found " . count($allocations) . " ranges in allocation file" . PHP_EOL;

// Group ranges by permissions (owner)
$rangesByOwner = [];
foreach ($allocations as $range) {
    $owner = $range['permissions'] ?: 'unassigned';
    // Clean owner name
    $owner = preg_replace('/[^a-zA-Z0-9_-]/', '', $owner);
    if (empty($owner)) {
        $owner = 'unassigned';
    }

    if (!isset($rangesByOwner[$owner])) {
        $rangesByOwner[$owner] = [];
    }
    $rangesByOwner[$owner][] = $range;
}

echo PHP_EOL . "Creating IP Pools by owner:" . PHP_EOL;

foreach ($rangesByOwner as $owner => $ranges) {
    echo "  - {$owner}: " . count($ranges) . " ranges" . PHP_EOL;

    // Create IP Pool
    $pool = IpPool::firstOrCreate(
        ['name' => "IPManager-{$owner}"],
        [
            'description' => "Imported from IPManager - Owner: {$owner}",
            'ip_version' => 'mixed',
            'server_id' => null,
        ]
    );

    $stats['pools_created']++;
    echo "    Created/Found pool: {$pool->name} (ID: {$pool->id})" . PHP_EOL;

    // Process each range
    foreach ($ranges as $range) {
        $start = $range['start'];
        $end = $range['end'];

        echo "    Processing range: {$start} - {$end}" . PHP_EOL;

        // Check if there's a corresponding CSV file for detailed IP data
        $csvFilename = null;
        $files = glob($csvDir . '/*.csv');
        foreach ($files as $file) {
            $basename = basename($file);
            // Match file that starts with the range start IP
            if (strpos($basename, str_replace(':', '-', $start) . ':') === 0 ||
                strpos($basename, $start . ':') === 0) {
                $csvFilename = $file;
                break;
            }
        }

        if ($csvFilename) {
            echo "      Found detailed CSV: " . basename($csvFilename) . PHP_EOL;
            $detailedIps = parseRangeFile($csvFilename);

            foreach ($detailedIps as $ipData) {
                $ipAddress = $ipData['ip'];
                $isFree = $ipData['is_free'];
                $owner = $ipData['owner'];

                // Create IP Address record
                $ip = IpAddress::firstOrCreate(
                    [
                        'ip_pool_id' => $pool->id,
                        'ip_address' => $ipAddress,
                    ],
                    [
                        'is_assigned' => !$isFree,
                        'assigned_to_type' => $isFree ? null : 'App\\Models\\User',
                        'assigned_to_id' => null,
                    ]
                );

                $stats['ips_created']++;
                if ($isFree) {
                    $stats['ips_free']++;
                } else {
                    $stats['ips_assigned']++;
                }
            }
        } else {
            // No detailed CSV, expand the range and create all IPs as free
            echo "      No detailed CSV, expanding range..." . PHP_EOL;
            $expandedIps = expandIpRange($start, $end);

            foreach ($expandedIps as $ip) {
                $ipAddress = IpAddress::firstOrCreate(
                    [
                        'ip_pool_id' => $pool->id,
                        'ip_address' => $ip,
                    ],
                    [
                        'is_assigned' => false,
                        'assigned_to_type' => null,
                        'assigned_to_id' => null,
                    ]
                );

                $stats['ips_created']++;
                $stats['ips_free']++;
            }
        }
    }
}

echo PHP_EOL . "=== Sync Complete ===" . PHP_EOL;
echo "Pools created: {$stats['pools_created']}" . PHP_EOL;
echo "IP addresses created: {$stats['ips_created']}" . PHP_EOL;
echo "  - Free (unoccupied): {$stats['ips_free']}" . PHP_EOL;
echo "  - Assigned: {$stats['ips_assigned']}" . PHP_EOL;

if (!empty($stats['errors'])) {
    echo PHP_EOL . "Errors:" . PHP_EOL;
    foreach ($stats['errors'] as $error) {
        echo "  - {$error}" . PHP_EOL;
    }
}

echo PHP_EOL . "Finished at " . now()->toDateTimeString() . PHP_EOL;
