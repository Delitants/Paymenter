<div class="container mt-8 mb-16 max-w-6xl">
    <div @if ($checkPayment) wire:poll.5s="checkPaymentStatus" @endif>
        @can('update', $invoice)
        @if ($this->pay || $showPayModal)
        @include('invoices.partials.payment-modal')
        @endif
        @endcan
        <header class="flex flex-wrap items-center justify-between gap-4 mb-7">
            <h1 class="text-3xl font-bold">{{ !$invoice->number && config('settings.invoice_proforma', false) ? __('invoices.proforma_invoice', ['id' => $invoice->id]) : __('invoices.invoice', ['id' => $invoice->number]) }}</h1>
            <button type="button" class="min-h-11 text-sm underline" wire:click="downloadPDF" wire:loading.attr="disabled">
                <span wire:loading wire:target="downloadPDF"><x-ri-loader-5-fill class="size-5 animate-spin" /></span>
                <span wire:loading.remove wire:target="downloadPDF">{{ __('invoices.download_pdf') }}</span>
            </button>
        </header>
        @php
            $allocations = \App\Models\AccountFundingAllocation::where('invoice_id', $invoice->id)->orderBy('id')->get();
            $accountQuote = $this->fundingQuote;
            $depositInvoice = $invoice->items->contains(fn ($item) => $item->reference_type === \App\Models\Credit::class);
        @endphp
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-8 items-start">
            <main class="min-w-0 space-y-6">
                @if($this->claimedAttempt || $checkPayment)
                <section class="border border-neutral rounded-xl p-6 text-sm" role="status">
                    <h2 class="font-bold mb-2">{{ __('A payment is currently being processed') }}</h2>
                    <p>{{ __('We are waiting for payment confirmation. Your invoice will update when the outcome is confirmed. Continue with the original payment method; changing gateways or applying account funds requires reconciliation.') }}</p>
                </section>
                @endif
                <section class="bg-background-secondary border border-neutral rounded-xl p-6">
                    <div class="flex flex-wrap justify-between gap-6 text-sm">
                        <div><h2 class="font-bold mb-2">{{ __('invoices.issued_to') }}</h2><p>{{ $invoice->user_name }}</p>@foreach($invoice->user_properties as $property)<p>{{ $property }}</p>@endforeach</div>
                        <div><h2 class="font-bold mb-2">{{ __('invoices.bill_to') }}</h2><p>{!! nl2br(e($invoice->bill_to)) !!}</p></div>
                    </div>
                    <div class="flex flex-wrap justify-between gap-4 mt-5 text-sm border-t border-neutral pt-4">
                        <p>{{ __('invoices.invoice_date') }}: {{ $invoice->created_at->format('d M Y') }}</p>
                        @if($invoice->due_at)<p>{{ __('invoices.due_date') }}: {{ $invoice->due_at->format('d M Y') }}</p>@endif
                        <p class="font-semibold">{{ __('invoices.' . ($invoice->status === 'paid' ? 'paid' : ($invoice->status === 'pending' ? 'payment_pending' : $invoice->status))) }}</p>
                    </div>
                </section>
                <section class="bg-background-secondary border border-neutral rounded-xl p-6">
                    <h2 class="text-lg font-bold mb-4">{{ __('Items') }}</h2>
                    <ol class="divide-y divide-neutral">
                        @foreach($invoice->items as $item)
                        <li class="py-4 grid grid-cols-[minmax(0,1fr)_auto] gap-4 text-sm">
                            <div class="min-w-0">
                                @if(in_array($item->reference_type, ['App\Models\Service', 'App\Models\ServiceUpgrade']) && $item->reference)
                                <a class="font-medium hover:underline break-words" href="{{ route('services.show', $item->reference_type === 'App\Models\Service' ? $item->reference_id : $item->reference->service_id) }}">{{ $item->description }}</a>
                                @else<p class="font-medium break-words">{{ $item->description }}</p>@endif
                                <p class="text-base/70 mt-1">{{ __('invoices.quantity') }}: {{ $item->quantity }} · {{ __('invoices.price') }}: <span class="whitespace-nowrap">{{ $item->formattedPrice }}</span></p>
                            </div>
                            <p class="whitespace-nowrap tabular-nums font-semibold">{{ $item->formattedTotal }}</p>
                        </li>
                        @endforeach
                    </ol>
                </section>
                @if($accountQuote)
                <section class="bg-background-secondary border border-neutral rounded-xl p-6">
                    <x-billing.account-position :quote="$accountQuote" :currency="$invoice->currency_code" />
                    <a class="inline-flex items-center min-h-11 text-primary underline text-sm mt-3" href="{{ route('account.funding', ['currency' => $invoice->currency_code]) }}" wire:navigate>{{ __('View account statement') }}</a>
                </section>
                @endif
                @if($depositInvoice)
                <section class="bg-background-secondary border border-neutral rounded-xl p-6 text-sm text-base/70">
                    <h2 class="font-bold text-base mb-2">{{ __('Deposits repay debt first') }}</h2>
                    <p>{{ __('The remaining principal becomes cash. Gateway fees do not increase your account balance. Deposit invoices cannot be paid with account funds.') }}</p>
                </section>
                @endif
                @if($invoice->transactions->isNotEmpty())
                <section class="bg-background-secondary border border-neutral rounded-xl p-6">
                    <h2 class="text-lg font-bold mb-5">{{ __('Payment and account funding history') }}</h2>
                    <ol class="space-y-4">
                        @foreach($invoice->transactions->sortByDesc('created_at') as $transaction)
                        @php($allocation = $allocations->firstWhere('invoice_transaction_id', $transaction->id))
                        <li class="border border-neutral rounded-md p-4 text-sm">
                            <div class="flex justify-between gap-4">
                                <div class="min-w-0"><p class="font-semibold">{{ $allocation ? __('Account funding receipt') : ($transaction->is_credit_transaction ? __('invoices.paid_with_credits') : $transaction->gateway?->name) }}</p><p class="text-xs text-base/70 mt-1">{{ $transaction->created_at->format('d M Y H:i') }}</p></div>
                                <p class="whitespace-nowrap tabular-nums font-medium">{{ $transaction->formattedAmount }}</p>
                            </div>
                            @if($allocation)
                            <p class="text-base/70 mt-3">{{ __('Internal account allocation') }}</p>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                <div><dt class="text-base/70">{{ __('Cash applied') }}</dt><dd class="tabular-nums whitespace-nowrap">{{ $allocation->cash_amount }} {{ $invoice->currency_code }}</dd></div>
                                <div><dt class="text-base/70">{{ __('Borrowing applied') }}</dt><dd class="tabular-nums whitespace-nowrap">{{ $allocation->debt_amount }} {{ $invoice->currency_code }}</dd></div>
                            </dl>
                            @if(\Brick\Math\BigDecimal::of($allocation->reversed_amount)->isPositive())
                            <p class="mt-3">{{ __('Account payment reversed') }}: <span class="whitespace-nowrap">{{ $invoice->formattedTotal->format($allocation->reversed_amount) }}</span></p>
                            @endif
                            @else
                            @if($transaction->transaction_id)<p class="text-xs break-all text-base/70 mt-2">{{ __('invoices.transaction_id') }}: {{ $transaction->transaction_id }}</p>@endif
                            <p class="text-xs text-base/70 mt-2">{{ __($transaction->settlementLabel) }}</p>
                            @endif
                            <p class="text-xs text-base/70 mt-2">{{ __('invoices.transaction_statuses.' . $transaction->status->value) }}</p>
                        </li>
                        @endforeach
                    </ol>
                </section>
                @endif
            </main>
            <aside class="space-y-6 lg:sticky lg:top-8">
                <section class="bg-background-secondary border border-neutral rounded-xl p-6">
                    <h2 class="text-xl font-bold border-b border-neutral pb-4 mb-5">{{ __('Payment summary') }}</h2>
                    <x-billing.payment-summary :summary="$this->paymentSummary" :formatter="$invoice->formattedTotal" :tax-name="$invoice->tax?->name ?? 'Tax'" :tax-rate="(string) ($invoice->tax?->rate ?? '0')" />
                    <p class="text-xs text-base/70 mt-5">{{ __('Gateway fees are not taxed. The fee is confirmed when payment starts.') }}</p>
                </section>
                @can('update', $invoice)
                @if($invoice->status === 'pending' && !$showPayModal && !$this->pay)
                <section class="bg-background-secondary border border-neutral rounded-xl p-6">
                    <h2 class="text-xl font-bold border-b border-neutral pb-4 mb-5">{{ __('Payment method') }}</h2>
                    @include('invoices.partials.payment-options', ['inlinePaymentOptions' => true])
                </section>
                @endif
                @endcan
            </aside>
        </div>
    </div>
</div>
