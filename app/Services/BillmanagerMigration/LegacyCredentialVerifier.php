<?php

namespace App\Services\BillmanagerMigration;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class LegacyCredentialVerifier
{
    public function findByLogin(string $login): ?User
    {
        $login = strtolower(trim($login));
        $emailUser = User::where('email', $login)->first();
        $aliasId = DB::table('billmanager_login_aliases')->where('alias', $login)->value('user_id');
        if ($emailUser && $aliasId && $emailUser->id != $aliasId) {
            return null;
        }

        return $emailUser ?? ($aliasId ? User::find($aliasId) : null);
    }

    private function isMapped(User $user): bool
    {
        return DB::table('billmanager_mappings')->where([
            'source_table' => 'users', 'target_table' => 'users', 'target_id' => $user->id,
        ])->exists();
    }

    public function install(User $user, string $hash, ?string $totpSecret, bool $requiresMfa): void
    {
        if (!$this->isMapped($user)) {
            throw new RuntimeException('Legacy credentials require an explicit source identity mapping');
        }
        DB::transaction(function () use ($user, $hash, $totpSecret, $requiresMfa) {
            $current = User::lockForUpdate()->findOrFail($user->id);
            $query = DB::table('billmanager_legacy_credentials')->where('user_id', $user->id);
            $existing = $query->first();
            if ($existing && ($existing->upgraded_at || !hash_equals($existing->password_fingerprint ?? '', hash('sha256', $current->password)))) {
                // Replaying a credential transfer must never revive an old password after a reset.
                return;
            }
            if ($existing) {
                $previous = Crypt::decryptString($existing->credential);
                if ($previous !== '' && !hash_equals($previous, $hash)) {
                    throw new RuntimeException('Legacy credential changed; explicit reconciliation required');
                }
            }
            $supportedHash = preg_match('/^\$(1|5|6|2[aby])\$/', $hash) === 1;
            $secret = strtoupper(trim($totpSecret ?? ''));
            $validFactor = preg_match('/^[A-Z2-7]{16,128}={0,6}$/', $secret) === 1;
            $blocked = !$supportedHash || ($requiresMfa && !$validFactor);
            if ($validFactor) {
                DB::table('users')->where('id', $current->id)->update(['tfa_secret' => Crypt::encryptString($secret)]);
            }
            $values = [
                'credential' => Crypt::encryptString($hash),
                'password_fingerprint' => hash('sha256', $current->password),
                'login_blocked' => $blocked,
                'block_reason' => $blocked ? 'Legacy password or required MFA needs a verified reset' : null,
            ];
            if ($existing) {
                $query->update($values);
            } else {
                DB::table('billmanager_legacy_credentials')->insert(['user_id' => $current->id] + $values);
            }
        });
        $user->refresh();
    }

    public function verifyAndUpgrade(User $user, string $password): bool
    {
        return DB::transaction(function () use ($user, $password) {
            $current = User::lockForUpdate()->findOrFail($user->id);
            $query = DB::table('billmanager_legacy_credentials')->where('user_id', $user->id);
            $legacy = $query->first();
            if ($legacy && ($legacy->login_blocked || !$this->isMapped($current))) {
                return false;
            }
            if (Hash::check($password, $current->password)) {
                return true;
            }
            if (!$legacy || $legacy->upgraded_at) {
                return false;
            }
            if (!hash_equals($legacy->password_fingerprint ?? '', hash('sha256', $current->password))) {
                $query->update(['credential' => Crypt::encryptString(''), 'upgraded_at' => now()]);

                return false;
            }
            $hash = Crypt::decryptString($legacy->credential);
            if (!preg_match('/^\$(1|5|6|2[aby])\$/', $hash) || !hash_equals($hash, crypt($password, $hash))) {
                return false;
            }
            DB::table('users')->where('id', $current->id)->update(['password' => Hash::make($password), 'updated_at' => now()]);
            $query->update(['credential' => Crypt::encryptString(''), 'upgraded_at' => now()]);
            $user->refresh();

            return true;
        });
    }
}
