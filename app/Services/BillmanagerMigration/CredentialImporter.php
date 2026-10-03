<?php

namespace App\Services\BillmanagerMigration;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CredentialImporter
{
    public function import(Snapshot $snapshot, ImportContext $context, string $encryptedPath, bool $disableUnsupportedMfa = false): ImportReport
    {
        $bundle = json_decode(Crypt::decryptString(file_get_contents($encryptedPath)), true, flags: JSON_THROW_ON_ERROR);
        if (($bundle['schema_version'] ?? null) !== 1 || ($bundle['source_host'] ?? null) !== $snapshot->sourceHost() || $context->sourceHost !== $snapshot->sourceHost()) {
            throw new RuntimeException('Credential source does not match snapshot');
        }
        $users = array_column($snapshot->rows('users'), null, 'id');
        $credentials = $bundle['credentials'] ?? [];
        $ids = array_column($credentials, 'source_user_id');
        $expected = array_map('strval', array_keys($users));
        sort($ids);
        sort($expected);
        if ($ids !== $expected) {
            throw new RuntimeException('Credential identities do not match selected users');
        }

        return DB::transaction(function () use ($users, $credentials, $context, $disableUnsupportedMfa) {
            $counts = ['installed' => 0, 'otp_preserved' => 0, 'otp_disabled' => 0, 'blocked' => 0, 'already_upgraded' => 0];
            $verifier = new LegacyCredentialVerifier;
            foreach ($credentials as $credential) {
                $id = (string) $credential['source_user_id'];
                $user = User::findOrFail($context->mappedId('users', $id));
                $alias = strtolower(trim($users[$id]['name'] ?? $users[$id]['email']));
                if ($alias === '' || strlen($alias) > 255) {
                    throw new RuntimeException('Invalid source login name');
                }
                $existing = DB::table('billmanager_login_aliases')->where('alias', $alias)->value('user_id');
                if (($existing && $existing != $user->id) || User::where('email', $alias)->where('id', '!=', $user->id)->exists()) {
                    throw new RuntimeException('Source username collides with another identity');
                }
                DB::table('billmanager_login_aliases')->insertOrIgnore(['user_id' => $user->id, 'alias' => $alias]);
                $before = DB::table('billmanager_legacy_credentials')->where('user_id', $user->id)->first();
                if ($before && ($before->upgraded_at || !hash_equals($before->password_fingerprint ?? '', hash('sha256', $user->password)))) {
                    $counts['already_upgraded']++;

                    continue;
                }
                $secret = $credential['totp_secret'] ?? null;
                $requiresMfa = (bool) ($credential['requires_mfa'] ?? false);
                $valid = preg_match('/^[A-Z2-7]{16,128}={0,6}$/i', trim($secret ?? '')) === 1;
                if ($requiresMfa && !$valid && $disableUnsupportedMfa) {
                    DB::table('users')->where('id', $user->id)->update(['tfa_secret' => null]);
                    $requiresMfa = false;
                    $counts['otp_disabled']++;
                    $context->archive('credential_migration_events', $id, ['source_user_id' => $id, 'event' => 'unsupported_otp_disabled', 'operator_option' => true]);
                }
                $verifier->install($user, $credential['hash'], $secret, $requiresMfa);
                $state = DB::table('billmanager_legacy_credentials')->where('user_id', $user->id)->first();
                $counts[$state->login_blocked ? 'blocked' : 'installed']++;
                if ($requiresMfa && $valid) {
                    $counts['otp_preserved']++;
                }
            }

            return new ImportReport('credentials_imported', $counts);
        });
    }
}
