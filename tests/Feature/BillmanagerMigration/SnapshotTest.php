<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Services\BillmanagerMigration\Snapshot;
use PHPUnit\Framework\TestCase;

class SnapshotTest extends TestCase
{
    private array $files = [];

    private function fixture(array $changes = []): string
    {
        $data = array_replace_recursive([
            'schema_version' => 1,
            'source_host' => '192.0.2.10',
            'captured_at_utc' => '2026-09-28T10:00:00Z',
            'login_cutoff' => '2024-09-28 00:00:00',
            'tables' => [
                'accounts' => [['id' => '21']],
                'users' => [['id' => '11', 'account' => '21', 'enabled' => 'on', 'last_login' => '2025-01-01 00:00:00']],
                'items' => [['id' => '31', 'account' => '21']],
            ],
        ], $changes);
        $path = tempnam(sys_get_temp_dir(), 'bill-snapshot-');
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        $this->files[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            unlink($path);
            unlink($path . '.sha256');
        }
    }

    public function test_unchanged_selected_records_are_available_to_import(): void
    {
        $snapshot = Snapshot::load($this->fixture(), '192.0.2.10', '2024-09-28 00:00:00');
        $this->assertSame('21', $snapshot->rows('users')[0]['account']);
        $this->assertSame(['accounts' => 1, 'users' => 1, 'items' => 1], $snapshot->counts());
    }

    public function test_modified_payload_is_rejected_before_import(): void
    {
        $path = $this->fixture();
        file_put_contents($path, "\n", FILE_APPEND);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('checksum');
        Snapshot::load($path, '192.0.2.10', '2024-09-28 00:00:00');
    }

    public function test_duplicate_source_identity_is_rejected(): void
    {
        $path = $this->fixture(['tables' => ['accounts' => [['id' => '21'], ['id' => '21']]]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate');
        Snapshot::load($path, '192.0.2.10', '2024-09-28 00:00:00');
    }

    public function test_orphaned_service_ownership_is_rejected(): void
    {
        $path = $this->fixture(['tables' => ['items' => [['id' => '31', 'account' => '999']]]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('account');
        Snapshot::load($path, '192.0.2.10', '2024-09-28 00:00:00');
    }

    public function test_out_of_window_client_is_rejected_even_with_valid_digest(): void
    {
        $path = $this->fixture(['tables' => ['users' => [['last_login' => '2023-01-01 00:00:00']]]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cutoff');
        Snapshot::load($path, '192.0.2.10', '2024-09-28 00:00:00');
    }

    public function test_snapshot_of_another_host_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source');
        Snapshot::load($this->fixture(['source_host' => '192.0.2.20']), '192.0.2.10', '2024-09-28 00:00:00');
    }

    private function activitySnapshot(array $tables): string
    {
        return json_encode([
            'schema_version' => 2,
            'source_host' => '192.0.2.10',
            'captured_at_utc' => '2026-09-28T10:00:00Z',
            'activity_cutoff' => '2022-01-01 00:00:00',
            'consistent_snapshot' => true,
            'tables' => array_replace([
                'accounts' => [['id' => '21', 'registration_date' => '2020-01-01']],
                'users' => [['id' => '11', 'account' => '21', 'level' => '16', 'enabled' => 'off', 'last_login' => null]],
            ], $tables),
        ], JSON_THROW_ON_ERROR);
    }

    public function test_activity_scope_keeps_a_current_service_and_its_older_history(): void
    {
        $payload = $this->activitySnapshot([
            'items' => [['id' => '31', 'account' => '21', 'status' => '2', 'opendate' => '2020-01-01']],
            'subaccounts' => [['id' => '41', 'account' => '21']],
            'payments' => [['id' => '51', 'subaccount' => '41', 'paydate' => '2020-01-01']],
            'tickets' => [['id' => '61', 'account_client' => '21', 'date_start' => '2020-01-01']],
        ]);

        $snapshot = Snapshot::fromBytes($payload, hash('sha256', $payload), '192.0.2.10', '2022-01-01 00:00:00', activityScope: true);
        $this->assertTrue($snapshot->hasActivityScope());
        $this->assertSame('off', $snapshot->rows('users')[0]['enabled']);
        $this->assertSame('51', $snapshot->rows('payments')[0]['id']);
        $this->assertSame('61', $snapshot->rows('tickets')[0]['id']);
    }

    public function test_payment_on_the_cutoff_qualifies_without_a_recent_login(): void
    {
        $payload = $this->activitySnapshot([
            'subaccounts' => [['id' => '41', 'account' => '21']],
            'payments' => [['id' => '51', 'subaccount' => '41', 'paydate' => '2022-01-01']],
        ]);

        $snapshot = Snapshot::fromBytes($payload, hash('sha256', $payload), '192.0.2.10', '2022-01-01 00:00:00', activityScope: true);
        $this->assertCount(1, $snapshot->rows('accounts'));
    }

    public function test_old_closed_service_cannot_qualify_an_inactive_account(): void
    {
        $payload = $this->activitySnapshot([
            'historical_items' => [['id' => '31', 'account' => '21', 'status' => '4', 'expiredate' => '2021-12-31']],
            'tickets' => [['id' => '61', 'account_client' => '21', 'date_start' => '2021-01-01']],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('activity cutoff');
        Snapshot::fromBytes($payload, hash('sha256', $payload), '192.0.2.10', '2022-01-01 00:00:00', activityScope: true);
    }

    public function test_activity_snapshot_requires_explicit_selection(): void
    {
        $payload = $this->activitySnapshot(['accounts' => [['id' => '21', 'registration_date' => '2023-01-01']]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schema');
        Snapshot::fromBytes($payload, hash('sha256', $payload), '192.0.2.10', '2022-01-01 00:00:00');
    }
}
