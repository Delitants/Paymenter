<?php

namespace App\Livewire\Client;

use App\Attributes\DisabledIf;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Accounts\AccountAmount;
use App\Services\Accounts\WalletLedger;
use App\Services\Billing\MoneyCalculator;
use App\Services\BillmanagerMigration\AccountAccess;
use App\Services\BillmanagerMigration\MigrationHold;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;

#[DisabledIf('credits_enabled', reverse: true)]
class Credits extends Component
{
    #[Validate('required|exists:currencies,code')]
    public $currency;

    public $amount;

    #[Locked]
    public $gateways = [];

    public $gateway;

    public function mount()
    {
        $this->amount = (string) config('settings.credits_minimum_deposit');
        $this->currency = session('currency', config('settings.default_currency'));
        $this->gateways = ExtensionHelper::getCheckoutGateways($this->amount, $this->currency, 'credits');
        if (count($this->gateways) > 0 && !array_search($this->gateway, array_column($this->gateways, 'id')) !== false) {
            $this->gateway = $this->gateways[0]->id;
        }
    }

    public function updated($variable)
    {
        if ($variable === 'amount' || $variable === 'currency') {
            $this->gateways = ExtensionHelper::getCheckoutGateways($this->amount, $this->currency, 'credits');
            if (count($this->gateways) > 0 && !array_search($this->gateway, array_column($this->gateways, 'id')) !== false) {
                $this->gateway = $this->gateways[0]->id;
            }
        }
    }

    public function addCredit()
    {
        $this->validate([
            'currency' => 'required|exists:currencies,code',
            'amount' => 'required|numeric|min:' . config('settings.credits_minimum_deposit') . '|max:' . config('settings.credits_maximum_deposit'),
            'gateway' => 'required|in:' . implode(',', array_column($this->gateways, 'id')),
        ]);

        // Create invoice
        DB::beginTransaction();

        try {
            // Only new private deposit rows are created here. Serialize creation
            // on the current owner; existing invoice rows are never mutated.
            $owner = User::whereKey(Auth::id())->lockForUpdate()->firstOrFail();
            MigrationHold::assertAllowed($owner, 'create deposit invoice', true);
            $wallet = AccountWallet::where('user_id', $owner->id)->where('currency_code', $this->currency)->lockForUpdate()->first();
            if ($wallet) {
                $quote = (new WalletLedger)->quote($owner, $this->currency);
                if ($quote->blocked) {
                    throw new DisplayException('Your account needs review before adding funds. Please contact support.');
                }
                $principal = AccountAmount::positiveCents($this->amount);
                $after = AccountAmount::parse($wallet->balance)->add($principal);
                $cashAfter = $after->compare(AccountAmount::parse('0')) > 0 ? $after->floorCents() : '0.00';
            } else {
                $principal = (new MoneyCalculator)->money((string) $this->amount);
                $cash = $owner->credits()->where('currency_code', $this->currency)->orderBy('id')->lockForUpdate()->get();
                $cashAfter = (string) $cash->reduce(fn ($total, $row) => $total->plus($row->getRawOriginal('amount')), BigDecimal::of('0.00'))->plus($principal)->toScale(2);
            }
            if (BigDecimal::of($cashAfter)->isGreaterThan((string) config('settings.credits_maximum_credit'))) {
                throw new DisplayException('This deposit would exceed the maximum account cash balance.');
            }

            // Check if the user has any unpaid invoice items referencing credits
            $unpaidInvoiceItems = Auth::user()->invoices()->where('status', Invoice::STATUS_PENDING)->whereHas('items', function ($query) {
                $query->where('reference_type', Credit::class);
            })->exists();

            if ($unpaidInvoiceItems) {
                throw new DisplayException('You have an unpaid invoice for credits. Please pay the invoice before adding more credits.');
            }

            $invoice = Invoice::create([
                'user_id' => Auth::id(),
                'currency_code' => $this->currency,
                'due_at' => now(),
            ]);

            $invoice->items()->create([
                'description' => __('account.credit_deposit', ['currency' => $this->currency]),
                'quantity' => 1,
                'price' => (string) (new MoneyCalculator)->money((string) $this->amount),
                'kind' => 'credit_allocation',
                'tax_amount' => '0.00',
                'reference_type' => Credit::class,
            ]);

            DB::commit();

            Session::put(['gateway' => $this->gateway]);

            // Redirect to the invoices page and pay the invoice
            if ($this->gateway) {
                $pay = ExtensionHelper::pay(Gateway::where('id', $this->gateway)->first(), $invoice->fresh());
                if (is_string($pay)) {
                    return $this->redirect($pay);
                }
            }

            return $this->redirect(route('invoices.show', $invoice) . '?gateway=' . $this->gateway . '&pay', true);
        } catch (Exception $e) {
            // Rollback the transaction
            DB::rollBack();
            // Return error message
            throw $e;
        }
    }

    public function render()
    {
        $statements = AccountWallet::whereIn('user_id', AccountAccess::visibleOwnerIds(Auth::user()))
            ->orderBy('user_id')->orderBy('currency_code')->get();
        $fundingBlocked = (new WalletLedger)->quote(Auth::user(), $this->currency)?->blocked ?? false;

        return view('client.account.credits', ['statements' => $statements, 'fundingBlocked' => $fundingBlocked])->layoutData([
            'sidebar' => true,
            'title' => 'Add Credits',
        ]);
    }
}
