<?php

namespace Paymenter\Extensions\Servers\SSLStore;

use App\Helpers\ExtensionHelper;
use App\Models\ExtensionOperation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\InvoicePaymentDependencies;
use App\Services\Service\PaidServiceLifecycle;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SSLStoreRenewals
{
    private const QUOTE_PURPOSE = 'sslstore-linked-renewal';

    public function preview(Service $service, string $csr, string $approvalEmail): array
    {
        Gate::authorize('update', $service);
        [$parent, $api, $binding] = $this->context($service);
        [$request, $agreement] = $this->supplierTerms($api, $parent, $csr, $approvalEmail);
        $fresh = $service->fresh() ?? throw new RuntimeException('The certificate service no longer exists');
        [, , $current] = $this->context($fresh);
        if ($binding !== $current) {
            throw new RuntimeException('The certificate changed while its renewal was being prepared');
        }

        $token = Crypt::encryptString(json_encode([
            'purpose' => self::QUOTE_PURPOSE, 'binding' => $binding, 'request' => $request,
            'agreement_sha256' => hash('sha256', $agreement), 'deadline' => now()->addMinutes(20)->timestamp,
        ], JSON_THROW_ON_ERROR));
        if (strlen($token) > 60000) {
            throw new RuntimeException('The certificate renewal request exceeds the quote size limit');
        }

        return [
            'token' => $token,
            'agreement' => $agreement,
            'domain' => $request['DomainName'],
            'additional_domains' => $request['DNSNames'],
            'approval_email' => $request['ApproverEmail'],
            'price' => $binding['price'], 'currency' => $binding['terms']['currency'], 'months' => $binding['terms']['months'],
        ];
    }

    public function request(Service $service, string $token, bool $accepted): Invoice
    {
        Gate::authorize('update', $service);
        $data = $this->decode($token);
        if (!$accepted || $data['deadline'] < now()->timestamp) {
            throw new RuntimeException('Review and accept the current certificate renewal agreement');
        }

        return DB::transaction(function () use ($service, $token, $data) {
            (new InvoicePaymentDependencies)->lockService($service->id, $data['binding']['parent_invoice_id']);
            $service = $this->lockedService($service);
            Gate::authorize('update', $service);
            [, , $binding] = $this->context($service, lockedParent: $data['binding']);
            if ($binding !== $data['binding'] || PaidServiceLifecycle::pending($service)) {
                throw new RuntimeException('The certificate renewal quote has changed');
            }
            $contract = json_encode([
                'service_id' => (int) $service->id, 'server_id' => $binding['terms']['server_id'],
                'extension' => 'SSLStore', 'terms' => $token,
            ], JSON_THROW_ON_ERROR);
            $pending = $service->invoices()->where('invoices.status', Invoice::STATUS_PENDING)->limit(2)->lockForUpdate()->get();
            if ($pending->isNotEmpty()) {
                $invoice = $pending->first();
                if ($pending->count() === 1 && $this->storedQuote($invoice) === $contract) {
                    $this->validateInvoice($service, $invoice, $token, currentRead: true);

                    return $invoice;
                }
                throw new RuntimeException('An open invoice already exists for this certificate');
            }
            $invoice = $service->createRenewalInvoice();
            $invoice->properties()->create(['key' => ExtensionHelper::SERVICE_RENEWAL_QUOTE, 'value' => $contract]);

            return $invoice;
        }, attempts: 3);
    }

    public function validateInvoice(Service $service, Invoice $invoice, string $token, bool $currentRead = false): array
    {
        $data = $this->decode($token);
        MigrationHold::assertAllowed($invoice, 'SSLStore renewal', currentRead: true);
        $items = $invoice->items()->where('kind', 'product')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->limit(2)->get();
        $item = $items->first();
        $recovery = $invoice->status === Invoice::STATUS_PAID && $item && $this->hasSubmittedClaim($item, $data['binding'], $token);
        [$parent, $api, $binding] = $this->context($service, lockedParent: $currentRead ? $data['binding'] : null, recovering: $recovery);
        if ($binding !== $data['binding'] || (int) $invoice->user_id !== $binding['user_id']
            || $invoice->currency_code !== $binding['terms']['currency']
            || $invoice->due_at?->toDateString() !== $service->expires_at->toDateString()
            || $items->count() !== 1 || $item->reference_type !== Service::class || (int) $item->reference_id !== (int) $service->id
            || $item->quantity !== 1 || $item->price !== $binding['price']) {
            throw new RuntimeException('The certificate renewal invoice no longer matches its quote');
        }

        return [$parent, $api, $data, $item];
    }

    public function validateForPayment(Service $service, Invoice $invoice, string $token): void
    {
        [$parent, $api, $data] = $this->validateInvoice($service, $invoice, $token);
        if ($invoice->status === Invoice::STATUS_PENDING && DB::transactionLevel() === 0) {
            $this->verifySupplierTerms($api, $parent, $data);
        }
    }

    public function fulfill(Service $service, InvoiceItem $item, string $quote): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('SSLStore renewal must run after payment commits');
        }
        $stored = json_decode($quote, true);
        $token = $stored['terms'] ?? null;
        if (($stored['extension'] ?? null) !== 'SSLStore' || !is_string($token)) {
            throw new RuntimeException('The SSLStore renewal quote is missing');
        }
        $identity = hash('sha256', SSLStoreOrders::RENEWAL . ':' . $item->id);
        $data = $this->decode($token);
        $existing = ExtensionOperation::where('identity', $identity)->first();
        if (!$existing) {
            [$parent, $api] = $this->validateInvoice($service, $item->invoice, $token);
            $this->verifySupplierTerms($api, $parent, $data);
        }
        [$operation, $api, $submit] = DB::transaction(function () use ($item, $service, $identity, $data, $token, $quote) {
            [$service, $invoice, $operation] = $this->lockPayment($service, $item, $data, $identity, $quote, $token);
            if ($operation?->status === 'complete') {
                return [$operation, null, false];
            }
            [, $api, , $line] = $this->validateInvoice($service, $invoice, $token, currentRead: true);
            if ($line->id !== $item->id) {
                throw new RuntimeException('The paid SSLStore renewal item changed');
            }
            if ($operation) {
                return [$operation, $api, false];
            }
            $binding = $data['binding'];
            $operation = ExtensionOperation::create([
                'identity' => $identity, 'kind' => SSLStoreOrders::RENEWAL, 'status' => 'submitted',
                'extension_id' => $binding['terms']['server_id'], 'user_id' => $service->user_id,
                'service_id' => $service->id, 'invoice_item_id' => $item->id,
                'payload' => [
                    'binding' => ['terms' => $binding['terms'], 'invoice_id' => (int) $invoice->id, 'price' => $binding['price']],
                    'custom_id' => 'paymenter-' . Str::lower((string) Str::ulid()),
                    'parent' => $binding['parent_order_id'], 'request_sha256' => hash('sha256', $token),
                    'due_at' => $service->calculateNextDueDate()->toDateTimeString(),
                ],
            ]);

            return [$operation, $api, true];
        }, attempts: 3);
        if ($operation->status === 'complete') {
            return;
        }
        $payload = $operation->payload;
        $terms = $payload['binding']['terms'];
        $receipt = $submit
            ? $api->renew($payload['custom_id'], $terms, $payload['parent'], $data['request'])
            : $api->orderInfo($payload['custom_id'], $terms, invitation: false, parent: $payload['parent']);
        if (!in_array($receipt['status'], ['PENDING', 'ACTIVE'], true)) {
            throw new RuntimeException('SSLStore renewal order requires review');
        }
        DB::transaction(function () use ($service, $item, $data, $token, $receipt, $identity, $quote) {
            [$service, $invoice, $operation] = $this->lockPayment($service, $item, $data, $identity, $quote, $token);
            if (!$operation) {
                throw new RuntimeException('The SSLStore renewal claim disappeared');
            }
            $payload = $operation->payload;
            if ($operation->status === 'complete') {
                if (($payload['receipt']['order_id'] ?? null) !== $receipt['order_id']) {
                    throw new RuntimeException('SSLStore renewal order identity changed');
                }

                return;
            }
            [, , , $line] = $this->validateInvoice($service, $invoice, $token, currentRead: true);
            if ($line->id !== $item->id) {
                throw new RuntimeException('The paid SSLStore renewal item changed');
            }
            $service->forceFill(['expires_at' => $payload['due_at'], 'status' => Service::STATUS_ACTIVE])->save();
            $operation->update(['status' => 'complete', 'payload' => $payload + ['receipt' => $receipt]]);
            PaidServiceLifecycle::complete($service, $item);
        }, attempts: 3);
    }

    private function context(Service $service, ?array $lockedParent = null, bool $recovering = false): array
    {
        // A reversed later renewal still occupies the supplier lineage.
        $latest = ExtensionOperation::where('service_id', $service->id)->where('status', 'complete')
            ->whereIn('kind', [SSLStoreOrders::INVITATION, SSLStoreOrders::RENEWAL])
            ->when($lockedParent !== null, fn ($query) => $query->lockForUpdate())->latest('id')->first();
        if (!$latest || ($lockedParent !== null && $latest->id !== $lockedParent['parent_operation_id'])) {
            throw new RuntimeException('The original SSLStore order has changed');
        }
        $orders = new SSLStoreOrders;
        [$parent, $api] = $orders->confirmedOrder($service, allowSuspended: true, expected: $latest,
            lockedInvoiceId: $lockedParent['parent_invoice_id'] ?? null);
        [, $terms] = $orders->terms($service->product, $service->plan, $service->currency_code);
        $payload = $parent->payload;
        if ((!$api->renewalsEnabled() && !$recovering) || !$service->expires_at || (int) $service->quantity !== 1
            || !BigDecimal::of($service->price)->isPositive() || $terms !== $payload['binding']['terms']
            || $payload['receipt']['status'] === 'CANCELLED'
            || $service->cancellation()->when($lockedParent !== null, fn ($query) => $query->lockForUpdate())->first()) {
            throw new RuntimeException('Linked certificate renewal is unavailable');
        }

        return [$parent, $api, [
            'service_id' => (int) $service->id, 'user_id' => (int) $service->user_id,
            'parent_operation_id' => (int) $parent->id, 'parent_invoice_id' => $payload['binding']['invoice_id'],
            'parent_order_id' => $payload['receipt']['order_id'], 'terms' => $terms,
            'price' => (string) BigDecimal::of($service->price)->toScale(2), 'expires_at' => $service->expires_at->toDateTimeString(),
        ]];
    }

    private function hasSubmittedClaim(InvoiceItem $item, array $binding, string $token): bool
    {
        $operation = ExtensionOperation::where([
            'identity' => hash('sha256', SSLStoreOrders::RENEWAL . ':' . $item->id), 'kind' => SSLStoreOrders::RENEWAL,
            'status' => 'submitted', 'invoice_item_id' => $item->id, 'service_id' => $binding['service_id'],
            'user_id' => $binding['user_id'], 'extension_id' => $binding['terms']['server_id'],
        ])->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();

        return $operation && ($operation->payload['request_sha256'] ?? null) === hash('sha256', $token);
    }

    private function verifySupplierTerms(SSLStoreApi $api, ExtensionOperation $parent, array $data): void
    {
        [$request, $agreement] = $this->supplierTerms($api, $parent, rawurldecode($data['request']['CSR']), $data['request']['ApproverEmail']);
        if ($request !== $data['request'] || !hash_equals($data['agreement_sha256'], hash('sha256', $agreement))) {
            throw new RuntimeException('SSLStore renewal details changed since the agreement was accepted');
        }
    }

    private function supplierTerms(SSLStoreApi $api, ExtensionOperation $parent, string $csr, string $approvalEmail): array
    {
        $payload = $parent->payload;
        $terms = $payload['binding']['terms'];
        $original = $api->renewalSource($payload['custom_id'], $terms, $payload['receipt']['order_id'],
            $parent->kind === SSLStoreOrders::INVITATION, $payload['parent'] ?? null);
        $request = SSLStoreRenewalRequest::fromOriginal($original, $csr, $approvalEmail);
        $api->verifyOffer($terms['product_code'], $terms['months'], $terms['currency']);
        $api->verifyApprover($terms['product_code'], $request['DomainName'], $request['ApproverEmail']);

        return [$request, $api->agreement($terms['product_code'])];
    }

    private function decode(string $token): array
    {
        try {
            $data = strlen($token) <= 60000 ? json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $data = null;
        }
        if (($data['purpose'] ?? null) !== self::QUOTE_PURPOSE || !is_array($data['binding'] ?? null) || !is_array($data['request'] ?? null)
            || !is_int($data['binding']['parent_invoice_id'] ?? null) || $data['binding']['parent_invoice_id'] < 1
            || !is_int($data['binding']['parent_operation_id'] ?? null) || $data['binding']['parent_operation_id'] < 1
            || !is_string($data['agreement_sha256'] ?? null) || !is_int($data['deadline'] ?? null)) {
            throw new RuntimeException('The certificate renewal quote is invalid');
        }

        return $data;
    }

    private function storedQuote(Invoice $invoice): ?string
    {
        return $invoice->properties()->where('key', ExtensionHelper::SERVICE_RENEWAL_QUOTE)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->value('value');
    }

    private function lockedService(Service $service): Service
    {
        $service = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
        MigrationHold::assertAllowed($service, 'SSLStore renewal', currentRead: true);
        $product = $service->product()->lockForUpdate()->firstOrFail();
        $server = $product->server()->lockForUpdate()->first();
        $server?->setRelation('settings', $server->settings()->lockForUpdate()->get());
        $product->setRelation('server', $server);
        $service->setRelation('product', $product);
        $service->setRelation('plan', $service->plan()->lockForUpdate()->firstOrFail());

        return $service;
    }

    private function lockPayment(Service $service, InvoiceItem $item, array $data, string $identity, string $quote, string $token): array
    {
        $binding = $data['binding'];
        (new InvoicePaymentDependencies)->lock([$binding['parent_invoice_id'], $item->invoice_id]);
        $invoice = Invoice::whereKey($item->invoice_id)->lockForUpdate()->firstOrFail();
        $service = $this->lockedService($service);
        MigrationHold::assertAllowed($item, 'SSLStore renewal', currentRead: true);
        if ($invoice->status !== Invoice::STATUS_PAID || $this->storedQuote($invoice) !== $quote) {
            throw new RuntimeException('SSLStore requires a paid certificate renewal invoice');
        }
        $operation = ExtensionOperation::where('identity', $identity)->lockForUpdate()->first();
        if ($operation && ($operation->kind !== SSLStoreOrders::RENEWAL || (int) $operation->invoice_item_id !== (int) $item->id
            || (int) $operation->service_id !== (int) $service->id || (int) $operation->user_id !== $binding['user_id']
            || (int) $operation->extension_id !== $binding['terms']['server_id']
            || !in_array($operation->status, ['submitted', 'complete'], true)
            || $operation->payload['parent'] !== $binding['parent_order_id']
            || $operation->payload['request_sha256'] !== hash('sha256', $token)
            || $operation->payload['binding'] !== ['terms' => $binding['terms'], 'invoice_id' => (int) $invoice->id, 'price' => $binding['price']])) {
            throw new RuntimeException('The SSLStore renewal claim no longer matches the paid order');
        }

        return [$service, $invoice, $operation];
    }
}
