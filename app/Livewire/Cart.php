<?php

namespace App\Livewire;

use App\Classes\Cart as ClassesCart;
use App\Classes\Price;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Models\AccountWallet;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\Accounts\AccountPaymentLocks;
use App\Services\Accounts\InvoiceFunding;
use App\Services\Accounts\WalletLedger;
use App\Services\Accounts\WalletQuote;
use App\Services\Billing\InvoicePricing;
use App\Services\Billing\MoneyCalculator;
use App\Services\Billing\PaymentSummary;
use App\Services\Gateways\GatewayFeePolicy;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class Cart extends Component
{
    #[Locked]
    public $total;

    public $gateway;

    public $coupon;

    public $use_credits = true;

    public $tos;

    public function mount()
    {
        if (ClassesCart::get()->coupon_id) {
            $this->coupon = ClassesCart::get()->coupon;
        }
        $this->updateTotal();
    }

    private function updateTotal()
    {
        if (ClassesCart::items()->count() == 0) {
            $this->total = null;

            return;
        }
        unset($this->baseSummary, $this->gateways, $this->paymentSummary);
        $base = $this->baseSummary();
        $this->total = new Price(['price' => $base->productGross, 'currency' => ClassesCart::get()->currency, 'tax_amount' => $base->productTax]);
        if (!collect($this->gateways())->contains('id', $this->gateway)) {
            $this->gateway = $this->gateways()[0]->id ?? null;
        }
    }

    #[Computed]
    public function fundingQuote(): ?WalletQuote
    {
        return Auth::check() ? (new WalletLedger)->quote(Auth::user(), ClassesCart::get()->currency_code) : null;
    }

    #[Computed]
    public function baseSummary(): PaymentSummary
    {
        $gross = $tax = BigDecimal::of('0.00');
        foreach (ClassesCart::items() as $item) {
            $price = $item->price;
            $gross = $gross->plus(BigDecimal::of($price->total)->multipliedBy($item->quantity));
            $tax = $tax->plus(BigDecimal::of($price->total_tax)->multipliedBy($item->quantity));
        }
        $paid = BigDecimal::of('0.00');
        if ($this->use_credits && config('settings.credits_enabled') && Auth::check()) {
            $credit = Auth::user()->credits()->where('currency_code', ClassesCart::get()->currency_code)->first();
            $quote = $this->fundingQuote();
            $available = BigDecimal::of($quote?->fundingAvailable ?? (string) ($credit?->getRawOriginal('amount') ?? '0.00'));
            $paid = $available->isGreaterThan($gross) ? $gross : $available;
        }
        $net = $gross->minus($tax);
        $remaining = $gross->minus($paid);
        $unpaid = (new MoneyCalculator)->allocateRemaining((string) $net->toScale(2), (string) $tax->toScale(2), (string) $remaining->toScale(2));

        return new PaymentSummary(ClassesCart::get()->currency_code, (string) $net->toScale(2), (string) $tax->toScale(2), (string) $gross->toScale(2), $unpaid['net'], $unpaid['tax'], '0.00', (string) $gross->toScale(2), (string) $paid->toScale(2), (string) $remaining->toScale(2));
    }

    #[Computed]
    public function gateways(): array
    {
        $base = $this->baseSummary();

        return ExtensionHelper::getCheckoutGateways($base->payable, $base->currency, 'cart', ClassesCart::items());
    }

    #[Computed]
    public function paymentSummary(): PaymentSummary
    {
        $base = $this->baseSummary();
        $gateway = collect($this->gateways())->firstWhere('id', $this->gateway);

        return $gateway ? (new GatewayFeePolicy)->quote($base, $gateway) : $base;
    }

    public function applyCoupon()
    {
        if ($this->coupon && ClassesCart::get()->coupon_id) {
            return $this->notify('Coupon code already applied', 'error');
        }
        // Rate limit to prevent abuse
        if (RateLimiter::tooManyAttempts('apply_coupon_' . request()->ip(), 5)) {
            return $this->notify('Too many attempts. Please try again later.', 'error');
        }

        RateLimiter::hit('apply_coupon_' . request()->ip());

        try {
            $cart = ClassesCart::applyCoupon($this->coupon);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');
            $this->coupon = null;

            return;
        }
        $this->coupon = $cart->coupon;
        $this->updateTotal();
        $this->notify('Coupon code applied successfully', 'success');
    }

    public function removeCoupon()
    {
        if (!$this->coupon || !ClassesCart::get()->coupon_id) {
            return $this->notify('No coupon code applied', 'error');
        }
        ClassesCart::removeCoupon();
        $this->coupon = null;
        $this->updateTotal();
        $this->notify('Coupon code removed successfully', 'success');
    }

    public function removeProduct($index)
    {
        ClassesCart::remove($index);
        $this->updateTotal();
    }

    public function updateQuantity($index, $quantity)
    {
        ClassesCart::updateQuantity($index, $quantity);
        $this->updateTotal();
    }

    // Checkout
    public function checkout()
    {
        if (ClassesCart::items()->count() === 0) {
            return $this->notify('Your cart is empty', 'error');
        }
        if (!Auth::check()) {
            return redirect()->guest('login');
        }
        if (config('settings.mail_must_verify') && !Auth::user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        if (config('settings.tos') && !$this->tos) {
            return $this->notify('You must accept the terms of service', 'error');
        }

        // Re-validate coupon if one exists
        if (ClassesCart::get()->coupon && !ClassesCart::validateAndRefreshCoupon()) {
            $this->coupon = null;
            $this->updateTotal();

            return $this->notify('This coupon can no longer be used', 'error');
        }

        // Validate opt-in provider checkout before creating an invoice or holding DB locks.
        foreach (ClassesCart::get()->items()->with('product.server.settings', 'plan.prices')->get() as $item) {
            if ($item->product->server && ExtensionHelper::hasFunction($item->product->server, 'validateCartItem')) {
                $item->checkout_config = ExtensionHelper::call($item->product->server, 'validateCartItem', [$item]);
                $item->save();
            }
        }
        ClassesCart::get()->unsetRelation('items');
        $this->updateTotal();

        if (BigDecimal::of($this->baseSummary()->payable)->isPositive() && !collect($this->gateways())->contains('id', $this->gateway)) {
            return $this->notify(__('Select an available payment method.'), 'error');
        }

        // Start database transaction
        DB::beginTransaction();
        try {
            $cart = ClassesCart::get();

            $user = User::where('id', Auth::id())->lockForUpdate()->first();
            // Lock the orderproducts
            foreach ($cart->items as $item) {
                // Make sure we have the latest product data and lock it
                $item->setRelation('product', $item->product()->lockForUpdate()->firstOrFail());

                if (
                    $item->product->per_user_limit > 0 && ($user->services->where('product_id', $item->product->id)->count() >= $item->product->per_user_limit ||
                        $cart->items->filter(fn ($it) => $it->product->id == $item->product->id)->sum(fn ($it) => $it->quantity) + $user->services->where('product_id', $item->product->id)->count() > $item->product->per_user_limit
                    )
                ) {
                    throw new DisplayException(__('product.user_limit', ['product' => $item->product->name]));
                }
                if ($item->product->stock !== null) {
                    if ($item->product->stock < $item->quantity) {
                        throw new DisplayException(__('product.out_of_stock', ['product' => $item->product->name]));
                    }

                    $item->product->stock -= $item->quantity;
                    $item->product->save();
                }
            }
            // Create the order
            $order = new Order([
                'user_id' => $user->id,
                'currency_code' => $cart->currency_code,
            ]);
            $order->save();

            // Create the product invoice even when account credits cover it in full.
            $invoice = null;
            if ($cart->items->contains(fn ($item) => BigDecimal::of($item->price->total)->isPositive())) {
                $invoice = new Invoice([
                    'user_id' => $user->id,
                    'due_at' => now()->addDays(7),
                    'currency_code' => $cart->currency_code,
                ]);
                $invoice->save();
            }

            // Create the services
            foreach ($cart->items as $item) {
                $quoted = $item->priceForInvoice($invoice);
                $price = $cart->coupon && ($cart->coupon->recurring === null || (int) $cart->coupon->recurring === 1)
                    ? $quoted->original_price_decimal : $quoted->price_decimal;
                // Create the service
                $service = $order->services()->create([
                    'user_id' => $user->id,
                    'currency_code' => $cart->currency_code,
                    'product_id' => $item->product->id,
                    'plan_id' => $item->plan->id,
                    'price' => $price,
                    'quantity' => $item->quantity,
                    'coupon_id' => $cart->coupon_id,
                ]);

                foreach ($item->checkout_config as $key => $value) {
                    $service->properties()->updateOrCreate([
                        'key' => $key,
                    ], [
                        'value' => $value,
                    ]);
                }

                foreach ($item->config_options as $configOption) {
                    $configOption = (object) $configOption;
                    if (in_array($configOption->option_type, ['text', 'number'])) {
                        if (!isset($configOption->value)) {
                            continue;
                        }
                        $service->properties()->updateOrCreate([
                            'key' => $configOption->option_env_variable ? $configOption->option_env_variable : $configOption->option_name,
                        ], [
                            'name' => $configOption->option_name,
                            'value' => $configOption->value,
                        ]);

                        continue;
                    }
                    if (!isset($configOption->value) || $configOption->value === null) {
                        continue;
                    }

                    $service->configs()->create([
                        'config_option_id' => $configOption->option_id,
                        'config_value_id' => $configOption->value,
                    ]);
                }

                // Create the invoice items
                if (BigDecimal::of($quoted->total)->isPositive()) {
                    $invoice->items()->create([
                        'reference_id' => $service->id,
                        'reference_type' => Service::class,
                        'price' => $quoted->total,
                        'tax_amount' => (string) BigDecimal::of($quoted->total_tax)->multipliedBy($item->quantity)->toScale(2),
                        'quantity' => $item->quantity,
                        'description' => $service->description,
                    ]);
                } else {
                    // We'll make the service active immediately
                    if ($service->product->server) {
                        CreateJob::dispatch($service);
                    }
                    $service->status = Service::STATUS_ACTIVE;
                    $service->expires_at = $service->calculateNextDueDate();
                    $service->save();
                }
            }

            if ($invoice && $this->use_credits && config('settings.credits_enabled')) {
                (new AccountPaymentLocks)->during([$invoice->id], function () use ($invoice, $user, $cart) {
                    if (AccountWallet::where('user_id', $user->id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first()) {
                        (new InvoiceFunding)->fundAvailable($user, $invoice, 'cart-funding:' . $cart->ulid);

                        return;
                    }
                    Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                    $credit = $user->credits()->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
                    $due = BigDecimal::of((new InvoicePricing)->summary($invoice)->payable);
                    $available = BigDecimal::of((string) ($credit?->getRawOriginal('amount') ?? '0.00'));
                    $spend = $available->isGreaterThan($due) ? $due : $available;
                    if ($spend->isPositive()) {
                        $credit->update(['amount' => (string) $available->minus($spend)->toScale(2)]);
                        ExtensionHelper::addPayment($invoice, null, (string) $spend->toScale(2), isCreditTransaction: true);
                    }
                });
            }

            // Commit the transaction
            DB::commit();

            // Clear the cart
            ClassesCart::clear();

            if (!$invoice) {
                // Is it only one item? Then redirect to the service page
                if ($order->services->count() == 1) {
                    return $this->redirect(route('services.show', $order->services->first()), true);
                }

                return $this->redirect(route('services'), true);
            } else {
                return $this->redirect(route('invoices.show', [$invoice, 'pay' => $invoice->fresh()->status === 'pending', 'gateway' => $invoice->fresh()->status === 'pending' ? $this->gateway : null]), true);
            }
        } catch (Exception $e) {
            // Rollback the transaction
            DB::rollBack();
            // Return error message
            // Is it a real error or a validation error?
            // If it's a validation error, you can use the $this->addError() method to display the error message to the user.
            if ($e instanceof DisplayException) {
                return $this->notify($e->getMessage(), 'error');
            } else {
                report($e);
                $this->notify('An error occurred while processing your order. Please try again later.');
            }
        }
    }

    public function render()
    {
        return view('cart');
    }
}
