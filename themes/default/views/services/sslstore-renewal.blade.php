@component('layouts.app')
    <main class="container max-w-3xl space-y-6 py-8">
        <a href="{{ route('services.show', $service) }}" class="text-primary hover:underline">{{ __('Back to service') }}</a>
        <h1 class="text-2xl font-semibold">{{ __('Renew certificate') }}</h1>

        @if ($preview)
            <dl class="grid gap-2 sm:grid-cols-2">
                <dt>{{ __('Certificate') }}</dt>
                <dd>{{ $preview['domain'] }}</dd>
                @if ($preview['additional_domains'])
                    <dt>{{ __('Additional certificate names') }}</dt>
                    <dd class="break-words">{{ implode(', ', $preview['additional_domains']) }}</dd>
                @endif
                <dt>{{ __('Approval email') }}</dt>
                <dd>{{ $preview['approval_email'] }}</dd>
                <dt>{{ __('Renewal term') }}</dt>
                <dd>{{ __(':months months', ['months' => $preview['months']]) }}</dd>
                <dt>{{ __('Renewal price') }}</dt>
                <dd>{{ $preview['price'] }} {{ $preview['currency'] }}</dd>
            </dl>
            <p class="text-sm">{{ __('Your invoice shows any applicable tax and payment fees.') }}</p>
            <section class="space-y-2">
                <h2 class="text-lg font-medium">{{ __('Subscriber agreement') }}</h2>
                <div tabindex="0" class="max-h-80 overflow-auto whitespace-pre-wrap rounded-lg border border-neutral p-4">{{ $preview['agreement'] }}</div>
            </section>
            <form method="post" action="{{ route('services.sslstore.renewal.confirm', $service) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="quote" value="{{ $preview['token'] }}">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="accepted" value="1" required>
                    {{ __('I accept the subscriber agreement and authorize renewal using the original certificate contacts.') }}
                </label>
                <x-button.primary type="submit">{{ __('Create renewal invoice') }}</x-button.primary>
            </form>
        @else
            <form method="post" action="{{ route('services.sslstore.renewal.preview', $service) }}" class="space-y-4">
                @csrf
                <label class="block space-y-2">
                    <span>{{ __('Certificate signing request (CSR)') }}</span>
                    <textarea name="csr" rows="9" maxlength="16384" required spellcheck="false" class="w-full rounded-lg border border-neutral bg-background p-3 font-mono"></textarea>
                </label>
                <p class="text-sm">{{ __('Use a signed request for the same certificate names. Keep the private key on your server.') }}</p>
                <label class="block space-y-2">
                    <span>{{ __('Approval email (optional)') }}</span>
                    <input name="approval_email" type="email" maxlength="254" class="w-full rounded-lg border border-neutral bg-background p-3">
                </label>
                <p class="text-sm">{{ __('Leave the address empty to use the original approval email.') }}</p>
                <x-button.primary type="submit">{{ __('Review renewal') }}</x-button.primary>
            </form>
        @endif
    </main>
@endcomponent
