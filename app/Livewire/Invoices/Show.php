<?php

namespace App\Livewire\Invoices;

use App\Classes\PDF;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\InvoicePricing;
use App\Services\Billing\PaymentSummary;
use App\Services\Gateways\GatewayFeePolicy;
use App\Services\Gateways\InvoicePaymentDependencies;
use App\Services\Gateways\PaymentWriteGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class Show extends Component
{
    #[Locked]
    public Invoice $invoice;

    public $checkPayment = false;

    private $pay = null;

    #[Url('pay', except: false, nullable: true)]
    public $showPayModal = false;

    public $lastChecked = null;

    public $selectedMethod = null;

    public $setAsDefault = false;

    public function mount()
    {
        $claim = $this->claimedAttempt();
        $preferred = $claim?->gateway_id ?? Request::query('gateway');
        if ($preferred && collect($this->gateways())->contains('id', $preferred)) {
            $this->selectedMethod = 'gateway-' . $preferred;
        }

        if (Request::has('checkPayment') && $this->invoice->status === 'pending') {
            $this->checkPayment = true;
        }
        if ($this->invoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->exists()) {
            $this->checkPayment = true;
        }

        // Load relations
        $this->invoice->load('transactions', 'transactions.gateway', 'transactions.invoice');

        if ($this->showPayModal && $this->invoice->status !== 'pending') {
            $this->showPayModal = false;
        }
    }

    #[Computed]
    public function claimedAttempt(): ?GatewayPaymentAttempt
    {
        return GatewayPaymentAttempt::where('invoice_id', $this->invoice->id)->whereIn('state', ['open', 'initializing'])->first();
    }

    #[Computed]
    public function paymentSummary(): PaymentSummary
    {
        $base = (new InvoicePricing)->summary($this->invoice);
        if ($this->claimedAttempt() || $this->invoice->status !== 'pending' || $this->selectedMethod === 'credit') {
            return $base;
        }
        $gateway = str_starts_with((string) $this->selectedMethod, 'gateway-')
            ? collect($this->gateways())->firstWhere('id', substr($this->selectedMethod, 8))
            : $this->savedPaymentMethods()->firstWhere('ulid', $this->selectedMethod)?->gateway;

        return $gateway ? (new GatewayFeePolicy)->quote($base, $gateway) : $base;
    }

    #[Computed]
    public function gateways()
    {
        if ($claim = $this->claimedAttempt()) {
            $gateway = $claim->gateway;
            $collection = $gateway?->settings()->where('key', 'collection_enabled')->first()?->value;

            return $gateway?->enabled && filter_var($collection, FILTER_VALIDATE_BOOLEAN) ? [$gateway] : [];
        }

        return ExtensionHelper::getCheckoutGateways((new InvoicePricing)->summary($this->invoice)->payable, $this->invoice->currency_code, 'invoice', $this->invoice->items);
    }

    #[Computed]
    public function paymentMethods()
    {
        return $this->claimedAttempt() ? [] : ExtensionHelper::getBillingAgreementGateways($this->invoice->currency_code);
    }

    #[Computed]
    public function savedPaymentMethods()
    {
        return Auth::user()->billingAgreements()->with('gateway')->get()->whereIn('gateway_id', array_column($this->paymentMethods(), 'id'));
    }

    #[Computed]
    public function recurringServices()
    {
        return $this->invoice->items()
            ->where('reference_type', Service::class)
            ->whereNotNull('reference_id')
            ->whereHasMorph('reference', [Service::class], function ($query) {
                $query->whereHas('plan', function ($planQuery) {
                    $planQuery->whereNotIn('type', ['one-time', 'free']);
                });
            });
    }

    public function updatedShowPayModal($value)
    {
        if ($value && $this->invoice->status !== 'pending') {
            $this->showPayModal = false;
        }
    }

    public function processPayment()
    {
        $this->authorize('update', $this->invoice);
        if (is_null($this->selectedMethod)) {
            return;
        }

        if (($claim = $this->claimedAttempt()) && $this->selectedMethod !== 'gateway-' . $claim->gateway_id) {
            return $this->notify(__('Continue with the original payment method. Changing it requires reconciliation.'), 'error');
        }

        if ($this->selectedMethod === 'credit') {
            return $this->payWithCredit();
        }

        if (str_starts_with($this->selectedMethod, 'gateway-')) {
            $gatewayId = substr($this->selectedMethod, 8);

            return $this->payWithMethod($gatewayId);
        }

        return $this->payWithSavedMethod($this->selectedMethod);
    }

    private function payWithMethod($methodId)
    {
        if (!in_array($methodId, array_column($this->gateways, 'id'))) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }

        if ($this->invoice->status !== 'pending') {
            return $this->notify(__('This invoice cannot be paid.'), 'error');
        }

        $this->pay = ExtensionHelper::pay(Gateway::where('id', $methodId)->first(), $this->invoice);

        $this->invoice = $this->invoice->fresh(['items', 'transactions']);
        unset($this->claimedAttempt, $this->gateways, $this->paymentMethods, $this->savedPaymentMethods, $this->paymentSummary);

        if (is_string($this->pay)) {
            $this->redirect($this->pay);
        }
    }

    private function payWithCredit()
    {
        if (!config('settings.credits_enabled') || $this->claimedAttempt() || $this->invoice->items()->where('reference_type', Credit::class)->exists()) {
            return $this->notify(__('Credits cannot be applied to this payment.'), 'error');
        }
        $full = DB::transaction(function () {
            $invoice = (new InvoicePaymentDependencies)->lock([$this->invoice->id])->firstWhere('id', $this->invoice->id);
            $this->authorize('update', $invoice);
            (new PaymentWriteGuard)->assertEditable($invoice);
            $credit = Auth::user()->credits()->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
            $remaining = BigDecimal::of((new InvoicePricing)->summary($invoice)->payable);
            if ($invoice->status !== 'pending' || !$remaining->isPositive() || !$credit || !BigDecimal::of((string) $credit->amount)->isPositive()) {
                return null;
            }
            $available = BigDecimal::of((string) $credit->amount);
            $spend = $available->isGreaterThan($remaining) ? $remaining : $available;
            $credit->update(['amount' => (string) $available->minus($spend)->toScale(2)]);
            ExtensionHelper::addPayment($invoice, null, (string) $spend->toScale(2), isCreditTransaction: true);

            return $spend->isEqualTo($remaining);
        });
        $this->invoice = $this->invoice->fresh();
        if ($full === true) {
            return $this->redirect(route('invoices.show', $this->invoice), true);
        }
        if ($full === false) {
            $this->notify(__('Part of the invoice has been paid with credits. Please pay the remaining amount'));
        }
    }

    private function payWithSavedMethod($agreementUlid)
    {
        $agreement = Auth::user()->billingAgreements()->where('ulid', $agreementUlid)->with('gateway')->first();
        if (!$agreement) {
            return $this->notify(__('Invalid payment method.'), 'error');
        }

        if (!in_array($agreement->gateway->id, array_column($this->paymentMethods, 'id'))) {
            return $this->notify(__('This payment method cannot be used for this invoice.'), 'error');
        }

        if ($this->invoice->status !== 'pending') {
            return $this->notify(__('This invoice cannot be paid.'), 'error');
        }

        if ($this->setAsDefault) {
            $invoiceItems = $this->recurringServices()->get();
            $agreement = Auth::user()->billingAgreements()->where('ulid', $agreementUlid)->first();

            foreach ($invoiceItems as $invoiceItem) {
                $service = $invoiceItem->reference;
                $service->update(['billing_agreement_id' => $agreement->id]);
            }

            if ($invoiceItems->count() > 0) {
                $this->notify('Default payment method has been updated for recurring services.', 'success');
            }
        }

        $success = ExtensionHelper::charge($agreement->gateway, $this->invoice, $agreement);

        if ($success === true) {
            $this->notify(__('Successfully charged the saved payment method.'), 'success');

            return $this->redirect(route('invoices.show', $this->invoice) . '?checkPayment', true);
        } else {
            return $this->notify(__('Could not process payment. Please try again or use a different payment method.'), 'error');
        }
    }

    public function exitPay()
    {
        $this->pay = null;
        // Dispatch event so extensions can do their thing
        $this->dispatch('invoice.payment.cancelled', $this->invoice);
        // Refresh invoice status
        $this->redirect(route('invoices.show', $this->invoice), true);
    }

    public function checkPaymentStatus()
    {
        $this->invoice->refresh();

        // Check for transactions that failed since lastChecked
        if ($this->lastChecked) {
            $failedSinceLastCheck = $this->invoice->transactions()
                ->where('status', InvoiceTransactionStatus::Failed)
                ->where('updated_at', '>', $this->lastChecked)
                ->exists();

            if ($failedSinceLastCheck) {
                $this->notify(__('Payment failed. Please try again or use a different payment method.'), 'error');
                $this->checkPayment = false;
                $this->lastChecked = null;

                return;
            }
        }

        // Update lastChecked to current time
        $this->lastChecked = now();

        // Check if invoice is paid
        if ($this->invoice->status === 'paid') {
            $this->notify(__('The invoice has been paid.'), 'success');
            $this->checkPayment = false;
            $this->lastChecked = null;
        }

        // Skip render if still checking
        if ($this->checkPayment) {
            return $this->skipRender();
        }
    }

    public function render()
    {
        return view('invoices.show')->layoutData([
            'title' => __('invoices.invoice', ['id' => $this->invoice->number]),
            'sidebar' => true,
        ]);
    }

    public function downloadPDF()
    {
        return response()->streamDownload(function () {
            echo PDF::generateInvoice($this->invoice)->output();
        }, 'invoice-' . ($this->invoice->number ?? $this->invoice->id) . '.pdf', ['Content-Type' => 'application/pdf']);
    }
}
