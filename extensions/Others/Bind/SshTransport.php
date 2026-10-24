<?php

namespace Paymenter\Extensions\Others\Bind;

use Symfony\Component\Process\Process;

final class SshTransport
{
    private \Closure $runner;

    public function __construct(private readonly array $configuration, ?callable $runner = null, private readonly ?string $tempRoot = null)
    {
        $this->runner = $runner === null ? self::run(...) : \Closure::fromCallable($runner);
    }

    public function request(string $bindingId, string $operation, array $payload, string $requestId): array
    {
        $input = Protocol::encode($requestId, $bindingId, $operation, $payload);
        $c = $this->configuration;
        $host = $c['host'] ?? null;
        Protocol::need(is_string($host) && (filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/D', $host) === 1) && strlen($host) <= 253);
        Protocol::need(is_int($c['port'] ?? null) && $c['port'] >= 1 && $c['port'] <= 65535 && is_string($c['username'] ?? null) && $c['username'] !== 'root' && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $c['username']) === 1);
        Protocol::need(is_string($c['private_key'] ?? null) && strlen($c['private_key']) <= 16384 && preg_match('/^-----BEGIN (?:OPENSSH |RSA |EC )?PRIVATE KEY-----\R/', $c['private_key']) === 1);
        Protocol::need(is_string($c['host_key'] ?? null) && strlen($c['host_key']) <= 16384 && preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) ([A-Za-z0-9+\/=]+)$/D', $c['host_key'], $key) === 1 && base64_decode($key[2], true) !== false);
        $pattern = $c['port'] === 22 ? $host . ',[' . $host . ']:22' : '[' . $host . ']:' . $c['port'];
        $files = null;
        try {
            $files = new PrivateFiles($c['private_key'], $pattern . ' ' . $c['host_key'] . "\n", $this->tempRoot);
            $argv = ['/usr/bin/ssh', '-F', '/dev/null', '-T'];
            foreach (['BatchMode=yes', 'StrictHostKeyChecking=yes', 'UserKnownHostsFile=' . $files->knownHostsPath, 'GlobalKnownHostsFile=/dev/null', 'IdentityAgent=none', 'IdentitiesOnly=yes', 'ForwardAgent=no', 'ClearAllForwardings=yes', 'ControlMaster=no', 'ControlPath=none', 'ConnectionAttempts=1', 'ConnectTimeout=10', 'LogLevel=ERROR'] as $option) {
                $argv[] = '-o';
                $argv[] = $option;
            }
            array_push($argv, '-i', $files->keyPath, '-p', (string) $c['port'], '--', $c['username'] . '@' . $host, 'paymenter-bind-v1');
            $timeout = in_array($operation, ['prepare_zone', 'prepare_secondary'], true) ? 70 : 40;
            $raw = ($this->runner)($argv, $input, $timeout, Protocol::OUTPUT_LIMIT);
            Protocol::need(is_string($raw));

            return Protocol::decode($raw, $requestId, $bindingId);
        } catch (\Throwable) {
            throw new TransportUncertain($requestId);
        } finally {
            try {
                $files?->cleanup();
            } catch (\Throwable) {
                throw new TransportUncertain($requestId);
            }
        }
    }

    private static function run(array $argv, string $stdin, int $timeout, int $limit): string
    {
        $process = new Process($argv, env: ['SSH_AUTH_SOCK' => false, 'SSH_AGENT_PID' => false], input: $stdin, timeout: $timeout);
        $stdout = '';
        $stderr = '';
        try {
            $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr, $limit): void {
                if ($type === Process::OUT) {
                    $stdout .= $buffer;
                } else {
                    $stderr .= $buffer;
                }
                if (strlen($stdout) + strlen($stderr) > $limit) {
                    throw new \RuntimeException('SSH response limit exceeded');
                }
            });
            if (!$process->isSuccessful() || $stderr !== '') {
                throw new \RuntimeException('SSH outcome not confirmed');
            }

            return $stdout;
        } finally {
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
        }
    }
}
