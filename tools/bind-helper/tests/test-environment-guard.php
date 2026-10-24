<?php
function assert_bind_test_environment(array $env): void
{
    $database = $env['DB_DATABASE'] ?? '';
    if (!is_string($database) || !preg_match('/^bind_test_[a-f0-9]{32}$/D', $database)
        || ($env['BIND_TEST_DATABASE'] ?? null) !== $database
        || ($env['DB_HOST'] ?? null) !== '127.0.0.1'
        || !in_array($env['DB_CONNECTION'] ?? '', ['mysql', 'mariadb'], true)
        || !preg_match('/^[1-9][0-9]{0,4}$/D', (string) ($env['DB_PORT'] ?? ''))
        || (int) $env['DB_PORT'] > 65535
        || ($env['BIND_TEST_SERVER_HOSTNAME'] ?? null) !== 'bind-test-'.substr($database, 10)) {
        throw new RuntimeException('Refusing an unverified disposable database environment');
    }
}
