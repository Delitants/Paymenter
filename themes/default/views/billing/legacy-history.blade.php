<div class="container mt-14 space-y-4">
    <h1 class="text-2xl font-semibold">{{ __('Billing history') }}</h1>
    <p>{{ __('Historical records from BILLmanager. Current billing continues in the original portal until handover.') }}</p>
    @php($types = ['payments' => __('Payment'), 'invoices' => __('Accounting invoice'), 'subaccounts' => __('Account balance')])
    @if ($record)
        <a href="{{ route('billing.history') }}" wire:navigate class="text-primary underline">{{ __('All billing history') }}</a>
        <section class="bg-background-secondary border border-neutral rounded-lg p-4 space-y-3">
            <h2 class="text-xl">{{ $types[$record->source_table] }} {{ $record->number ?? $record->source_id }}</h2>
            <p>{{ __('Status') }}: {{ __(ucfirst(str_replace('_', ' ', $record->status))) }}</p>
            <p class="font-semibold">{{ $record->amount }} {{ $record->currency_code }}</p>
            @php($detail = $record->details)
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-2 break-words">
                @foreach (['createdate' => 'Created', 'paydate' => 'Payment date', 'cdate' => 'Document date', 'documentnumber' => 'Receipt number', 'documentdate' => 'Receipt date', 'externalid' => 'Payment provider reference', 'commissionamount' => 'Commission', 'taxamount' => 'Tax amount', 'taxrate' => 'Tax rate', 'usedamount' => 'Used amount', 'creditlimit' => 'Credit limit', 'allowpostpaid' => 'Postpaid allowed', 'description' => 'Description'] as $field => $label)
                    @if (isset($detail[$field]) && $detail[$field] !== '')
                        <div><dt class="font-medium">{{ __($label) }}</dt><dd>{{ $detail[$field] }}</dd></div>
                    @endif
                @endforeach
            </dl>
            @if ($record->source_table === 'subaccounts')
                <p>{{ __('This balance is held and cannot be spent in Paymenter before billing handover.') }}</p>
                <p>{{ __('Two-decimal equivalent') }}: {{ $detail['rounded_amount'] }} {{ $record->currency_code }};
                    {{ __('rounding difference') }}: {{ $detail['rounding_delta'] }} {{ $record->currency_code }}</p>
            @endif
            @if ($record->source_table === 'invoices')
                <p>{{ __('This is an accounting document. Its document status does not indicate whether a payment was received.') }}</p>
                @if ($record->native_invoice)
                    <a class="text-primary underline" href="{{ route('invoices.show', $record->native_invoice) }}" wire:navigate>{{ __('View matched paid invoice') }}</a>
                @endif
                @foreach (['issuer' => 'Issued by', 'payer' => 'Billed to'] as $key => $label)
                    @if (!empty($detail[$key]))
                        <div><h3 class="font-semibold">{{ __($label) }}</h3>
                            @foreach (['name', 'person', 'address_legal', 'city_legal', 'state_legal', 'postcode_legal', 'vatnum'] as $field)
                                @if (!empty($detail[$key][$field]))<p>{{ $detail[$key][$field] }}</p>@endif
                            @endforeach
                        </div>
                    @endif
                @endforeach
                <div class="overflow-x-auto">
                    <table class="w-full text-left"><thead><tr><th>{{ __('Description') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Tax') }}</th></tr></thead><tbody>
                        @foreach ($detail['line_items'] as $item)
                            <tr><td>{{ $item['name'] }}</td><td>{{ $item['amount'] }}</td><td>{{ $item['taxamount'] ?? '' }}</td></tr>
                        @endforeach
                    </tbody></table>
                </div>
            @endif
            <p class="text-sm">{{ __('Dates and amounts are shown as recorded in BILLmanager.') }}</p>
        </section>
    @else
        <label class="block">{{ __('Record type') }}
            <select wire:model.live="kind" class="bg-background-secondary border border-neutral rounded p-2">
                <option value="">{{ __('All records') }}</option>
                @foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        @forelse ($records as $entry)
            <a href="{{ route('billing.history.show', $entry) }}" wire:navigate class="block bg-background-secondary border border-neutral rounded-lg p-4">
                <span class="font-semibold">{{ $types[$entry->source_table] }} {{ $entry->number ?? $entry->source_id }}</span>
                <span class="block">{{ $entry->amount }} {{ $entry->currency_code }} — {{ __(ucfirst(str_replace('_', ' ', $entry->status))) }}</span>
            </a>
        @empty
            <p>{{ __('No billing history has been imported for this account.') }}</p>
        @endforelse
        {{ $records->links() }}
    @endif
</div>
