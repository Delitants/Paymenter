<?php

namespace App\Admin\Actions;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\AccountFundingAllocation;
use App\Models\AccountReversalReservation;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\Accounts\AccountFundingReversals;
use App\Services\Accounts\DepositLifecycle;
use App\Services\Gateways\Operations\GatewayOperations;
use App\Services\Gateways\Operations\ManualSettlements;
use App\Services\Gateways\Operations\OperationPolicy;
use App\Services\Gateways\Operations\ProviderOperations;
use App\Services\Gateways\Operations\RefundAllocation;
use App\Services\Gateways\Operations\Refunds;
use Brick\Math\BigDecimal;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Paymenter\Extensions\Gateways\Klarna\ConversionQuote;
use RuntimeException;

/** Native form consumers; all money writes and allocation use the shared services. */
final class PaymentActions
{
    public static function allowed(string $permission, ?Invoice $invoice = null, ?Gateway $gateway = null): bool
    {
        $actor = auth()->user();
        if (!$actor || !User::find($actor->id)?->hasPermission('admin.invoice_transactions.' . $permission)) {
            return false;
        }
        if ($invoice && $gateway) {
            try {
                (new OperationPolicy)->authorize($actor, $permission, $invoice, $gateway);
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    public static function receipt(Closure $invoice): Action
    {
        return Action::make('manual_receipt')->label('Record manual settlement')
            ->visible(fn () => self::allowed('manual_settle'))
            ->modalDescription('Record money already received outside the gateway. This creates an audited receipt in the invoice currency.')
            ->schema([
                Hidden::make('payment_binding')->default(fn () => self::binding($invoice())),
                Select::make('gateway_id')->label('Gateway / method')->options(fn () => Gateway::whereHas('settings', fn ($q) => $q->where('key', 'admin_payment_operations_enabled')->whereIn('value', ['1', 'true']))->pluck('name', 'id'))->required(),
                Text::make(fn () => 'Invoice currency: ' . $invoice()->currency_code),
                TextInput::make('amount')->label('Received amount')->required()->numeric()->minValue(0.01),
                TextInput::make('reference')->label('External receipt reference')->required()->maxLength(190),
                ...self::auditFields(),
            ])
            ->action(function (array $data, Action $action) use ($invoice) {
                self::perform(function () use ($data, $invoice) {
                    $record = $invoice();
                    self::assertBinding($record, $data);

                    return (new ManualSettlements)->record(auth()->user(), $record, Gateway::findOrFail($data['gateway_id']), (string) $data['amount'],
                        $data['reference'], $data['reason'], $data['effective_at'], $data['request_key']);
                }, $action);
            });
    }

    public static function transactionActions(): array
    {
        return [self::accountReversal(), self::refund(false), self::refund(true), self::transition(false), self::transition(true)];
    }

    private static function accountReversal(): Action
    {
        return Action::make('account_reverse')->label('Reverse account funding')
            ->visible(function (InvoiceTransaction $record) {
                $allocation = AccountFundingAllocation::where('invoice_transaction_id', $record->id)->first();

                return auth()->user() && $allocation && (new AccountFundingReversals)->canReverse(auth()->user(), $allocation);
            })
            ->modalDescription('Restore all or part of this account payment to the original account. The reversed amount becomes payable again; service fulfillment is preserved.')
            ->schema([TextInput::make('amount')->label('Amount to reverse')->required()->numeric()->minValue(0.01),
                Textarea::make('reason')->required()->minLength(3)->maxLength(2000), Hidden::make('request_key')->default(fn () => (string) Str::uuid())])
            ->action(function (InvoiceTransaction $record, array $data, Action $action) {
                try {
                    $allocation = AccountFundingAllocation::where('invoice_transaction_id', $record->id)->sole();
                    (new AccountFundingReversals)->reverse(auth()->user(), $allocation, (string) $data['amount'], $data['reason'], $data['request_key']);
                } catch (RuntimeException|\DomainException $e) {
                    self::formError($action, new RuntimeException($e->getMessage()));
                }
                $action->getLivewire()->dispatch('admin-payment-operation-completed', invoiceId: $record->invoice_id)->to(EditInvoice::class);
                Notification::make()->title('Account funding reversed')->body($data['amount'] . ' ' . $allocation->currency_code . ' restored to the original account.')->success()->send();
            });
    }

    private static function refund(bool $provider): Action
    {
        return Action::make($provider ? 'provider_refund' : 'external_refund')
            ->label($provider ? 'Refund at provider' : 'Record completed external refund')
            ->visible(function (InvoiceTransaction $record) use ($provider) {
                return $record->gateway && self::allowed('refund', $record->invoice, $record->gateway) && !PaymentOperation::where('original_transaction_id', $record->id)->whereIn('state', ['queued', 'processing', 'pending', 'uncertain'])->exists() &&
                    (!$provider || ($record->settlement_origin !== 'manual_record' && (app(GatewayOperations::class)->for($record->gateway)->capabilities()['refund'] ?? false)));
            })
            ->modalDescription($provider ? 'Submit once against the original provider payment. Unknown or pending results reserve funds until reconciliation. A verified void is distinct from a refund.' : 'Record a refund already completed outside Paymenter. This action does not move money at the gateway.')
            ->schema([
                Hidden::make('payment_binding')->default(fn (InvoiceTransaction $record) => self::binding($record)),
                Select::make('refund_mode')->label('Refund amount')->options(['full' => 'Full eligible amount', 'partial' => 'Partial amount'])->default('full')->required()->live(),
                Toggle::make('include_fee')->label('Allow refund of customer gateway fee')->default(false)->live()
                    ->helperText('Raises the eligible maximum. A partial amount consumes product net and tax first; the fee is refunded only when that amount exceeds remaining product money. The fee has zero tax.'),
                TextInput::make('amount')->label('Total partial refund amount')->numeric()->minValue(0.01)->required(fn (Get $get) => $get('refund_mode') === 'partial')
                    ->visible(fn (Get $get) => $get('refund_mode') === 'partial')->live(debounce: 500),
                Text::make(function (Get $get, InvoiceTransaction $record) use ($provider): string {
                    try {
                        $quote = self::refundPreview(auth()->user(), $record, $get('refund_mode') ?? 'full', $get('amount'), (bool) $get('include_fee'), $provider);

                        $converted = $provider && isset($quote['provider_amount']) ? ' Klarna will refund ' . $quote['provider_amount'] . ' ' . $quote['provider_currency'] . ' using the original conversion.' : '';

                        return 'Refund ' . $quote['amount'] . ' ' . $quote['currency'] . ': product net ' . $quote['allocation']['net'] . ', tax ' . $quote['allocation']['tax'] . ', untaxed customer fee ' . $quote['allocation']['fee'] . '. Eligible maximum ' . $quote['remaining'] . '; available afterward ' . $quote['remaining_after'] . '.' . $converted;
                    } catch (\Throwable $e) {
                        return $e instanceof RuntimeException ? $e->getMessage() : 'Refund preview unavailable for the current permission or payment.';
                    }
                }),
                ...($provider ? [] : [TextInput::make('reference')->label('External refund reference')->required()->maxLength(190)]),
                ...self::auditFields(!$provider),
            ])
            ->action(function (InvoiceTransaction $record, array $data, Action $action) use ($provider) {
                self::perform(function () use ($record, $data, $provider) {
                    self::assertBinding($record, $data);
                    $quote = self::refundPreview(auth()->user(), $record, $data['refund_mode'], $data['amount'] ?? null, (bool) $data['include_fee'], $provider);
                    $refunds = new Refunds;

                    return $provider ? $refunds->submit(auth()->user(), $record, $quote['amount'], (bool) $data['include_fee'], $data['reason'], $data['request_key']) :
                        $refunds->recordExternal(auth()->user(), $record, $quote['amount'], (bool) $data['include_fee'], $data['reference'], $data['reason'], $data['effective_at'], $data['request_key']);
                }, $action);
            });
    }

    public static function refundPreview(User $actor, InvoiceTransaction $transaction, string $mode, mixed $amount, bool $includeFee, bool $provider = false): array
    {
        if (!$transaction->gateway) {
            throw new RuntimeException('Original payment gateway is missing.');
        }
        (new OperationPolicy)->authorize($actor, 'refund', $transaction->invoice, $transaction->gateway);
        if (!in_array($mode, ['full', 'partial'], true)) {
            throw new RuntimeException('Choose full or partial refund.');
        }
        $allocation = new RefundAllocation;
        $amount = $mode === 'full' ? $allocation->available($transaction, $includeFee) : (string) $amount;
        if ($mode === 'full' && $amount === '0.00') {
            throw new RuntimeException('No refundable amount remains for this fee choice.');
        }

        $preview = $allocation->quote($transaction, $amount, $includeFee) + ['amount' => $amount, 'currency' => $transaction->invoice->currency_code];
        if ($provider && $transaction->gateway->extension === 'Klarna') {
            $attempts = GatewayPaymentAttempt::where('invoice_id', $transaction->invoice_id)->where('gateway_id', $transaction->gateway_id)->where('state', 'paid')->get()
                ->filter(fn ($a) => $a->provider_transaction_id && $transaction->transaction_id === 'gateway:' . $a->gateway_id . ':' . $a->provider_transaction_id);
            if ($attempts->count() === 1 && isset($attempts->first()->provider_payload['conversion_quote'])) {
                $converter = new ConversionQuote;
                $q = $converter->validate($attempts->first());
                $minor = $converter->refund($q, BigDecimal::of($transaction->refunded_amount)->multipliedBy(100)->toInt(), BigDecimal::of($amount)->multipliedBy(100)->toInt());
                $preview += ['provider_amount' => (string) BigDecimal::of($minor)->dividedBy(100, 2), 'provider_currency' => $q['provider_currency']];
            }
        }

        return $preview;
    }

    private static function transition(bool $restore): Action
    {
        return Action::make($restore ? 'manual_restore' : 'manual_unsettle')->label($restore ? 'Restore manual settlement' : 'Mark manual receipt unsettled')
            ->visible(fn (InvoiceTransaction $record) => $record->settlement_origin === 'manual_record' && $record->settlement_state === ($restore ? 'unsettled' : 'settled') && $record->gateway &&
                self::allowed($restore ? 'manual_settle' : 'manual_unsettle', $record->invoice, $record->gateway) && !PaymentOperation::where('original_transaction_id', $record->id)->whereIn('kind', ['provider_refund', 'external_refund'])->whereIn('state', RefundAllocation::RESERVED)->exists())
            ->modalDescription('Record a correction while preserving the original payment and history. No provider refund or void is issued.')
            ->schema([Hidden::make('payment_binding')->default(fn (InvoiceTransaction $record) => self::binding($record)), ...self::auditFields()])
            ->action(function (InvoiceTransaction $record, array $data, Action $action) use ($restore) {
                self::perform(function () use ($record, $data, $restore) {
                    self::assertBinding($record, $data);
                    $service = new ManualSettlements;

                    return $restore ? $service->restore(auth()->user(), $record, $data['reason'], $data['effective_at'], $data['request_key']) :
                        $service->unsettle(auth()->user(), $record, $data['reason'], $data['effective_at'], $data['request_key']);
                }, $action);
            });
    }

    public static function capture(Closure $invoice): Action
    {
        return Action::make('capture_authorization')->label('Review authorization capture')
            ->visible(function () use ($invoice) {
                if (!self::allowed('capture') || PaymentOperation::where('invoice_id', $invoice()->id)->whereIn('state', ['queued', 'processing', 'pending', 'uncertain'])->exists()) {
                    return false;
                }
                $attempts = GatewayPaymentAttempt::where('invoice_id', $invoice()->id)->whereIn('state', ['open', 'initializing', 'paid'])->get();
                $gateway = $attempts->count() === 1 && $attempts->first()->state === 'open' ? $attempts->first()->gateway : null;

                return $gateway && self::allowed('capture', $invoice(), $gateway) && (app(GatewayOperations::class)->for($gateway)->capabilities()['capture'] ?? false);
            })
            ->fillForm(function (Action $action) use ($invoice) {
                try {
                    $preview = (new ProviderOperations)->capturePreview(auth()->user(), $invoice());

                    return $preview + ['payment_binding' => self::binding($invoice(), $preview), 'request_key' => (string) Str::uuid()];
                } catch (RuntimeException $e) {
                    self::formError($action, $e);
                }
            })
            ->modalDescription('Authenticated read-only eligibility is required for the existing native authorization. Confirmation rechecks eligibility and submits once. Automatic capture remains unchanged.')
            ->schema([
                Hidden::make('payment_binding'), Hidden::make('gateway_id'), Hidden::make('reference'), Hidden::make('amount'), Hidden::make('currency'),
                Text::make(fn (Get $get) => 'Gateway verified authorization ' . $get('reference') . ': capture ' . $get('amount') . ' ' . $get('currency')),
                Text::make(function () use ($invoice): string {
                    $attempts = GatewayPaymentAttempt::where('invoice_id', $invoice()->id)->where('state', 'open')->get();
                    if ($attempts->count() !== 1 || $attempts->first()->gateway?->extension !== 'Klarna' || !isset($attempts->first()->provider_payload['conversion_quote'])) {
                        return '';
                    }
                    $quote = (new ConversionQuote)->validate($attempts->first());

                    return 'Klarna will capture ' . BigDecimal::of($quote['allocation']['order_amount'])->dividedBy(100, 2) . ' ' . $quote['provider_currency'] . ' against the original USD invoice.';
                }),
                ...self::auditFields(false),
            ])
            ->action(function (array $data, Action $action) use ($invoice) {
                self::perform(function () use ($data, $invoice) {
                    $record = $invoice();
                    $preview = array_intersect_key($data, array_flip(['gateway_id', 'reference', 'amount', 'currency']));
                    self::assertBinding($record, $data, $preview);

                    return (new ProviderOperations)->capture(auth()->user(), $record, Gateway::findOrFail($data['gateway_id']), $data['reference'], $data['reason'], $data['request_key']);
                }, $action);
            });
    }

    public static function resume(): Action
    {
        return Action::make('resume')->label('Resume unstarted request')->requiresConfirmation()
            ->modalDescription('Submit this queued refund or capture once using its original amount, reason and request UUID. The original administrator remains recorded; you are recorded as its executor. Requests already started require provider readback instead.')
            ->visible(fn (PaymentOperation $record) => in_array($record->kind, ['provider_refund', 'provider_capture'], true) && $record->state === 'queued' &&
                self::allowed($record->kind === 'provider_capture' ? 'capture' : 'refund', $record->invoice, $record->gateway))
            ->action(fn (PaymentOperation $record, Action $action) => self::perform(fn () => (new ProviderOperations)->resume(auth()->user(), $record), $action));
    }

    public static function reconcile(): Action
    {
        return Action::make('reconcile')->label(fn (PaymentOperation $record) => self::nativePostingRequired($record) ? 'Complete account posting' : 'Reconcile by provider readback')->requiresConfirmation()
            ->modalDescription(fn (PaymentOperation $record) => self::nativePostingRequired($record) ? 'Apply the verified original refund to the account after its posting conflict has been resolved. This uses the stored result and makes no provider request.' : 'Read the existing request at the provider. This never retries a refund or capture write.')
            ->visible(fn (PaymentOperation $record) => (in_array($record->kind, ['provider_refund', 'provider_capture'], true) || self::nativePostingRequired($record)) && (in_array($record->state, ['processing', 'pending', 'uncertain'], true) || self::nativePostingRequired($record)) && self::allowed('reconcile', $record->invoice, $record->gateway))
            ->action(fn (PaymentOperation $record, Action $action) => self::perform(function () use ($record) {
                if (self::nativePostingRequired($record)) {
                    (new DepositLifecycle)->finalizeRefund($record);

                    return $record->fresh();
                }

                return (new ProviderOperations)->reconcile(auth()->user(), $record);
            }, $action));
    }

    private static function nativePostingRequired(PaymentOperation $record): bool
    {
        return in_array($record->kind, ['provider_refund', 'external_refund'], true) && in_array($record->state, ['succeeded', 'failed'], true) && AccountReversalReservation::where('payment_operation_id', $record->id)->where('state', 'reserved')->where('posting_required', true)->exists();
    }

    public static function columns(): array
    {
        return [
            TextColumn::make('settlement_label')->label('Settlement')->badge(),
            TextColumn::make('settlement_origin')->label('Provenance')->formatStateUsing(fn ($state) => match ($state) {
                'manual_record' => 'Admin recorded', 'manual_capture' => 'Admin requested / gateway verified', default => 'Automatic / historical',
            })->placeholder('Automatic / historical'),
            TextColumn::make('invoice.currency_code')->label('Currency'),
            TextColumn::make('settlement_actor')->label('Administrator')->state(fn (InvoiceTransaction $record) => self::receiptOperation($record)?->actor_snapshot['name'] ?? null)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('settlement_reference')->label('Receipt reference')->state(fn (InvoiceTransaction $record) => ($operation = self::receiptOperation($record)) ? self::reference($operation) : $record->transaction_id)->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('settlement_reason')->label('Reason')->state(fn (InvoiceTransaction $record) => self::receiptOperation($record)?->reason)->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('settlement_effective_at')->label('Effective time (UTC)')->state(fn (InvoiceTransaction $record) => self::receiptOperation($record)?->effective_at)->dateTime()->timezone('UTC')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('refunded_amount')->label('Refunded'),
            TextColumn::make('reserved_refund')->label('Reserved')->state(fn (InvoiceTransaction $record) => self::reserved($record)),
            TextColumn::make('available_refund')->label('Available (including fee)')->state(function (InvoiceTransaction $record) {
                try {
                    return (new RefundAllocation)->available($record, true);
                } catch (RuntimeException $e) {
                    return $e->getMessage();
                }
            })->wrap(),
        ];
    }

    private static function receiptOperation(InvoiceTransaction $record): ?PaymentOperation
    {
        return PaymentOperation::where('result_transaction_id', $record->id)->whereIn('kind', ['manual_receipt', 'provider_capture'])->where('state', 'succeeded')->first();
    }

    public static function reserved(InvoiceTransaction $record): string
    {
        $amount = BigDecimal::of('0.00');
        foreach (PaymentOperation::where('original_transaction_id', $record->id)->whereIn('kind', ['provider_refund', 'external_refund'])->whereIn('state', ['queued', 'processing', 'pending', 'uncertain'])->get() as $operation) {
            $amount = $amount->plus($operation->amount);
        }

        return (string) $amount->toScale(2);
    }

    public static function reference(PaymentOperation $record): ?string
    {
        return $record->payload['reference'] ?? $record->payload['provider_context']['original_reference'] ?? $record->originalTransaction?->transaction_id;
    }

    public static function resultLabel(PaymentOperation $record): string
    {
        return match ($record->outcome_code) {
            'provider_void' => 'Gateway verified void', 'external_recorded' => 'Completed external refund — admin recorded',
            'manual_recorded' => 'Manually settled — admin recorded', 'unsettled' => 'Unsettled', 'settled' => 'Manual settlement restored',
            default => match ($record->state) {
                'succeeded' => $record->kind === 'provider_capture' ? 'Manually settled — gateway verified' : 'Gateway verified refund',
                'failed' => 'Failed — provider confirmed', 'queued' => 'Queued — not executed',
                default => ucfirst($record->state) . ' — reconciliation required',
            },
        };
    }

    private static function auditFields(bool $date = true): array
    {
        return [Hidden::make('request_key')->default(fn () => (string) Str::uuid())->required(),
            Textarea::make('reason')->required()->minLength(3)->maxLength(2000),
            ...($date ? [DateTimePicker::make('effective_at')->label('Effective date / time (UTC)')->timezone('UTC')->default(now()->utc())->seconds()->required()] : []),
        ];
    }

    public static function binding(Invoice|InvoiceTransaction $record, array $extra = []): string
    {
        $identity = $record instanceof Invoice ? $record->only(['id', 'user_id', 'currency_code']) :
            $record->only(['id', 'invoice_id', 'gateway_id', 'transaction_id', 'amount', 'status', 'settlement_origin', 'settlement_state']);
        if ($record instanceof InvoiceTransaction) {
            $identity['invoice_identity'] = $record->invoice->only(['id', 'user_id', 'currency_code']);
            $identity['operations'] = PaymentOperation::where(fn ($q) => $q->where('original_transaction_id', $record->id)->orWhere('result_transaction_id', $record->id))
                ->orderBy('id')->get(['id', 'kind', 'state', 'amount', 'request_key'])->toArray();
        }
        ksort($extra);

        return hash_hmac('sha256', json_encode([$record::class, $identity, $extra], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private static function assertBinding(Invoice|InvoiceTransaction $record, array $data, array $extra = []): void
    {
        if (!is_string($data['payment_binding'] ?? null) || !hash_equals(self::binding($record, $extra), $data['payment_binding'])) {
            throw new RuntimeException('Payment identity changed; close this form and refresh the original payment.');
        }
    }

    private static function formError(Action $action, RuntimeException $error): never
    {
        $livewire = $action->getLivewire();
        $name = $livewire->getMountedActionSchemaName();
        $path = $name ? $livewire->getSchema($name)?->getStatePath() : null;
        throw ValidationException::withMessages([($path ? $path . '.' : '') . 'reason' => $error->getMessage()]);
    }

    private static function perform(Closure $callback, Action $action): void
    {
        try {
            $operation = $callback();
        } catch (RuntimeException $e) {
            if ($action->getName() === 'reconcile') {
                Notification::make()->title('Reconciliation refused')->body($e->getMessage())->danger()->send();

                return;
            }
            self::formError($action, $e);
        }
        $action->getLivewire()->dispatch('admin-payment-operation-completed', invoiceId: $operation->invoice_id)->to(EditInvoice::class);
        $notification = Notification::make()->title(self::resultLabel($operation))->body('Operation #' . $operation->id . ': ' . $operation->amount . ' ' . $operation->currency_code);
        ($operation->state === 'succeeded' ? $notification->success() : $notification->warning())->send();
    }
}
