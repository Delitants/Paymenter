<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

// CLI-only receiver: encrypt before writing anything to disk.
if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    throw new RuntimeException('Usage: php receive-credentials.php /private/output.enc');
}
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$raw = stream_get_contents(STDIN, 4194305);
if (strlen($raw) > 4194304) {
    throw new RuntimeException('Credential bundle is too large');
}
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (($data['schema_version'] ?? null) !== 1 || empty($data['credentials']) || empty($data['source_host'])) {
    throw new RuntimeException('Invalid credential bundle');
}
$path = $argv[1];
$parent = realpath(dirname($path));
if (!$parent || !str_starts_with($path, '/') || str_starts_with($parent . '/', realpath($root) . '/')) {
    throw new RuntimeException('Credential bundle must be outside the application directory');
}
$encrypted = Crypt::encryptString($raw);
umask(0077);
$file = fopen($path, 'xb');
if (!$file) {
    throw new RuntimeException('Cannot create private credential bundle');
}
try {
    if (fwrite($file, $encrypted) !== strlen($encrypted)) {
        throw new RuntimeException('Incomplete encrypted bundle write');
    }
} finally {
    fclose($file);
}
echo json_encode(['encrypted_credentials' => count($data['credentials'])]), PHP_EOL;
