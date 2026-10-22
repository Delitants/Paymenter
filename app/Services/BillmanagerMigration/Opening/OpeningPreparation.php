<?php

namespace App\Services\BillmanagerMigration\Opening;

use App\Models\AccountWallet;
use App\Models\Credit;
use App\Services\Accounts\AccountAmount;
use App\Services\Accounts\NativeOpeningHistory;
use App\Services\BillmanagerMigration\BalancePreview;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OpeningPreparation
{
    public function prepare(OpeningBundle $bundle): array
    {
        return $this->prepareInternal($bundle, true);
    }

    public function sourceFacts(OpeningBundle $bundle): array
    {
        if (!$bundle->executable()) {
            throw new RuntimeException('Source replay requires the original executable bundle.');
        }

        return $this->prepareInternal($bundle, false);
    }

    private function prepareInternal(OpeningBundle $bundle, bool $initial): array
    {
        if ($bundle->executable()) {
            $input = OpeningBundle::load($bundle->reference('preparation_input'));
            if ($input->executable()) {
                throw new RuntimeException('Preparation input cannot be executable.');
            }
            $report = $this->prepareInternal($input, $initial);
            $stored = StrictProofJson::decode(PrivateProofFile::read($bundle->reference('preparation'), 0)['bytes']);
            $candidates = array_values(array_filter($report['accounts'], fn ($row) => $row['disposition'] === 'candidate'));
            if (CanonicalPolicy::bytes($stored) !== CanonicalPolicy::bytes($report) || CanonicalPolicy::bytes($bundle->accounts()) !== CanonicalPolicy::bytes($candidates) || $bundle->data()['scope_fingerprint'] !== hash('sha256', CanonicalPolicy::bytes($candidates))) {
                throw new RuntimeException('Executable preparation or reviewed scope changed.');
            }
            foreach (['source_identity', 'login_cutoff', 'activation_cutoff', 'source_timezone', 'import_id'] as $key) {
                if ($input->data()[$key] !== $bundle->data()[$key]) {
                    throw new RuntimeException('Executable input lineage changed.');
                }
            }
            foreach (['snapshot', 'snapshot_checksum', 'policy', 'population_review'] as $name) {
                if ($input->data()['references'][$name] !== $bundle->data()['references'][$name]) {
                    throw new RuntimeException('Executable evidence lineage changed.');
                }
            }

            return $report;
        }
        $snapshot = $bundle->snapshot();
        $data = $bundle->data();
        $policy = $bundle->policy();
        $context = $bundle->context();
        $preview = (new BalancePreview)->prepare($snapshot, $context, $data['activation_cutoff'], 'preserve');
        $population = StrictProofJson::decode(PrivateProofFile::read($bundle->reference('population_review'), 0)['bytes']);
        ProofSchema::keys($population, ['schema_version', 'purpose', 'source_identity', 'snapshot_sha256', 'activation_cutoff', 'selected_account_ids', 'eligible_account_ids', 'eligible_source_user_ids']);
        $eligibleAccounts = [];
        $eligibleUsers = [];
        foreach ($snapshot->rows('users') as $user) {
            if ($user['last_login'] >= $data['activation_cutoff']) {
                $eligibleAccounts[] = (string) $user['account'];
                $eligibleUsers[] = (string) $user['id'];
            }
        }
        $sorted = static function (array $values): array {
            $values = array_values(array_unique($values));
            sort($values, SORT_STRING);

            return $values;
        };
        if ($population['schema_version'] !== 1 || $population['purpose'] !== 'opening-population-review' || $population['source_identity'] !== $snapshot->sourceHost() ||
            $population['snapshot_sha256'] !== $snapshot->checksum() || $population['activation_cutoff'] !== $data['activation_cutoff'] ||
            $sorted($population['selected_account_ids']) !== $sorted(array_map('strval', array_column($snapshot->rows('accounts'), 'id'))) ||
            $sorted($population['eligible_account_ids']) !== $sorted($eligibleAccounts) || $sorted($population['eligible_source_user_ids']) !== $sorted($eligibleUsers)) {
            throw new RuntimeException('Complete frozen source population requires reconciliation.');
        }
        $expectedScope = [];
        $accounts = [];
        $counts = ['candidate' => 0, 'blocked' => 0, 'excluded' => 0];
        foreach ($preview['entries'] as $row) {
            $expectedScope[] = ['source_account' => $row['source_account_id'], 'owner_id' => $row['owner_user_id'], 'members' => $row['member_user_ids'], 'currency' => $row['currency'],
                'opening' => $row['exact_amount'], 'limit' => $row['credit_limit'], 'reviewed_reasons' => $row['review_reasons']];
            $reasons = array_values(array_diff($row['review_reasons'], $policy['reviewed_reasons']));
            $ownerRecent = false;
            foreach ($snapshot->rows('users') as $user) {
                if ((string) $user['account'] === $row['source_account_id'] && $context->mappedId('users', (string) $user['id']) === $row['owner_user_id'] && $user['last_login'] >= $data['activation_cutoff']) {
                    $ownerRecent = true;
                }
            }
            if (!$ownerRecent) {
                $reasons[] = 'owner_outside_activation_window';
            }
            if (!in_array($row['currency'], $policy['currencies'], true)) {
                $reasons[] = 'unsupported_currency';
            }
            $wallets = AccountWallet::where('user_id', $row['owner_user_id'])->where('currency_code', $row['currency'])->get();
            // An executable replay validates original receipts; preparation never repairs a pre-existing wallet.
            if ($initial && $wallets->isNotEmpty()) {
                $reasons[] = 'preexisting_wallet';
            }
            $cash = Credit::where('user_id', $row['owner_user_id'])->where('currency_code', $row['currency'])->get();
            if ($initial && ($cash->count() > 1 || ($cash->isNotEmpty() && AccountAmount::parse($cash->first()->getRawOriginal('amount'))->exact() !== '0.0000'))) {
                $reasons[] = 'unmatched_cash';
            }
            $source = AccountAmount::parse($row['exact_amount']);
            $effective = $source->compare(AccountAmount::parse('0')) > 0 ? AccountAmount::parse($source->halfUpCents()) : $source;
            $limit = AccountAmount::nonnegative($row['credit_limit']);
            $disposition = $row['disposition'] === 'excluded' ? 'excluded' : ($reasons ? 'blocked' : 'candidate');
            $counts[$disposition]++;
            $archive = [];
            $memberMappings = [];
            foreach ([['accounts', $row['source_account_id']], ...array_map(fn ($user) => ['users', (string) $user['id']], array_values(array_filter($snapshot->rows('users'), fn ($user) => (string) $user['account'] === $row['source_account_id'])))] as [$table, $id]) {
                $mapping = DB::table('billmanager_mappings')->where(['source_host' => $data['source_identity'], 'source_table' => $table, 'source_id' => $id])->sole();
                $record = (array) DB::table('billmanager_records')->where(['import_id' => $mapping->import_id, 'source_table' => $table, 'source_id' => $id])->sole();
                $archive[] = ['mapping' => (array) $mapping, 'record' => $record];
                if ($table === 'users') {
                    $memberMappings[] = ['source_id' => $id, 'native_id' => $mapping->target_id];
                }
            }
            $accounts[] = [...end($expectedScope), 'source_system' => 'billmanager:' . $data['source_identity'], 'member_mappings' => $memberMappings, 'effective_opening' => $effective->exact(),
                'rounding_delta' => $effective->subtract($source)->exact(), 'limit_exact' => $limit->exact(), 'disposition' => $disposition, 'blocked_reasons' => $reasons,
                'archive_fingerprint' => hash('sha256', CanonicalPolicy::bytes($archive)), 'hold_fingerprint' => self::holdFingerprint(),
                'receipt_exclusions' => NativeOpeningHistory::current($row['owner_user_id'], $row['currency'])];
        }
        if ($expectedScope !== $bundle->accounts()) {
            throw new RuntimeException('Opening scope or reason acknowledgements changed.');
        }

        return ['schema_version' => 1, 'purpose' => 'inactive-opening-preparation', 'application_authorized' => false, 'source_identity' => $data['source_identity'],
            'snapshot_sha256' => $snapshot->checksum(), 'input_sha256' => $bundle->digest(), 'population_fingerprint' => hash_file('sha256', $bundle->reference('population_review')),
            'policy_sha256' => hash('sha256', CanonicalPolicy::bytes($policy)), 'login_cutoff' => $data['login_cutoff'], 'activation_cutoff' => $data['activation_cutoff'],
            'import_id' => $data['import_id'], 'counts' => $counts, 'accounts' => $accounts];
    }

    public static function holdFingerprint(bool $locked = false): string
    {
        $query = DB::table('billmanager_holds')->orderBy('id');
        if ($locked) {
            $query->lockForUpdate();
        }

        return hash('sha256', CanonicalPolicy::bytes(array_map(fn ($row) => (array) $row, $query->get()->all())));
    }
}
