<div class="container mt-8 mb-16 max-w-6xl">
    <h1 class="text-3xl font-bold mb-7">{{ __('Account statement') }}</h1>
    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-8 items-start">
        <aside class="order-first lg:order-last bg-background-secondary rounded-xl border border-neutral p-6 lg:sticky lg:top-8">
            <h2 class="text-xl font-bold border-b border-neutral pb-4">{{ $currency }} {{ __('Summary') }}</h2>
            <dl class="text-sm space-y-4 mt-5">
                @foreach([
                    'Cash available' => $cash,
                    'Outstanding debt' => $quote->debt,
                    'Borrowing limit' => $quote->borrowingLimit,
                    'Remaining borrowing allowance' => $quote->remainingAllowance,
                    'Reserved refunds' => $quote->reservedPrincipal,
                ] as $label => $amount)
                <div class="flex justify-between gap-4">
                    <dt class="text-base/70">{{ __($label) }}</dt>
                    <dd class="tabular-nums whitespace-nowrap font-medium">{{ $amount }}</dd>
                </div>
                @endforeach
                <div class="border-t border-neutral pt-4 flex justify-between gap-4 font-bold text-base">
                    <dt>{{ __('Available for purchases') }}</dt>
                    <dd class="tabular-nums whitespace-nowrap">{{ $quote->fundingAvailable }}</dd>
                </div>
                <div class="flex justify-between gap-4 text-xs text-base/70">
                    <dt>{{ __('Fractional-cent remainder') }}</dt>
                    <dd class="tabular-nums whitespace-nowrap">{{ $quote->residual }}</dd>
                </div>
            </dl>
            @if($quote->blocked)
            <p role="status" class="border-t border-neutral mt-5 pt-4 text-sm">{{ __('Account funding is unavailable. You can still read your statement and history.') }}</p>
            @endif
        </aside>
        <div class="min-w-0 space-y-6">
            <section class="bg-background-secondary rounded-xl border border-neutral p-6">
                <h2 class="text-lg font-bold mb-5">{{ __('Statement history') }}</h2>
                <div class="hidden md:grid grid-cols-[140px_minmax(0,1fr)_110px_110px] gap-4 pb-3 text-sm text-base/70 border-b border-neutral" aria-hidden="true">
                    <span>{{ __('Date (UTC)') }}</span><span>{{ __('Description') }}</span><span class="text-right">{{ __('Amount') }}</span><span class="text-right">{{ __('Balance') }}</span>
                </div>
                <ol>
                    @forelse($movements as $movement)
                    <li class="grid grid-cols-1 md:grid-cols-[140px_minmax(0,1fr)_110px_110px] gap-3 md:gap-4 py-4 border-b border-neutral text-sm">
                        <time class="text-base/70">{{ $movement['date'] }}</time>
                        <div>
                            <p class="font-medium">{{ __($movement['label']) }}</p>
                            @if($movement['cash_portion'] !== null)
                            <p class="text-xs text-base/70 mt-1">{{ __('Cash applied') }}: {{ $movement['cash_portion'] }} · {{ __('Borrowing applied') }}: {{ $movement['borrowed_portion'] }}</p>
                            @endif
                            @if($administrative)
                            <details class="mt-2 text-xs break-all"><summary class="cursor-pointer min-h-11 flex items-center">{{ __('Administrative receipt') }}</summary><pre class="whitespace-pre-wrap">{{ json_encode($movement['audit'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                            @endif
                        </div>
                        <div class="flex justify-between md:block md:text-right tabular-nums whitespace-nowrap"><span class="md:hidden text-base/70">{{ __('Amount') }}</span><span>{{ $movement['delta'] }}</span></div>
                        <div class="flex justify-between md:block md:text-right tabular-nums whitespace-nowrap"><span class="md:hidden text-base/70">{{ __('Balance') }}</span><span>{{ $movement['balance_after'] }}</span></div>
                    </li>
                    @empty
                    <li class="py-4 text-sm text-base/70">{{ __('No account movements yet.') }}</li>
                    @endforelse
                </ol>
                <div class="mt-4">{{ $movements->links() }}</div>
            </section>
            @if($reservations->isNotEmpty())
            <section class="bg-background-secondary rounded-xl border border-neutral p-6">
                <h2 class="text-lg font-bold mb-4">{{ __('Refund reservations') }}</h2>
                @foreach($reservations as $reservation)
                <div class="border-t border-neutral py-4 text-sm">
                    <p>{{ __($reservation['label']) }}</p>
                    <p class="mt-1 tabular-nums">{{ __('Reserved principal') }}: {{ $reservation['principal'] }} {{ $currency }}</p>
                    @if($administrative)
                    <details class="mt-2 break-all text-xs"><summary class="cursor-pointer min-h-11 flex items-center">{{ __('Administrative receipt') }}</summary><pre class="whitespace-pre-wrap">{{ json_encode($reservation['audit'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                    @endif
                </div>
                @endforeach
            </section>
            @endif
            <section class="bg-background-secondary rounded-xl border border-neutral p-6 space-y-4 text-sm text-base/70">
                <h2 class="text-lg font-bold text-base">{{ __('How account funding works') }}</h2>
                <p><strong class="text-base">{{ __('Deposits repay debt first.') }}</strong> {{ __('The remaining principal becomes cash. Gateway fees do not increase your account balance.') }}</p>
                <p><strong class="text-base">{{ __('Refunds awaiting confirmation reserve funds.') }}</strong> {{ __('Reserved principal is unavailable for purchases. A reservation is not a completed refund.') }}</p>
                <p><strong class="text-base">{{ __('Refund confirmed; account update pending.') }}</strong> {{ __('New account funding is unavailable until the confirmed refund has been reconciled.') }}</p>
                <p><strong class="text-base">{{ __('When account funding is unavailable.') }}</strong> {{ __('You can still read your history. Shared account members with history access can read this statement without spending account funds.') }}</p>
                <p><strong class="text-base">{{ __('Borrowing allowance is separate from cash.') }}</strong> {{ __('Outstanding debt reduces your remaining allowance. Fractional cents stay in your balance and cannot be spent until a full cent is available.') }}</p>
            </section>
            @if($administrative)
            <section class="bg-background-secondary rounded-xl border border-neutral p-6">
                <h2 class="text-lg font-bold mb-4">{{ __('Opening lineage') }}</h2>
                <pre class="whitespace-pre-wrap break-all text-xs">{{ json_encode($opening, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </section>
            @endif
        </div>
    </div>
</div>
