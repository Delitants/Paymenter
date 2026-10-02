<?php

namespace App\Services\Gateways\Operations;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GatewayOperations
{
    public function for(Gateway $gateway): Adapter
    {
        $settings = $gateway->settings();
        if (DB::transactionLevel() > 0) {
            $settings->lockForUpdate();
        }
        $gateway->setRelation('settings', $settings->get());
        $extensionClass = '\\Paymenter\\Extensions\\Gateways\\' . $gateway->extension . '\\' . $gateway->extension;
        if (class_exists($extensionClass)) {
            $extension = ExtensionHelper::getExtension('gateway', $gateway->extension, $gateway->settings);
            $extension->bindRecord($gateway);
            if ($adapter = $extension->paymentOperations()) {
                return $adapter;
            }
        }
        $class = __NAMESPACE__ . '\\Adapters\\' . $gateway->extension;
        if (in_array($gateway->extension, ['Klarna', 'AuthorizeNet', 'Stripe', 'PayPal', 'Mollie', 'WebMoney'], true) && class_exists($class)) {
            return new $class($gateway);
        }
        $unsupported = __NAMESPACE__ . '\\Adapters\\Unsupported';
        if (class_exists($unsupported)) {
            return new $unsupported($gateway);
        }

        return new class implements Adapter
        {
            public function capabilities(): array
            {
                return ['refund' => false, 'capture' => false, 'reconcile' => false, 'reason' => 'authorized_provider_operations_unavailable'];
            }

            public function fingerprint(): string
            {
                throw new RuntimeException('Authorized provider operations are unavailable.');
            }

            public function prepare(Invoice $invoice, ?InvoiceTransaction $transaction, string $kind, string $providerReference, string $amount, string $currency): array
            {
                throw new RuntimeException('Authorized provider operations are unavailable; record a completed external refund instead.');
            }

            public function execute(PaymentOperation $operation): OperationResult
            {
                throw new RuntimeException('Authorized provider operations are unavailable.');
            }

            public function reconcile(PaymentOperation $operation): OperationResult
            {
                return new OperationResult('uncertain', outcomeCode: 'unsupported_reconciliation');
            }
        };
    }
}
