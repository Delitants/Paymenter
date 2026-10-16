<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// Store SMTP configuration encrypted; keep delivery disabled during preparation.
if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    throw new RuntimeException('Usage: receive-mail-settings.php PRIVATE_OUTPUT EXPECTED_SOURCE');
}
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!in_array(DB::connection()->getDatabaseName(), config('billmanager-migration.allowed_databases', []), true)) {
    throw new RuntimeException('Database is not explicitly allowed');
}
$raw = stream_get_contents(STDIN, 65537);
if (strlen($raw) > 65536) {
    throw new RuntimeException('Mail bundle is too large');
}
$data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
$keys = ['mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption'];
$actual = array_keys($data['settings'] ?? []);
sort($actual);
$expected = $keys;
sort($expected);
if (($data['schema_version'] ?? null) !== 1 || ($data['kind'] ?? null) !== 'mail_settings' || ($data['source_host'] ?? null) !== $argv[2] || $actual !== $expected) {
    throw new RuntimeException('Invalid SMTP bundle');
}
$parent = realpath(dirname($argv[1]));
if (!$parent || !str_starts_with($argv[1], '/') || str_starts_with($parent . '/', realpath($root) . '/')) {
    throw new RuntimeException('Output must be outside application directory');
}
umask(0077);
$file = fopen($argv[1], 'xb');
if (!$file) {
    throw new RuntimeException('Cannot create encrypted mail bundle');
}
try {
    $encrypted = Crypt::encryptString($raw);
    if (fwrite($file, $encrypted) !== strlen($encrypted)) {
        throw new RuntimeException('Incomplete mail bundle write');
    }
} finally {
    fclose($file);
}
DB::transaction(function () use ($data) {
    foreach ($data['settings'] + ['mail_disable' => '1'] as $key => $value) {
        DB::table('settings')->updateOrInsert(['key' => $key, 'settingable_id' => null, 'settingable_type' => null], [
            'value' => $key === 'mail_password' ? Crypt::encryptString($value) : $value,
            'encrypted' => $key === 'mail_password', 'type' => $key === 'mail_disable' ? 'boolean' : 'string',
        ]);
    }
});
Cache::forget('settings');
echo json_encode(['smtp_settings_copied' => count($keys), 'password_encrypted' => true, 'delivery_disabled' => true]), PHP_EOL;
