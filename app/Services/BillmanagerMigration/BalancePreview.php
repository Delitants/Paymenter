<?php

namespace App\Services\BillmanagerMigration;

use App\Models\BillmanagerRecord;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BalancePreview
{
    public function prepare(Snapshot $snapshot, ImportContext $context, string $activationCutoff): array
    {
        (new FinancialReconciler)->compare($snapshot, $context);
        $owners = [];
        $members = [];
        $eligible = [];
        foreach ($snapshot->rows('accounts') as $source) {
            $mapping = DB::table('billmanager_mappings')->where([
                'source_host' => $context->sourceHost, 'source_table' => 'accounts', 'source_id' => (string) $source['id'],
                'target_table' => 'billmanager_accounts',
            ])->sole();
            $account = DB::table('billmanager_accounts')->where('id', $mapping->target_id)->sole();
            if ((string) $account->source_account_id !== (string) $source['id']) {
                throw new RuntimeException('Account mapping conflicts with source identity');
            }
            $this->verifyCustomerArchive($mapping, $source, 'accounts');
            $expected = [];
            foreach ($snapshot->rows('users') as $user) {
                if ((string) $user['account'] !== (string) $source['id']) {
                    continue;
                }
                $userMapping = DB::table('billmanager_mappings')->where([
                    'source_host' => $context->sourceHost, 'source_table' => 'users', 'source_id' => (string) $user['id'],
                    'target_table' => 'users',
                ])->sole();
                $id = $userMapping->target_id;
                $native = User::findOrFail($id);
                if (mb_strtolower(trim($native->email)) !== mb_strtolower(trim($user['email']))) {
                    throw new RuntimeException('Mapped customer identity requires reconciliation');
                }
                $this->verifyCustomerArchive($userMapping, $user, 'users');
                if (! MigrationHold::isHeld($native)) {
                    throw new RuntimeException('Balance preview requires held source members');
                }
                $expected[(int) $user['id']] = (int) $id;
                if ($user['last_login'] >= $activationCutoff) {
                    $eligible[$source['id']] = true;
                }
            }
            $actual = DB::table('billmanager_members')->where('account_id', $account->id)->pluck('user_id', 'source_user_id')->map(fn ($id) => (int) $id)->all();
            ksort($expected);
            ksort($actual);
            if (! $expected || $expected !== $actual || ! in_array((int) $account->owner_user_id, $expected, true)) {
                throw new RuntimeException('Account owner or membership requires reconciliation');
            }
            if (in_array((int) $account->owner_user_id, $owners, true)) {
                throw new RuntimeException('Multiple source accounts resolve to one wallet owner');
            }
            $owners[$source['id']] = (int) $account->owner_user_id;
            $members[$source['id']] = array_values($expected);
        }

        $currencies = array_column($snapshot->rows('currencies'), 'iso', 'id');
        $entries = [];
        $identities = [];
        $counts = ['entries' => 0, 'eligible' => 0, 'positive' => 0, 'zero' => 0, 'debt' => 0, 'excluded' => 0, 'review_required' => 0];
        foreach ($snapshot->rows('subaccounts') as $row) {
            $account = $row['account'];
            if (! isset($owners[$account])) {
                throw new RuntimeException('Balance account has no verified owner');
            }
            $currency = $currencies[$row['currency']] ?? throw new RuntimeException('Unknown balance currency');
            $identity = $account.':'.$currency;
            if (isset($identities[$identity])) {
                throw new RuntimeException('Multiple balances for one source account and currency');
            }
            $identities[$identity] = true;
            $exact = BigDecimal::of(FinancialRows::decimal($row['balance']));
            $rounded = $exact->toScale(2, RoundingMode::HALF_UP);
            $creditLimit = FinancialRows::decimal($row['creditlimit']);
            $isEligible = isset($eligible[$account]);
            $reasons = [];
            if (! DB::table('currencies')->where('code', $currency)->exists()) {
                $reasons[] = 'native_currency_unavailable';
            }
            if ($rounded->abs()->isGreaterThan('999999999999999.99')) {
                $reasons[] = 'native_amount_out_of_range';
            }
            if (! $exact->isNegative() && $exact->isPositive() && $rounded->isZero()) {
                $reasons[] = 'below_native_precision';
            }
            if ($exact->isNegative()) {
                $reasons[] = 'debt';
            }
            if (! BigDecimal::of($creditLimit)->isZero()) {
                $reasons[] = 'credit_limit_policy';
            }
            if (($row['allowpostpaid'] ?? null) !== 'off') {
                $reasons[] = 'postpaid_policy';
            }
            if (($row['active'] ?? null) !== 'on') {
                $reasons[] = 'inactive_or_unknown_subaccount';
            }
            if (count($members[$account]) > 1) {
                $reasons[] = 'shared_account';
            }
            $provisional = [];
            foreach ($snapshot->rows('payments') as $payment) {
                if ((string) $payment['subaccount'] === (string) $row['id'] && (int) $payment['status'] === 3
                    && (! isset($payment['usedamount']) || BigDecimal::of(FinancialRows::decimal($payment['subaccountamount']))
                        ->minus(FinancialRows::decimal($payment['usedamount']))->isPositive())) {
                    $provisional[] = (string) $payment['id'];
                }
            }
            if ($provisional) {
                $reasons[] = 'provisional_funds';
            }
            sort($reasons);
            sort($provisional, SORT_NATURAL);
            $disposition = ! $isEligible ? 'excluded' : ($exact->isNegative() ? 'debt_review' : ($exact->isZero() ? 'zero' : 'credit_proposal'));
            $entries[] = ['source_account_id' => (string) $account, 'source_subaccount_id' => (string) $row['id'],
                'currency' => $currency, 'owner_user_id' => $owners[$account], 'member_user_ids' => $members[$account],
                'exact_amount' => (string) $exact, 'rounded_amount' => (string) $rounded,
                'rounding_delta' => (string) $rounded->minus($exact), 'credit_limit' => $creditLimit,
                'disposition' => $disposition, 'proposed_credit' => $isEligible && $exact->isPositive() ? (string) $rounded : '0.00',
                'review_reasons' => $reasons, 'provisional_payment_ids' => $provisional];
            $counts['entries']++;
            $counts[$isEligible ? 'eligible' : 'excluded']++;
            if ($isEligible) {
                $counts[$exact->isNegative() ? 'debt' : ($exact->isZero() ? 'zero' : 'positive')]++;
            }
            if ($reasons) {
                $counts['review_required']++;
            }
        }
        usort($entries, fn ($a, $b) => [(int) $a['source_account_id'], $a['currency']] <=> [(int) $b['source_account_id'], $b['currency']]);

        return ['schema_version' => 1, 'status' => 'balance_preview', 'application_authorized' => false,
            'source_host' => $snapshot->sourceHost(), 'snapshot_sha256' => $snapshot->checksum(),
            'captured_at_utc' => $snapshot->capturedAt(), 'source_timezone' => $snapshot->sourceTimezone(),
            'activation_cutoff' => $activationCutoff, 'counts' => $counts, 'entries' => $entries];
    }

    private function verifyCustomerArchive(object $mapping, array $source, string $table): void
    {
        if (! DB::table('billmanager_imports')->where(['id' => $mapping->import_id, 'source_host' => $mapping->source_host])->exists()) {
            throw new RuntimeException('Customer mapping source lineage requires reconciliation');
        }
        $record = BillmanagerRecord::where(['import_id' => $mapping->import_id, 'source_table' => $table, 'source_id' => (string) $source['id']])->sole();
        $payload = $record->payload;
        $account = $table === 'accounts' ? $source['id'] : $source['account'];
        if ((string) $record->source_account_id !== (string) $account
            || (string) ($payload['id'] ?? '') !== (string) $source['id']
            || ! hash_equals($record->payload_sha256, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
            || ($table === 'users' && ((string) ($payload['account'] ?? '') !== (string) $account
                || mb_strtolower(trim($payload['email'] ?? '')) !== mb_strtolower(trim($source['email']))))) {
            throw new RuntimeException('Archived customer identity requires reconciliation');
        }
    }
}
