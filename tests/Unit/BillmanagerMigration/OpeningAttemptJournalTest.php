<?php

namespace Tests\Unit\BillmanagerMigration;

use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use App\Services\BillmanagerMigration\Opening\OpeningAttemptJournal;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OpeningAttemptJournalTest extends TestCase
{
    private static function bytes(): string
    {
        $header = CanonicalPolicy::bytes(['schema_version' => 1, 'purpose' => 'account-opening-attempt-header', 'bundle_sha256' => str_repeat('a', 64), 'grant_sha256' => str_repeat('b', 64), 'nonce' => str_repeat('c', 64)]);
        $row = ['schema_version' => 1, 'purpose' => 'account-opening-attempt', 'bundle_sha256' => str_repeat('a', 64), 'grant_sha256' => str_repeat('b', 64), 'previous_sha256' => hash('sha256', $header), 'sequence' => 1,
            'source_account' => 'fictional-account', 'owner_id' => 1, 'currency' => 'USD', 'allocators' => ['account_wallets' => 1, 'credits' => 1, 'account_opening_receipts' => 1],
            'budgets' => ['account_wallets' => 1, 'credits' => 0, 'account_opening_receipts' => 1], 'created_at' => '2026-01-01T00:00:00Z'];

        return $header . "\n" . CanonicalPolicy::bytes($row) . "\n";
    }

    public function test_complete_chain_preserves_original_approval_and_bounded_zero_cash_allocation(): void
    {
        $rows = OpeningAttemptJournal::decodeRecords(self::bytes(), str_repeat('a', 64), str_repeat('b', 64));
        self::assertCount(2, $rows);
        self::assertSame(0, $rows[1]['budgets']['credits']);
        self::assertSame($rows[0]['sha256'], $rows[1]['previous_sha256']);
    }

    public function test_initial_batch_attempt_is_bounded_and_shares_the_original_hash_chain(): void
    {
        $header = explode("\n", self::bytes())[0];
        $record = ['schema_version' => 1, 'purpose' => 'account-opening-batch-attempt', 'bundle_sha256' => str_repeat('a', 64), 'grant_sha256' => str_repeat('b', 64),
            'previous_sha256' => hash('sha256', $header), 'sequence' => 1, 'allocators' => ['account_opening_batches' => 1], 'budgets' => ['account_opening_batches' => 1], 'created_at' => '2026-01-01T00:00:00Z'];
        $bytes = $header . "\n" . CanonicalPolicy::bytes($record) . "\n";
        $rows = OpeningAttemptJournal::decodeRecords($bytes, str_repeat('a', 64), str_repeat('b', 64));
        self::assertSame('account-opening-batch-attempt', $rows[1]['purpose']);
        self::assertSame(['account_opening_batches' => 1], $rows[1]['budgets']);
        $record['budgets']['account_opening_batches'] = 2;
        $this->expectException(RuntimeException::class);
        OpeningAttemptJournal::decodeRecords($header . "\n" . CanonicalPolicy::bytes($record) . "\n", str_repeat('a', 64), str_repeat('b', 64));
    }

    public static function malformed(): array
    {
        $bytes = self::bytes();

        return [['{}' . "\n"], [substr($bytes, 0, -1)], [$bytes . "{}\n"], [str_replace('"sequence":1', '"sequence":2', $bytes)],
            [str_replace('"previous_sha256":"', '"previous_sha256":"f', $bytes)],
            [str_replace('"budgets":{"account_opening_receipts":1,"account_wallets":1', '"budgets":{"account_opening_receipts":1,"account_wallets":2', $bytes)]];
    }

    #[DataProvider('malformed')]
    public function test_incomplete_changed_or_unbounded_journal_is_denied(string $bytes): void
    {
        $this->expectException(RuntimeException::class);
        OpeningAttemptJournal::decodeRecords($bytes, str_repeat('a', 64), str_repeat('b', 64));
    }

    public function test_omitted_first_allocator_is_not_a_schema_change(): void
    {
        $ddl = 'CREATE TABLE `fictional` (`id` bigint NOT NULL AUTO_INCREMENT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        self::assertSame(OpeningTargetState::normalizeDefinition($ddl), OpeningTargetState::normalizeDefinition(str_replace(' DEFAULT', ' AUTO_INCREMENT=12 DEFAULT', $ddl)));
        self::assertStringContainsString('NOT NULL AUTO_INCREMENT)', OpeningTargetState::normalizeDefinition($ddl));
    }
}
