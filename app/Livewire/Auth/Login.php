<?php

namespace App\Livewire\Auth;

use App\Livewire\Component;
use App\Services\BillmanagerMigration\LegacyCredentialVerifier;
use App\Traits\Captchable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Validate;

class Login extends Component
{
    use Captchable;

    #[Validate('required|string|max:255')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $remember = false;

    public function submit(\App\Actions\Auth\Login $loginAction)
    {
        $this->captcha();
        $this->validate();

        $verifier = app(LegacyCredentialVerifier::class);
        $user = $verifier->findByLogin($this->email);
        // Aliases share the canonical account's attempt budget.
        $emailKey = strtolower($user?->email ?? trim($this->email));

        if (RateLimiter::tooManyAttempts('login:' . $emailKey . ':' . request()->ip(), 5)) {
            $this->addError('email', 'Too many login attempts. Please try again in 60 seconds.');

            return;
        }

        RateLimiter::increment('login:' . $emailKey . ':' . request()->ip());

        if (!$user || !$verifier->verifyAndUpgrade($user, $this->password)) {
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        // Check 2FA
        if ($user->tfa_secret) {
            Session::put('2fa', [
                'user_id' => $user->id,
                'remember' => $this->remember,
                'expires' => now()->addMinutes(5),
            ]);

            return $this->redirect(route('2fa'), true);
        }

        RateLimiter::clear('login:' . $emailKey . ':' . request()->ip());

        $loginAction->execute($user, $this->remember);

        $intendedUrl = session()->pull('url.intended', default: route('dashboard'));
        $isAdminRoute = str_starts_with($intendedUrl, url('/admin'));

        // Redirect normally if it is an admin route, otherwise navigate using livewire
        return $this->redirect($intendedUrl, navigate: !$isAdminRoute);
    }

    public function render()
    {
        return view('auth.login');
    }
}
