<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Actions\Auth\Login as LoginAction;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Tfa;
use App\Models\User;
use App\Models\UserSession;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\LegacyCredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\TestCase;

class LoginAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private function blockedUser(bool $otp = false): User
    {
        $user = User::factory()->create(['password' => Hash::make('native-passphrase')]);
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => bin2hex(random_bytes(32)), 'status' => 'running']);
        (new ImportContext($id, '192.0.2.10'))->recordMapping('users', (string) $user->id, 'users', $user->id);
        (new LegacyCredentialVerifier)->install($user, crypt('legacy-passphrase', '$5$testsalt$'), $otp ? 'JBSWY3DPEHPK3PXP' : null, $otp);
        DB::table('billmanager_legacy_credentials')->where('user_id', $user->id)->update(['login_blocked' => true, 'block_reason' => 'Client admission held pending review']);

        return $user->fresh();
    }

    public function test_credential_reimport_preserves_admission_block_and_factor(): void
    {
        $user = $this->blockedUser(true);
        $password = $user->password;
        (new LegacyCredentialVerifier)->install($user, crypt('legacy-passphrase', '$5$testsalt$'), 'JBSWY3DPEHPK3PXP', true);
        $this->assertDatabaseHas('billmanager_legacy_credentials', ['user_id' => $user->id, 'login_blocked' => true, 'block_reason' => 'Client admission held pending review']);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->tfa_secret);
    }

    public function test_blocked_email_and_alias_reject_native_and_legacy_passwords(): void
    {
        $user = $this->blockedUser();
        DB::table('billmanager_login_aliases')->insert(['user_id' => $user->id, 'alias' => 'held-client']);
        foreach ([$user->email, 'held-client'] as $login) {
            foreach (['native-passphrase', 'legacy-passphrase'] as $password) {
                Livewire::test(Login::class)->set('email', $login)->set('password', $password)->call('submit')->assertHasErrors('email');
                $this->assertGuest();
            }
        }
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_shared_login_action_rejects_block_before_creating_session(): void
    {
        $user = $this->blockedUser();
        try {
            (new LoginAction)->execute($user, true);
            $this->fail('Blocked login must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
        $this->assertGuest();
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_pending_otp_cannot_complete_after_admission_is_blocked(): void
    {
        $user = $this->blockedUser(true);
        session(['2fa' => ['user_id' => $user->id, 'remember' => true, 'expires' => now()->addMinutes(5)]]);
        $tfa = new TwoFactorAuth(new EndroidQrCodeProvider);
        Livewire::test(Tfa::class)->set('code', $tfa->getCode('JBSWY3DPEHPK3PXP'))->call('verify')->assertHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->tfa_secret);
    }

    public function test_existing_session_is_revoked_for_blocked_user_only(): void
    {
        $user = $this->blockedUser();
        $other = User::factory()->create();
        $otherSession = $this->loginUser($other)['user_session'];
        $session = $this->loginUser($user);
        $this->actingAs($user)->withSession($session)->get('/')->assertRedirect('/');
        $this->assertGuest();
        $this->assertDatabaseMissing('user_sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('user_sessions', ['ulid' => $otherSession]);
    }

    public function test_remember_cookie_cannot_restore_blocked_user(): void
    {
        $user = $this->blockedUser();
        $token = UserSession::create(['user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'synthetic', 'last_activity' => now(), 'expires_at' => now()->addDay()]);
        Auth::logout();
        $this->withCookie('paymenter_remember', $token->ulid)->get('/')->assertRedirect('/');
        $this->assertGuest();
        $this->assertDatabaseMissing('user_sessions', ['user_id' => $user->id]);
    }

    public function test_block_remains_effective_after_native_password_reset(): void
    {
        $user = $this->blockedUser();
        $user->update(['password' => Hash::make('replacement-passphrase')]);
        $this->assertFalse((new LegacyCredentialVerifier)->verifyAndUpgrade($user->fresh(), 'replacement-passphrase'));
        $this->expectException(ValidationException::class);
        (new LoginAction)->execute($user->fresh());
    }
}
