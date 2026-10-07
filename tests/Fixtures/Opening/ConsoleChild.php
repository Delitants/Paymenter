<?php

namespace Tests\Fixtures\Opening;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Owned genuine CLI connection, with test-only process barriers, never argv spoofing. */
final class ConsoleChild
{
    private $process;

    private array $pipes = [];

    private ?int $exitCode = null;

    public function __construct(array $fixture, string $fault = '', string $reportName = 'child-report.json', string $command = 'apply')
    {
        $connection = DB::connection();
        $environment = getenv();
        foreach (array_keys($environment) as $key) {
            if (str_starts_with($key, 'ACCOUNT_OPENING_') || str_starts_with($key, 'ACCOUNT_FUNDING_')) {
                unset($environment[$key]);
            }
        }
        $environment = [...$environment, 'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_URL' => 'http://opening.example.invalid', 'APP_DEBUG' => 'false',
            'DB_CONNECTION' => $connection->getName(), 'DB_SOCKET' => $connection->getConfig('unix_socket'), 'DB_DATABASE' => $connection->getDatabaseName(),
            'DB_USERNAME' => $connection->getConfig('username'), 'DB_PASSWORD' => (string) $connection->getConfig('password'), 'ACCOUNT_FUNDING_ENABLED' => 'false',
            'ACCOUNT_OPENING_TRUST_PATH' => $fixture['dir'] . '/trust.json', 'ACCOUNT_OPENING_FENCE_INDEX_PATH' => $fixture['dir'] . '/current-lease.json',
            'ACCOUNT_OPENING_JOURNAL_DIRECTORY' => $fixture['dir'], 'OPENING_FIXTURE_FAULT' => $fault, 'OPENING_FIXTURE_BARRIER' => $fixture['dir'] . '/' . $reportName . '.barrier',
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'TELEMETRY_ENABLED' => 'false'];
        $args = [PHP_BINARY, '-d', 'auto_prepend_file=' . base_path('tests/Fixtures/Opening/batch-worker.php'), base_path('artisan'), 'billmanager:openings:' . $command, $fixture['dir'] . '/bundle.json', '--report=' . $fixture['dir'] . '/' . $reportName];
        if ($command === 'apply') {
            array_push($args, '--approval=' . $fixture['dir'] . '/approval.json', '--signature=' . $fixture['dir'] . '/approval-signature.json');
        }
        $this->process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, base_path(), $environment);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Synthetic native child could not start.');
        }
        fclose($this->pipes[0]);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    public function pid(): int
    {
        return proc_get_status($this->process)['pid'];
    }

    public function barrier(string $path, int $seconds = 240): array
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                $row = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if ($row['pid'] !== $this->pid() || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0600) {
                    throw new RuntimeException('Synthetic process barrier identity changed.');
                }
                if ($row['phase'] !== 'committed') {
                    // proc_get_status consumes waitpid's one-time stop event;
                    // pid() above may have consumed it already. Linux state is
                    // the continuing observation of this exact child instead.
                    $isStopped = fn () => preg_match('/^State:\s+[Tt]\b/m', (string) @file_get_contents('/proc/' . $row['pid'] . '/status')) === 1;
                    while (!$isStopped() && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    if (!$isStopped()) {
                        throw new RuntimeException('Projection barrier did not stop its actual child.');
                    }
                }

                return $row;
            }
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $this->exitCode = $status['exitcode'];
                throw new RuntimeException('Native child exited before its observation barrier: ' . $this->output());
            }
            usleep(100000);
        }
        throw new RuntimeException('Native child observation barrier timed out.');
    }

    public function signal(int $signal): void
    {
        if (!proc_terminate($this->process, $signal)) {
            throw new RuntimeException('Owned native child signal failed.');
        }
    }

    public function finish(int $seconds = 480): array
    {
        $deadline = microtime(true) + $seconds;
        $output = '';
        while (microtime(true) < $deadline) {
            $output .= $this->output();
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $code = $status['signaled'] ? 128 + $status['termsig'] : ($status['exitcode'] >= 0 ? $status['exitcode'] : $this->exitCode);
                $output .= $this->output();
                fclose($this->pipes[1]);
                fclose($this->pipes[2]);
                $closed = proc_close($this->process);
                $this->process = null;

                return ['exit_code' => $code ?? $closed, 'output' => $output];
            }
            usleep(100000);
        }
        throw new RuntimeException('Native child completion timed out.');
    }

    private function output(): string
    {
        return stream_get_contents($this->pipes[1]) . stream_get_contents($this->pipes[2]);
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, SIGKILL);
            foreach ([$this->pipes[1], $this->pipes[2]] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }
    }
}
