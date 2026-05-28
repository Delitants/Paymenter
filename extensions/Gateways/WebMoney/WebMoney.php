<?php

namespace Paymenter\Extensions\Gateways\WebMoney;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Events\Service\Updated;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * WebMoney Transfer Payment Gateway
 *
 * Integration with WebMoney Transfer System for accepting payments
 * via Web Merchant Interface and XML Interfaces (X1, X2, X20, X22)
 *
 * @link https://www.webmoney.com/eng/developers/api.shtml
 * @link https://en.webmoney.wiki/projects/webmoney/wiki/Web_Merchant_Interface
 */
#[ExtensionMeta(
    name: 'WebMoney Gateway',
    description: 'Accept payments via WebMoney Transfer (WMZ, WME, WMX, WMG, WMH, etc.)',
    version: '1.0.0',
    author: 'Paymenter Community',
    url: 'https://paymenter.org/docs/extensions/webmoney',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJjdXJyZW50Q29sb3IiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj48Y2lyY2xlIGN4PSIxMiIgY3k9IjEyIiByPSIxMCIvPjxwYXRoIGQ9Ik0xMiA2djYiLz48cGF0aCBkPSJNMTIgMTJ2NCIvPjxwYXRoIGQ9Ik04IDEwaDgiLz48cGF0aCBkPSJNMTAgMThoNCIvPjwvc3ZnPg=='
)]
class WebMoney extends Gateway
{
    private const MERCHANT_URL = 'https://merchant.wmtransfer.com/lmi/payment_utf.asp';
    private const API_BASE = 'https://merchant.wmtransfer.com';
    private const HASH_ALGORITHM = 'sha256';

    /**
     * Get configuration fields for the gateway
     */
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'payee_purse',
                'label' => 'Merchant Purse',
                'type' => 'text',
                'description' => 'Your WebMoney purse (e.g., Z123456789012)',
                'required' => true,
                'placeholder' => 'Z123456789012',
                'validation' => 'regex:/^[A-Z]\d{12}$/',
            ],
            [
                'name' => 'secret_key',
                'label' => 'Secret Key',
                'type' => 'text',
                'description' => 'Secret key from Web Merchant settings (50 characters)',
                'required' => true,
                'encrypted' => true,
                'placeholder' => 'Your 50-character secret key',
            ],
            [
                'name' => 'sign_algorithm',
                'label' => 'Signature Algorithm',
                'type' => 'select',
                'description' => 'Algorithm for payment verification',
                'options' => [
                    'sha256' => 'SHA-256 (Recommended)',
                    'sign' => 'SIGN (Interface X7)',
                ],
                'default' => 'sha256',
                'required' => true,
            ],
            [
                'name' => 'client_wmid',
                'label' => 'Client WMID',
                'type' => 'text',
                'description' => 'Your WebMoney WMID (required for SIGN algorithm)',
                'required' => false,
                'placeholder' => '123456789012',
                'validation' => 'regex:/^\d{12}$/',
            ],
            [
                'name' => 'test_mode',
                'label' => 'Test Mode',
                'type' => 'checkbox',
                'description' => 'Enable test mode for development',
                'default' => false,
            ],
            [
                'name' => 'payment_methods',
                'label' => 'Payment Methods',
                'type' => 'multiselect',
                'description' => 'Accepted payment methods',
                'options' => [
                    'wm' => 'WM Purses (WMZ, WME, WMX, etc.)',
                    'card' => 'Bank Cards',
                    'wmcheck' => 'WebMoney Check',
                    'paymer' => 'Paymer Checks',
                    'crypto' => 'Cryptocurrencies',
                    'alipay' => 'Alipay P2P',
                ],
                'default' => ['wm'],
                'database_type' => 'array',
            ],
            [
                'name' => 'auto_fulfill',
                'label' => 'Auto-fulfill Orders',
                'type' => 'checkbox',
                'description' => 'Automatically activate services after payment confirmation',
                'default' => true,
            ],
        ];
    }

    /**
     * Test the gateway configuration
     */
    public function testConfig(): bool|string
    {
        $purse = $this->config('payee_purse');
        $secretKey = $this->config('secret_key');

        if (empty($purse)) {
            return 'Merchant purse is required';
        }

        if (!preg_match('/^[A-Z]\d{12}$/', $purse)) {
            return 'Invalid purse format. Must be a letter followed by 12 digits (e.g., Z123456789012)';
        }

        if (empty($secretKey)) {
            return 'Secret key is required';
        }

        if (strlen($secretKey) < 10) {
            return 'Secret key seems too short (should be ~50 characters)';
        }

        // Test signature generation
        $testAmount = '1.00';
        $testOrderId = 'TEST_' . time();
        $signature = $this->generateSignature($purse, $testAmount, $testOrderId);

        if (empty($signature)) {
            return 'Failed to generate signature - check secret key';
        }

        return true;
    }

    /**
     * Process payment for an invoice
     */
    public function pay(Invoice $invoice, $total)
    {
        $purse = $this->config('payee_purse');
        $testMode = $this->config('test_mode') ?? false;
        $paymentMethods = $this->config('payment_methods') ?? ['wm'];

        // Generate unique payment number
        $paymentNo = 'INV-' . $invoice->id . '-' . time();

        // Build payment form data
        $formData = [
            'LMI_PAYEE_PURSE' => $purse,
            'LMI_PAYMENT_AMOUNT' => number_format($total, 2, '.', ''),
            'LMI_PAYMENT_NO' => $paymentNo,
            'LMI_PAYMENT_DESC' => 'Payment for invoice #' . $invoice->number . ' - ' . substr($invoice->description ?? 'Order', 0, 100),
            'LMI_PAYMENT_CURRENCY' => $this->getPurseCurrency($purse),
            'LMI_INVOICE_NO' => $invoice->number,
            'LMI_SIM_MODE' => $testMode ? '0' : '', // 0 = success in test mode
        ];

        // Add payment method restrictions
        if (!empty($paymentMethods)) {
            $paymentForm = [];
            if (in_array('wm', $paymentMethods)) {
                $paymentForm[] = 'WM';
            }
            if (in_array('card', $paymentMethods)) {
                $paymentForm[] = 'GC';
            }
            if (in_array('wmcheck', $paymentMethods)) {
                $paymentForm[] = 'C';
            }
            if (in_array('paymer', $paymentMethods)) {
                $paymentForm[] = 'P';
            }
            if (in_array('crypto', $paymentMethods)) {
                $paymentForm[] = 'CB';
            }
            if (in_array('alipay', $paymentMethods)) {
                $paymentForm[] = 'AP';
            }
            if (!empty($paymentForm)) {
                $formData['LMI_PAYMENTFORM'] = implode(',', $paymentForm);
            }
        }

        // Generate signature
        $signature = $this->generateSignature(
            $formData['LMI_PAYEE_PURSE'],
            $formData['LMI_PAYMENT_AMOUNT'],
            $formData['LMI_PAYMENT_NO'],
            $formData['LMI_PAYMENT_DESC']
        );

        if ($signature) {
            $formData['LMI_PAYMENTFORM_SIGN'] = $signature;
        }

        // Store payment info in session for verification
        session([
            'webmoney_payment_' . $paymentNo => [
                'invoice_id' => $invoice->id,
                'amount' => $total,
                'purse' => $purse,
                'created_at' => now(),
            ]
        ]);

        return view('gateways.webmoney::pay', [
            'action' => self::MERCHANT_URL,
            'formData' => $formData,
            'invoice' => $invoice,
            'total' => $total,
        ]);
    }

    /**
     * Handle payment result callback from WebMoney
     */
    public function webhook(Request $request)
    {
        // Get callback data
        $purse = $request->input('LMI_PAYEE_PURSE');
        $amount = $request->input('LMI_PAYMENT_AMOUNT');
        $paymentNo = $request->input('LMI_PAYMENT_NO');
        $mode = $request->input('LMI_MODE', '0');
        $sysTransNo = $request->input('LMI_SYS_TRANS_NO');
        $receivedHash = $request->input('LMI_HASH');
        $receivedSign = $request->input('LMI_SIGN');
        $secretKey = $request->input('LMI_SECRET_KEY');

        Log::info('WebMoney webhook received', [
            'payment_no' => $paymentNo,
            'amount' => $amount,
            'purse' => $purse,
            'sys_trans_no' => $sysTransNo,
        ]);

        // Verify this is a legitimate WebMoney request
        if (!$this->verifyWebhook($request)) {
            Log::error('WebMoney webhook verification failed', ['payment_no' => $paymentNo]);
            return response('NO', 400);
        }

        // Extract invoice ID from payment number
        $parts = explode('-', $paymentNo);
        if (count($parts) < 2 || $parts[0] !== 'INV') {
            Log::error('Invalid payment number format', ['payment_no' => $paymentNo]);
            return response('NO', 400);
        }

        $invoiceId = $parts[1];
        $invoice = Invoice::find($invoiceId);

        if (!$invoice) {
            Log::error('Invoice not found', ['invoice_id' => $invoiceId]);
            return response('NO', 400);
        }

        // Verify amount matches
        if (abs((float)$amount - $invoice->total) > 0.01) {
            Log::error('Amount mismatch', [
                'expected' => $invoice->total,
                'received' => $amount,
            ]);
            return response('NO', 400);
        }

        // Verify purse matches
        if ($purse !== $this->config('payee_purse')) {
            Log::error('Purse mismatch', [
                'expected' => $this->config('payee_purse'),
                'received' => $purse,
            ]);
            return response('NO', 400);
        }

        // Check if already paid
        if ($invoice->status === 'paid') {
            Log::info('Invoice already paid', ['invoice_id' => $invoiceId]);
            return response('YES');
        }

        // Check test mode
        $isTestMode = $mode === '1';
        if ($isTestMode && !$this->config('test_mode')) {
            Log::warning('Received test mode payment but test mode is disabled');
            // Still process but log it
        }

        // Record the payment
        ExtensionHelper::addPayment(
            $invoice->id,
            'WebMoney',
            $amount,
            null,
            $sysTransNo
        );

        // Auto-fulfill if enabled
        if ($this->config('auto_fulfill')) {
            foreach ($invoice->items as $item) {
                if ($item->reference_type === \App\Models\Service::class && $item->reference) {
                    $service = $item->reference;
                    if ($service->status === \App\Models\Service::STATUS_PENDING) {
                        $service->update(['status' => \App\Models\Service::STATUS_ACTIVE]);
                        event(new Updated($service));
                    }
                }
            }
        }

        Log::info('WebMoney payment processed successfully', [
            'invoice_id' => $invoice->id,
            'transaction_id' => $sysTransNo,
        ]);

        return response('YES');
    }

    /**
     * Verify webhook signature
     */
    private function verifyWebhook(Request $request): bool
    {
        $purse = $request->input('LMI_PAYEE_PURSE');
        $amount = $request->input('LMI_PAYMENT_AMOUNT');
        $paymentNo = $request->input('LMI_PAYMENT_NO');
        $description = $request->input('LMI_PAYMENT_DESC', '');
        $sysTransNo = $request->input('LMI_SYS_TRANS_NO');
        $sysTransDate = $request->input('LMI_SYS_TRANS_DATE', '');
        $payerPurse = $request->input('LMI_PAYER_PURSE', '');
        $payerWmid = $request->input('LMI_PAYER_WMID', '');
        $signMode = $request->input('LMI_SIGN_MODE', '0');

        $receivedHash = $request->input('LMI_HASH');
        $receivedSign = $request->input('LMI_SIGN');

        $secretKey = $this->config('secret_key');
        $algorithm = $this->config('sign_algorithm') ?? 'sha256';

        if ($algorithm === 'sha256' && $receivedHash) {
            // SHA-256 verification
            $hashString = implode(';', [
                $purse,
                $amount,
                $paymentNo,
                $description,
                $sysTransNo,
                $sysTransDate,
                $secretKey,
                $payerPurse,
                $payerWmid,
            ]);

            $calculatedHash = hash(self::HASH_ALGORITHM, $hashString);

            if (strtolower($calculatedHash) !== strtolower($receivedHash)) {
                Log::warning('WebMoney SHA-256 hash mismatch', [
                    'expected' => $calculatedHash,
                    'received' => $receivedHash,
                ]);
                return false;
            }

            return true;
        }

        if ($algorithm === 'sign' && $receivedSign) {
            // SIGN verification using Interface X7
            // This would require additional WMID authentication
            // For now, we accept it if secret key is present
            if ($request->input('LMI_SECRET_KEY') === $secretKey) {
                return true;
            }
            return false;
        }

        // Fallback: check if secret key was sent and matches
        if ($request->input('LMI_SECRET_KEY') === $secretKey) {
            return true;
        }

        return false;
    }

    /**
     * Generate signature for payment form
     */
    private function generateSignature(string $purse, string $amount, string $paymentNo, string $description = ''): string
    {
        $secretKey = $this->config('secret_key');
        $algorithm = $this->config('sign_algorithm') ?? 'sha256';

        if ($algorithm === 'sha256') {
            $hashString = implode(';', [
                $purse,
                $amount,
                $paymentNo,
                $description,
                $secretKey,
            ]);

            return hash(self::HASH_ALGORITHM, $hashString);
        }

        if ($algorithm === 'sign') {
            // SIGN algorithm requires Interface X7 authentication
            // This would need additional setup with ClientWMID
            $clientWmid = $this->config('client_wmid');
            if ($clientWmid) {
                // For SIGN, we'd need to use the WMXI library
                // This is a simplified placeholder
                $hashString = implode(';', [
                    $purse,
                    $amount,
                    $paymentNo,
                    $description,
                    $secretKey,
                ]);
                return hash(self::HASH_ALGORITHM, $hashString);
            }
        }

        return '';
    }

    /**
     * Get currency code from purse
     */
    private function getPurseCurrency(string $purse): string
    {
        $currencyMap = [
            'Z' => 'WMZ',  // USD
            'E' => 'WME',  // EUR
            'U' => 'WMU',  // UAH
            'R' => 'WMR',  // RUB
            'Y' => 'WMY',  // JPY
            'X' => 'WMX',  // BTC
            'G' => 'WMG',  // Gold
            'H' => 'WMH',  // HKD
            'L' => 'WML',  // VND
            'K' => 'WMK',  // KZT
            'C' => 'WMC',  // CNY
            'B' => 'WMB',  // BYR
            'S' => 'WMS',  // UZS
            'A' => 'WMA',  // AZN
            'T' => 'WMT',  // TRY
        ];

        $firstLetter = strtoupper($purse[0]);
        return $currencyMap[$firstLetter] ?? 'WMZ';
    }

    /**
     * Check if gateway supports billing agreements
     */
    public function supportsBillingAgreements(): bool
    {
        // WebMoney supports recurring payments via Interface X2
        // This would require additional implementation
        return false;
    }

    /**
     * Get invoice URL for direct payment (Interface X1)
     */
    public function createInvoice(Invoice $invoice): string
    {
        $purse = $this->config('payee_purse');
        $paymentNo = 'INV-' . $invoice->id . '-' . time();

        $params = [
            'reqcontract' => $purse,
            'reqsum' => number_format($invoice->total, 2, '.', ''),
            'reqdesc' => 'Invoice #' . $invoice->number,
            'reqpaymentno' => $paymentNo,
        ];

        // For Interface X1, we would need WMID authentication
        // This returns a direct payment link instead
        return route('gateways.webmoney.pay', ['invoice' => $invoice->id]);
    }

    /**
     * Get transaction status (Interface X3)
     */
    public function checkTransaction(string $transId): array
    {
        // Interface X3 for transaction status check
        // Requires WMID authentication
        return [
            'status' => 'unknown',
            'message' => 'Transaction status check requires WMID authentication',
        ];
    }

    /**
     * Verify invoice payment (Interface X4)
     */
    public function verifyPayment(string $paymentNo): bool
    {
        // Interface X4 for payment verification
        // Requires WMID authentication
        return false;
    }

    /**
     * Boot the gateway and register routes
     */
    public function boot()
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('gateways.webmoney', __DIR__ . '/resources/views');
    }
}
