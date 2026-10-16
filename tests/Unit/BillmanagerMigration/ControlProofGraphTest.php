<?php

namespace Tests\Unit\BillmanagerMigration;

use App\Services\BillmanagerMigration\Opening\ControlProofGraph;
use App\Services\BillmanagerMigration\Opening\PrivateProofFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\Opening\ProofFactory;

#[Group('opening-native')]
class ControlProofGraphTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(0, posix_geteuid());
        $this->dir = getenv('OPENING_FIXTURE_ROOT') . '/graph-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        ProofFactory::$directories[] = $this->dir;
    }

    protected function tearDown(): void
    {
        ProofFactory::cleanup();
        parent::tearDown();
    }

    private function file(string $name, int $size): array
    {
        $path = $this->dir . '/' . $name;
        ProofFactory::write($path, str_repeat('a', $size));

        return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
    }

    private function denied(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());

            return;
        }
        self::fail('Unsafe control proof was accepted.');
    }

    public function test_bounded_read_accepts_exact_size_and_denies_one_more(): void
    {
        self::assertTrue(class_exists(ControlProofGraph::class), 'Bounded control graph is missing.');
        foreach (['control' => 65536, 'history' => 4194304] as $kind => $limit) {
            $good = $this->file($kind, $limit);
            self::assertSame($limit, strlen((new ControlProofGraph)->read($good, $kind)['bytes']));
            $bad = $this->file($kind . '-overflow', $limit + 1);
            $this->denied(fn () => (new ControlProofGraph)->read($bad, $kind));
            $this->denied(fn () => PrivateProofFile::read($bad['path'], 0, $limit));
        }
        $large = $this->file('full.sql', 4194305);
        self::assertSame(4194305, strlen(PrivateProofFile::read($large['path'], 0)['bytes']));
    }

    public function test_shared_document_budget_is_not_reset_between_indexes(): void
    {
        self::assertTrue(class_exists(ControlProofGraph::class), 'Shared control graph budget is missing.');
        $graph = new ControlProofGraph;
        $first = $this->file('index-0', 4194304);
        $graph->read($first, 'history');
        $graph->read($first, 'history', [$this->dir . '/different-root']);
        self::assertSame(['bytes' => 4194304, 'documents' => 1], $graph->usage());
        for ($i = 1; $i < 8; $i++) {
            $graph->read($this->file('index-' . $i, 4194304), 'history');
        }
        self::assertSame(['bytes' => 33554432, 'documents' => 8], $graph->usage());
        $graph->read($first, 'history');
        $extra = $this->file('extra', 1);
        $this->denied(fn () => $graph->read($extra, 'control'));
        self::assertSame(['bytes' => 33554432, 'documents' => 8], $graph->usage());
    }

    public function test_reference_depth_and_cycle_are_bounded(): void
    {
        self::assertTrue(class_exists(ControlProofGraph::class), 'Bounded control graph traversal is missing.');
        $p = $this->file('leaf', 1);
        $ancestors = array_map(fn ($i) => $this->dir . '/parent-' . $i, range(1, 8));
        self::assertSame('a', (new ControlProofGraph)->read($p, 'control', $ancestors)['bytes']);
        $this->denied(fn () => (new ControlProofGraph)->read($p, 'control', [...$ancestors, $this->dir . '/parent-9']));
        $this->denied(fn () => (new ControlProofGraph)->read($p, 'control', [$p['path']]));
        $this->denied(fn () => (new ControlProofGraph)->read($p, 'unknown'));
    }

    public function test_changed_ordinary_file_or_reference_never_reuses_verified_bytes(): void
    {
        self::assertTrue(class_exists(ControlProofGraph::class));
        $p = $this->file('private', 1);
        $graph = new ControlProofGraph;
        $graph->read($p, 'control');
        rename($p['path'], $p['path'] . '.original');
        ProofFactory::write($p['path'], 'a');
        $this->denied(fn () => $graph->read($p, 'control'));
        $this->denied(fn () => (new ControlProofGraph)->read([...$p, 'unexpected' => true], 'control'));
        $this->denied(fn () => (new ControlProofGraph)->read([...$p, 'sha256' => str_repeat('b', 64)], 'control'));
        chmod($p['path'], 0644);
        $this->denied(fn () => (new ControlProofGraph)->read($p, 'control'));
        chmod($p['path'], 0600);
        link($p['path'], $p['path'] . '.hard');
        $this->denied(fn () => (new ControlProofGraph)->read($p, 'control'));
        unlink($p['path'] . '.hard');
        symlink($p['path'], $p['path'] . '.link');
        $this->denied(fn () => (new ControlProofGraph)->read(['path' => $p['path'] . '.link', 'sha256' => $p['sha256']], 'control'));
        $this->denied(fn () => (new ControlProofGraph)->readPath('php://memory', 'control'));
    }

    public function test_growth_or_equal_byte_replacement_during_read_is_denied(): void
    {
        // A bounded large read gives the parent time to stop the real reader
        // while its ordinary file descriptor is open; no production test hook.
        foreach (['grow', 'replace'] as $mutation) {
            $p = $this->file($mutation, 67108864);
            $pid = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                try {
                    PrivateProofFile::read($p['path'], 0, 67108864);
                    exit(0);
                } catch (RuntimeException) {
                    exit(17);
                }
            }
            $observed = false;
            $deadline = hrtime(true) + 5000000000;
            while (hrtime(true) < $deadline && is_dir('/proc/' . $pid . '/fd')) {
                foreach (glob('/proc/' . $pid . '/fd/*') as $fd) {
                    if (@readlink($fd) === $p['path']) {
                        posix_kill($pid, SIGSTOP);
                        pcntl_waitpid($pid, $stopped, WUNTRACED);
                        $observed = pcntl_wifstopped($stopped);
                        break 2;
                    }
                }
                usleep(100);
            }
            try {
                self::assertTrue($observed, 'Owned child must be stopped with its actual proof descriptor open.');
                if ($mutation === 'grow') {
                    file_put_contents($p['path'], 'b', FILE_APPEND);
                } else {
                    rename($p['path'], $p['path'] . '.old');
                    copy($p['path'] . '.old', $p['path']);
                    chmod($p['path'], 0600);
                }
            } finally {
                posix_kill($pid, SIGCONT);
                pcntl_waitpid($pid, $status);
            }
            self::assertSame(17, pcntl_wexitstatus($status), 'Changed proof read must deny without returning bytes.');
        }
    }
}
