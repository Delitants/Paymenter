<?php

namespace Tests\Fixtures\Opening;

use App\Services\BillmanagerMigration\BalancePreview;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\FinancialImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Support\Facades\DB;

final class MigrationFixture
{
    public static function input(array $overrides = [], string $captured = '2026-01-01T00:00:00Z', string $cutoff = '2024-01-01 00:00:00'): array
    {
        $p = ProofFactory::signed('account-opening-fence', ProofFactory::fence());
        $dir = $p['dir'];
        $tables = array_replace(['accounts' => [['id' => '10']], 'users' => [['id' => '11', 'account' => '10', 'enabled' => 'on', 'level' => '16',
            'email' => 'opening@example.invalid', 'realname' => 'Synthetic Opening', 'last_login' => '2025-01-01 00:00:00']],
            'currencies' => [['id' => '1', 'iso' => 'USD']], 'subaccounts' => [['id' => '31', 'account' => '10', 'currency' => '1', 'balance' => '12.3450', 'creditlimit' => '0.0000', 'allowpostpaid' => 'off', 'active' => 'on']],
            'payments' => [], 'expenses' => [], 'invoices' => [], 'invoice_items' => []], $overrides);
        ProofFactory::write($dir . '/snapshot.json', json_encode(['schema_version' => 1, 'source_host' => 'source.example.invalid', 'login_cutoff' => '2024-01-01 00:00:00',
            'captured_at_utc' => $captured, 'source_timezone' => 'UTC', 'tables' => $tables]));
        ProofFactory::write($dir . '/snapshot.json.sha256', hash_file('sha256', $dir . '/snapshot.json'));
        $snapshot = Snapshot::load($dir . '/snapshot.json', 'source.example.invalid', '2024-01-01 00:00:00');
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => 'source.example.invalid', 'snapshot_sha256' => $snapshot->checksum(), 'status' => 'prepared_partial']);
        $context = new ImportContext($id, 'source.example.invalid');
        (new CustomerImporter)->import($snapshot, $context);
        (new FinancialImporter)->import($snapshot, $context);
        $preview = (new BalancePreview)->prepare($snapshot, $context, $cutoff, 'preserve');
        $policy = ['schema_version' => 1, 'purpose' => 'inactive-opening-policy', 'inactive_only' => true, 'credit_limit_policy' => 'preserve',
            'rounding' => 'positive_half_up_cents_debt_exact_four', 'shared_access' => 'owner_spend_members_read_only', 'currencies' => ['USD'],
            'reviewed_reasons' => ['debt', 'credit_limit_activation', 'shared_account', 'below_native_precision']];
        ProofFactory::write($dir . '/policy.json', json_encode($policy, JSON_UNESCAPED_SLASHES));
        $eligible = [];
        $users = [];
        foreach ($tables['users'] as $user) {
            if ($user['last_login'] >= $cutoff) {
                $eligible[] = $user['account'];
                $users[] = $user['id'];
            }
        }
        $population = ['schema_version' => 1, 'purpose' => 'opening-population-review', 'source_identity' => 'source.example.invalid', 'snapshot_sha256' => $snapshot->checksum(),
            'activation_cutoff' => $cutoff, 'selected_account_ids' => array_column($tables['accounts'], 'id'), 'eligible_account_ids' => array_values(array_unique($eligible)), 'eligible_source_user_ids' => $users];
        ProofFactory::write($dir . '/population.json', json_encode($population));
        $refs = [];
        foreach (['snapshot' => 'snapshot.json', 'snapshot_checksum' => 'snapshot.json.sha256', 'policy' => 'policy.json', 'population_review' => 'population.json'] as $key => $file) {
            $refs[$key] = ['path' => $dir . '/' . $file, 'sha256' => hash_file('sha256', $dir . '/' . $file)];
        }
        $scope = [];
        foreach ($preview['entries'] as $row) {
            $scope[] = ['source_account' => $row['source_account_id'], 'owner_id' => $row['owner_user_id'], 'members' => $row['member_user_ids'], 'currency' => $row['currency'],
                'opening' => $row['exact_amount'], 'limit' => $row['credit_limit'], 'reviewed_reasons' => $row['review_reasons']];
        }
        $input = ['schema_version' => 1, 'purpose' => 'billmanager-inactive-opening-input', 'source_identity' => 'source.example.invalid', 'login_cutoff' => '2024-01-01 00:00:00',
            'activation_cutoff' => $cutoff, 'source_timezone' => 'UTC', 'import_id' => $id, 'scope' => $scope, 'references' => $refs];
        self::writeInput($dir . '/input.json', $input);

        return ['path' => $dir . '/input.json', 'dir' => $dir, 'input' => $input, 'context' => $context, 'owner_id' => $scope[0]['owner_id']];
    }

    public static function writeInput(string $path, array $input): void
    {
        ProofFactory::write($path, json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
