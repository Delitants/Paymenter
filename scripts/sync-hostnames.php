<?php

/**
 * Sync Hostnames from IPManager to Paymenter IP Addresses
 *
 * Uses direct DB queries for reliability
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$csvDir = '/tmp/ipmanager_sync/IPManager';
$stats = [
    'ips_updated' => 0,
    'ips_not_found' => 0,
    'files_processed' => 0,
    'errors' => [],
];

echo "=== IPManager Hostname Sync ===" . PHP_EOL;
echo "Starting sync at " . now()->toDateTimeString() . PHP_EOL . PHP_EOL;

/**
 * Parse individual range CSV file for hostname data
 */
function parseRangeFile($path): array
{
    $ips = [];
    $content = file_get_contents($path);

    // Normalize line endings (Windows -> Unix)
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

        if (empty($ipAddress)) {
            continue;
        }

        // Skip FREE entries - they don't have a real hostname
        $isFree = stripos($domainName, 'FREE') !== false;
        if ($isFree) {
            $domainName = null;
        }

        // Clean up hostname - remove trailing dots
        if ($domainName) {
            $domainName = rtrim($domainName, '.');
        }

        $ips[] = [
            'ip' => $ipAddress,
            'hostname' => $domainName ?: null,
            'is_free' => $isFree,
        ];
    }

    return $ips;
}

// Get all CSV files (excluding allocation file)
$files = glob($csvDir . '/*.csv');
$files = array_filter($files, fn($f) => strpos(basename($f), 'IP allocation') === false);

echo "Found " . count($files) . " IP range CSV files" . PHP_EOL . PHP_EOL;

foreach ($files as $file) {
    echo "Processing: " . basename($file) . PHP_EOL;

    $ips = parseRangeFile($file);
    $stats['files_processed']++;

    foreach ($ips as $ipData) {
        $ipAddress = $ipData['ip'];
        $hostname = $ipData['hostname'];

        // Update using direct DB query
        $affected = DB::table('ip_addresses')
            ->where('ip_address', $ipAddress)
            ->update([
                'hostname' => $hostname,
                'is_assigned' => $hostname !== null ? 1 : 0,
                'assigned_to_type' => $hostname !== null ? 'App\\Models\\User' : null,
                'updated_at' => now(),
            ]);

        if ($affected > 0) {
            $stats['ips_updated']++;
            echo "  Updated {$ipAddress}: " . ($hostname ?? 'FREE') . PHP_EOL;
        } else {
            $stats['ips_not_found']++;
            echo "  IP not found in DB: {$ipAddress}" . PHP_EOL;
        }
    }
}

echo PHP_EOL . "=== Sync Complete ===" . PHP_EOL;
echo "Files processed: {$stats['files_processed']}" . PHP_EOL;
echo "IPs updated: {$stats['ips_updated']}" . PHP_EOL;
echo "IPs not found in database: {$stats['ips_not_found']}" . PHP_EOL;

if (!empty($stats['errors'])) {
    echo PHP_EOL . "Errors:" . PHP_EOL;
    foreach ($stats['errors'] as $error) {
        echo "  - {$error}" . PHP_EOL;
    }
}

echo PHP_EOL . "Finished at " . now()->toDateTimeString() . PHP_EOL;
