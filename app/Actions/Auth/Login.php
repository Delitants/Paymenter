<?php

namespace App\Actions\Auth;

use App\Events\Auth\Login as LoginEvent;
use App\Models\User;
use App\Models\UserSession;
use App\Services\BillmanagerMigration\LegacyCredentialVerifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class Login
{
    public function execute(User $user, bool $remember = false): void
    {
        // Shared by password, OTP and linked-account sign-in.
        if (!app(LegacyCredentialVerifier::class)->canAuthenticate($user)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        Session::regenerate();

        // Create user session token - middleware will handle actual login
        $userSession = UserSession::create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->userAgent() ?? '', 0, 512),
            'last_activity' => Carbon::now(),
            'expires_at' => $remember ? Carbon::now()->addDays(30) : null,
        ]);

        Session::put('user_session', $userSession->ulid);

        Auth::login($user);

        if ($remember) {
            Cookie::queue('paymenter_remember', $userSession->ulid, 60 * 24 * 30); // 30 days
        }

        event(new LoginEvent($user));
    }
}
