<?php

namespace App\Services\Accounts;

use App\Models\AccountMovement;
use App\Models\AccountPostingIssue;
use App\Models\AccountWallet;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Retained verified income evidence; this context cannot mutate wallet funds. */
final class NativePostingIssue
{
    private static ?array $expected = null;

    private static ?int $recordId = null;

    public static function record(AccountWriteContext $context): AccountPostingIssue
    {
        if (DB::transactionLevel() === 0 || !in_array($context->action, ['deposit', 'downgrade'], true)) {
            throw new RuntimeException('Posting issues require a verified original incoming receipt.');
        }
        $context->assertReceiptFactsCurrent();
        User::whereKey($context->ownerId)->lockForUpdate()->firstOrFail();
        $wallet = AccountWallet::where('user_id', $context->ownerId)->where('currency_code', $context->currency)->lockForUpdate()->firstOrFail();
        NativeOpeningHistory::assertEligible($wallet, $context->action, $context->receiptProof);
        if ($context->receiptProof['processed_at'] < $wallet->created_at->format('Y-m-d H:i:s') ||
            AccountMovement::where('source_key', $context->sourceKey())->lockForUpdate()->exists()) {
            throw new RuntimeException('Old or already posted income cannot become a new reconciliation issue.');
        }
        $attributes = ['wallet_id' => $wallet->id, 'user_id' => $context->ownerId, 'currency_code' => $context->currency,
            'source_key' => $context->sourceKey(), 'kind' => $context->action, 'principal' => $context->delta(),
            'proof' => $context->receiptProof, 'reason_code' => 'native_posting_required', 'resolved_movement_id' => null];
        $issue = AccountPostingIssue::where('source_key', $context->sourceKey())->lockForUpdate()->first();
        if ($issue) {
            if ($issue->only(array_keys($attributes)) !== $attributes) {
                throw new RuntimeException('Incoming reconciliation evidence changed.');
            }

            return $issue;
        }

        return self::during($attributes, null, fn () => AccountPostingIssue::create($attributes));
    }

    public static function resolve(AccountWriteContext $context, AccountMovement $movement): void
    {
        $issue = AccountPostingIssue::where('source_key', $context->sourceKey())->lockForUpdate()->first();
        if (!$issue) {
            return;
        }
        $context->assertReceiptFactsCurrent();
        $stored = AccountMovement::whereKey($movement->id)->lockForUpdate()->firstOrFail();
        if ($stored->source_key !== $context->sourceKey() || $stored->request_fingerprint !== $context->fingerprint($stored->request_key) ||
            $issue->proof !== $context->receiptProof || $issue->principal !== $stored->delta || $issue->wallet_id !== $stored->wallet_id ||
            $issue->user_id !== $stored->user_id || $issue->currency_code !== $stored->currency_code ||
            ($issue->resolved_movement_id !== null && $issue->resolved_movement_id !== $stored->id)) {
            throw new RuntimeException('Incoming issue resolution requires the exact persisted movement receipt.');
        }
        if ($issue->resolved_movement_id === null) {
            self::during(['resolved_movement_id' => $stored->id], $issue->id, fn () => $issue->update(['resolved_movement_id' => $stored->id]));
        }
    }

    private static function during(array $expected, ?int $id, Closure $write): mixed
    {
        if (self::$expected !== null) {
            throw new RuntimeException('Nested incoming evidence writes are not allowed.');
        }
        self::$expected = $expected;
        self::$recordId = $id;
        try {
            return $write();
        } finally {
            self::$expected = null;
            self::$recordId = null;
        }
    }

    public static function assertRecordWrite(AccountPostingIssue $record, string $action): void
    {
        $expected = self::$expected;
        if (DB::transactionLevel() === 0 || $expected === null || $action === 'delete' || $record->getKey() !== self::$recordId ||
            ($action === 'create' ? array_diff(array_keys($record->getAttributes()), array_keys($expected)) :
                array_diff(array_keys($record->getDirty()), [...array_keys($expected), 'updated_at'])) || $record->only(array_keys($expected)) !== $expected) {
            throw new RuntimeException('Incoming reconciliation evidence is immutable outside verified receipt transitions.');
        }
    }
}
