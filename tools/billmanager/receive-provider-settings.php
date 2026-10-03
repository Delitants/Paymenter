<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    throw new RuntimeException('Usage: receive-provider-settings.php PRIVATE_OUTPUT EXPECTED_SOURCE');
}
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!in_array(DB::connection()->getDatabaseName(), config('billmanager-migration.allowed_databases', []), true)) {
    throw new RuntimeException('Database is not explicitly allowed');
}
$raw = stream_get_contents(STDIN, 262145);
if (strlen($raw) > 262144) {
    throw new RuntimeException('Provider bundle exceeds maximum size');
}
$data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
if (($data['schema_version'] ?? null) !== 1 || ($data['source_host'] ?? null) !== $argv[2] || ($data['kind'] ?? null) !== 'provider_settings' || !is_array($data['providers'] ?? null) || !$data['providers']) {
    throw new RuntimeException('Invalid provider bundle or source');
}
$ids = array_column($data['providers'], 'id');
if (count($ids) !== count($data['providers']) || count(array_unique($ids)) !== count($ids)) {
    throw new RuntimeException('Missing or duplicate provider identifiers');
}
$parent = realpath(dirname($argv[1]));
if (!$parent || !str_starts_with($argv[1], '/') || str_starts_with($parent . '/', realpath($root) . '/')) {
    throw new RuntimeException('Output must be outside application directory');
}
umask(0077);
$file = fopen($argv[1], 'xb');
if (!$file) {
    throw new RuntimeException('Cannot create private encrypted bundle');
}
try {
    $encrypted = Crypt::encryptString($raw);
    if (fwrite($file, $encrypted) !== strlen($encrypted)) {
        throw new RuntimeException('Incomplete encrypted bundle write');
    }
} finally {
    fclose($file);
}
if (Crypt::decryptString(file_get_contents($argv[1])) !== $raw) {
    throw new RuntimeException('Encrypted bundle readback failed');
}
echo json_encode(['providers_encrypted' => count($ids), 'readback_verified' => true, 'native_settings_changed' => false]), PHP_EOL;
