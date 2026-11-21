<?php

namespace App\Services\BillmanagerMigration;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class CustomerImporter
{
    public function import(Snapshot $snapshot, ImportContext $context): ImportReport
    {
        if ($snapshot->sourceHost() !== $context->sourceHost) {
            throw new RuntimeException('Snapshot source does not match import context');
        }

        return DB::transaction(function () use ($snapshot, $context) {
            $users = $snapshot->rows('users');
            usort($users, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
            $byAccount = [];
            $capture = Carbon::parse($snapshot->capturedAt())->utc();
            foreach ($users as $row) {
                if ((int) ($row['level'] ?? 0) !== 16 || $row['enabled'] !== 'on') {
                    throw new RuntimeException('Only enabled customer identities can be imported');
                }
                $email = mb_strtolower(trim($row['email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Invalid customer email for source user ' . $row['id']);
                }
                $mapped = $context->mappedId('users', (string) $row['id']);
                if ($mapped !== null) {
                    $user = User::findOrFail($mapped);
                    if (mb_strtolower(trim($user->email)) !== $email) {
                        throw new RuntimeException('Mapped customer email changed; explicit reconciliation required');
                    }
                } else {
                    if (User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists()) {
                        throw new RuntimeException('Customer email collision; explicit identity mapping required');
                    }
                    $name = preg_split('/\s+/u', trim($row['realname'] ?? ''), 2);
                    $password = Hash::make(Str::random(64));
                    $id = DB::table('users')->insertGetId([
                        'first_name' => $name[0] ?? '', 'last_name' => $name[1] ?? '',
                        'email' => $email, 'password' => $password, 'role_id' => null,
                        'email_verified_at' => ($row['emailverified'] ?? '') === 'on' ? $capture : null,
                        'created_at' => $capture, 'updated_at' => $capture,
                    ]);
                    $user = User::findOrFail($id);
                    $context->recordMapping('users', (string) $row['id'], 'users', $id);
                    DB::table('billmanager_legacy_credentials')->insert([
                        'user_id' => $id, 'credential' => Crypt::encryptString(''),
                        'password_fingerprint' => hash('sha256', $password),
                        'login_blocked' => true, 'block_reason' => 'Awaiting protected credential transfer',
                    ]);
                    DB::table('billmanager_holds')->insert([
                        'model_type' => $user->getMorphClass(), 'model_id' => $id, 'reason' => 'Awaiting billing handover',
                    ]);
                }
                $byAccount[$row['account']][] = ['user_id' => $user->id, 'source_user_id' => (int) $row['id']];
                $context->archive('users', (string) $row['id'], $row, (int) $row['account']);
            }
            foreach ($snapshot->rows('accounts') as $account) {
                $members = $byAccount[$account['id']] ?? [];
                if (!$members) {
                    throw new RuntimeException('Selected account has no eligible logins');
                }
                $id = $context->mappedId('accounts', (string) $account['id']);
                if ($id === null) {
                    $id = DB::table('billmanager_accounts')->insertGetId([
                        'source_account_id' => (int) $account['id'], 'owner_user_id' => $members[0]['user_id'],
                        'created_at' => $capture, 'updated_at' => $capture,
                    ]);
                    $context->recordMapping('accounts', (string) $account['id'], 'billmanager_accounts', $id);
                } elseif (!DB::table('billmanager_accounts')->where('id', $id)->exists()) {
                    throw new RuntimeException('Mapped account is missing');
                }
                foreach ($members as $member) {
                    $existing = DB::table('billmanager_members')->where('source_user_id', $member['source_user_id'])->first();
                    if ($existing && ((int) $existing->account_id !== $id || (int) $existing->user_id !== $member['user_id'])) {
                        throw new RuntimeException('Source account membership changed; explicit reconciliation required');
                    }
                    if (!$existing) {
                        DB::table('billmanager_members')->insert(['account_id' => $id] + $member);
                    }
                }
                $context->archive('accounts', (string) $account['id'], $account, (int) $account['id']);
            }

            return new ImportReport('customers_imported', ['users' => count($users), 'accounts' => count($byAccount)]);
        });
    }
}
