<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Tfa;
use App\Models\User;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\LegacyCredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\TestCase;

class LegacyCredentialTest extends TestCase
{
    use RefreshDatabase;

    private function mappedUser(): User
    {
        $user = User::factory()->create();
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => bin2hex(random_bytes(32)), 'status' => 'running']);
        (new ImportContext($id, '192.0.2.10'))->recordMapping('users', (string) $user->id, 'users', $user->id);

        return $user;
    }

    public function test_supported_legacy_hashes_upgrade_only_after_correct_password(): void
    {
        foreach (['$1$testsalt$', '$5$testsalt$'] as $salt) {
            $user = $this->mappedUser();
            $before = $user->password;
            $verifier = new LegacyCredentialVerifier;
            $verifier->install($user, crypt('synthetic-passphrase', $salt), null, false);
            $this->assertFalse($verifier->verifyAndUpgrade($user, 'incorrect'));
            $this->assertSame($before, $user->fresh()->password);
            $this->assertTrue($verifier->verifyAndUpgrade($user, 'synthetic-passphrase'));
            $this->assertTrue(Hash::check('synthetic-passphrase', $user->fresh()->password));
            $this->assertNotNull(DB::table('billmanager_legacy_credentials')->where('user_id', $user->id)->value('upgraded_at'));
        }
    }

    public function test_password_reset_invalidates_legacy_fallback(): void
    {
        $user = $this->mappedUser();
        $verifier = new LegacyCredentialVerifier;
        $verifier->install($user, crypt('old-passphrase', '$5$testsalt$'), null, false);
        $user->update(['password' => Hash::make('new-passphrase')]);
        $this->assertFalse($verifier->verifyAndUpgrade($user->fresh(), 'old-passphrase'));
        $this->assertTrue($verifier->verifyAndUpgrade($user->fresh(), 'new-passphrase'));
        $verifier->install($user->fresh(), crypt('old-passphrase', '$5$testsalt$'), null, false);
        $this->assertFalse($verifier->verifyAndUpgrade($user->fresh(), 'old-passphrase'));
    }

    public function test_existing_native_user_keeps_native_authentication(): void
    {
        $user = User::factory()->create(['password' => Hash::make('native-passphrase')]);
        $this->assertTrue((new LegacyCredentialVerifier)->verifyAndUpgrade($user, 'native-passphrase'));
        $this->assertFalse((new LegacyCredentialVerifier)->verifyAndUpgrade($user, 'incorrect'));
        $this->expectException(\RuntimeException::class);
        (new LegacyCredentialVerifier)->install($user, crypt('other', '$1$testsalt$'), null, false);
    }

    public function test_required_mfa_is_preserved_or_login_stays_blocked(): void
    {
        $user = $this->mappedUser();
        $verifier = new LegacyCredentialVerifier;
        $hash = crypt('synthetic-passphrase', '$5$testsalt$');
        $verifier->install($user, $hash, null, true);
        $this->assertFalse($verifier->verifyAndUpgrade($user, 'synthetic-passphrase'));
        $this->assertTrue((bool) DB::table('billmanager_legacy_credentials')->where('user_id', $user->id)->value('login_blocked'));
        $verifier->install($user, $hash, 'JBSWY3DPEHPK3PXP', true);
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->tfa_secret);
        $this->assertTrue($verifier->verifyAndUpgrade($user->fresh(), 'synthetic-passphrase'));
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->tfa_secret);
    }

    public function test_migrated_password_still_requires_the_native_second_factor(): void
    {
        $user = $this->mappedUser();
        (new LegacyCredentialVerifier)->install($user, crypt('synthetic-passphrase', '$5$testsalt$'), 'JBSWY3DPEHPK3PXP', true);
        Livewire::test(Login::class)
            ->set('email', $user->email)->set('password', 'synthetic-passphrase')->call('submit')
            ->assertRedirect(route('2fa'));
        $this->assertGuest();
        $this->assertSame($user->id, session('2fa.user_id'));
    }

    public function test_native_rate_limit_also_covers_legacy_password_verification(): void
    {
        $user = $this->mappedUser();
        (new LegacyCredentialVerifier)->install($user, crypt('synthetic-passphrase', '$1$testsalt$'), null, false);
        $before = $user->password;
        $component = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'incorrect');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->call('submit')->assertHasErrors('email');
        }
        $component->set('password', 'synthetic-passphrase')->call('submit')->assertHasErrors('email');
        $this->assertSame($before, $user->fresh()->password);
        $this->assertGuest();
    }

    public function test_migrated_username_alias_uses_the_same_password_and_requires_otp(): void
    {
        $user = $this->mappedUser();
        DB::table('billmanager_login_aliases')->insert(['user_id' => $user->id, 'alias' => 'legacy-client']);
        (new LegacyCredentialVerifier)->install($user, crypt('synthetic-passphrase', '$5$testsalt$'), 'JBSWY3DPEHPK3PXP', true);
        Livewire::test(Login::class)->set('email', 'legacy-client')->set('password', 'synthetic-passphrase')->call('submit')->assertRedirect(route('2fa'));
        $this->assertGuest();
        $tfa = new TwoFactorAuth(new EndroidQrCodeProvider);
        Livewire::test(Tfa::class)->set('code', $tfa->getCode('JBSWY3DPEHPK3PXP'))->call('verify')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
