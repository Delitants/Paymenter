<?php

namespace App\Livewire\Client;

use App\Livewire\Component;
use App\Models\Currency;
use App\Models\User;
use App\Services\Accounts\AccountStatement;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class AccountFundingStatement extends Component
{
    use WithPagination;

    #[Locked]
    public int $owner;

    #[Locked]
    public string $currency;

    public function mount(?int $owner = null, ?string $currency = null): void
    {
        $this->owner = $owner ?? Auth::id();
        $this->currency = $currency ?? request()->query('currency', config('settings.default_currency', 'USD'));
        Currency::findOrFail($this->currency);
    }

    public function render()
    {
        // Resolve current membership and staff permissions on every request.
        $statement = app(AccountStatement::class)->forReader(Auth::user(), User::findOrFail($this->owner), $this->currency);

        return view('client.account.funding-statement', $statement)->layoutData([
            'title' => __('Account statement'), 'sidebar' => false,
        ]);
    }
}
