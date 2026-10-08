<?php

use App\Services\BillmanagerMigration\Opening\PrivateProofFile;

// Test-only acceptance preflight: fail before framework/database setup when
// the caller did not explicitly supply the owned privileged environment.
$socket = getenv('DB_SOCKET');
$hostNamespace = getenv('AUDIT_HOST_NET_NS');
$proofRoot = getenv('OPENING_FIXTURE_ROOT');
$runtimeRoot = getenv('RUNTIME_FIXTURE_ROOT');
$probe = getenv('OPENING_COLD_RESTORE_PROBE');
$interfaces = is_file('/proc/net/dev') ? array_map(static fn ($line) => trim(explode(':', $line, 2)[0]), array_filter(array_slice(file('/proc/net/dev'), 2), static fn ($line) => str_contains($line, ':'))) : [];
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0 || getenv('APP_ENV') !== 'testing' ||
    getenv('DB_DATABASE') !== 'paymenter_test' || !$socket || realpath($socket) !== $socket || filetype($socket) !== 'socket' || fileowner($socket) !== 0 ||
    !$hostNamespace || !is_file('/proc/self/ns/net') || readlink('/proc/self/ns/net') === $hostNamespace ||
    $interfaces !== ['lo'] || !$proofRoot || !$runtimeRoot || !$probe) {
    throw new RuntimeException('Native opening acceptance requires an explicit owned isolated root testing environment.');
}
foreach ([$proofRoot => 0700, $runtimeRoot => 0750] as $directory => $mode) {
    if (realpath($directory) !== $directory || fileowner($directory) !== 0 || (fileperms($directory) & 0777) !== $mode) {
        throw new RuntimeException('Native opening acceptance requires protected fixture roots.');
    }
}
if ((fileperms(dirname($socket)) & 0022) !== 0 || fileowner(dirname($socket)) !== 0 ||
    !ctype_digit((string) getenv('RUNTIME_FIXTURE_GID')) || filegroup($runtimeRoot) !== (int) getenv('RUNTIME_FIXTURE_GID')) {
    throw new RuntimeException('Native opening acceptance requires protected socket and runtime reader access.');
}
require dirname(__DIR__, 3) . '/vendor/autoload.php';
PrivateProofFile::read($probe, 0);
