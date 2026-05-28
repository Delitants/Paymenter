<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebMoney Payment</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .payment-container {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .payment-header {
            background: linear-gradient(135deg, #0066cc 0%, #0052a3 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .payment-header h1 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        .payment-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .payment-body {
            padding: 30px;
        }
        .invoice-details {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .invoice-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .invoice-row:last-child {
            border-bottom: none;
        }
        .invoice-row label {
            color: #6c757d;
            font-size: 14px;
        }
        .invoice-row span {
            color: #212529;
            font-weight: 600;
        }
        .total-row {
            border-top: 2px solid #0066cc !important;
            margin-top: 10px;
            padding-top: 15px !important;
        }
        .total-row label,
        .total-row span {
            font-size: 18px;
            color: #0066cc;
        }
        .webmoney-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 25px;
        }
        .webmoney-info h3 {
            color: #856404;
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .webmoney-info ul {
            color: #856404;
            font-size: 13px;
            padding-left: 20px;
        }
        .webmoney-info li {
            margin-bottom: 5px;
        }
        .payment-methods {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 25px;
        }
        .payment-badge {
            background: #e9ecef;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            color: #495057;
            font-weight: 500;
        }
        .btn-pay {
            width: 100%;
            background: linear-gradient(135deg, #0066cc 0%, #0052a3 100%);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 102, 204, 0.4);
        }
        .btn-cancel {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: #6c757d;
            text-decoration: none;
            font-size: 14px;
        }
        .btn-cancel:hover {
            color: #495057;
        }
        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
            font-size: 12px;
        }
        .security-badge svg {
            width: 16px;
            height: 16px;
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="payment-header">
            <h1>💳 WebMoney Payment</h1>
            <p>Secure payment via WebMoney Transfer</p>
        </div>

        <div class="payment-body">
            <div class="invoice-details">
                <div class="invoice-row">
                    <label>Invoice Number</label>
                    <span>#{{ $invoice->number }}</span>
                </div>
                <div class="invoice-row">
                    <label>Description</label>
                    <span>{{ Str::limit($invoice->description ?? 'Invoice Payment', 40) }}</span>
                </div>
                <div class="invoice-row total-row">
                    <label>Total Amount</label>
                    <span>{{ number_format($total, 2) }} {{ $invoice->currency_code }}</span>
                </div>
            </div>

            <div class="webmoney-info">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    Payment Information
                </h3>
                <ul>
                    <li>You will be redirected to WebMoney Transfer to complete your payment</li>
                    <li>Supported: WM-purses, Bank Cards, WebMoney Check, Cryptocurrencies</li>
                    <li>Payment is processed securely by WebMoney</li>
                </ul>
            </div>

            <form method="POST" action="{{ $action }}" id="webmoneyForm" accept-charset="utf-8">
                @foreach($formData as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach

                <button type="submit" class="btn-pay">
                    Pay with WebMoney →
                </button>
            </form>

            <a href="{{ route('account.invoices') }}" class="btn-cancel">
                ← Back to Invoices
            </a>

            <div class="security-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Secured by WebMoney Transfer • SHA-256 Encrypted
            </div>
        </div>
    </div>

    <script>
        // Auto-submit the form after a short delay for better UX
        document.addEventListener('DOMContentLoaded', function() {
            // Optional: Auto-redirect after 2 seconds
            // setTimeout(() => {
            //     document.getElementById('webmoneyForm').submit();
            // }, 2000);
        });
    </script>
</body>
</html>
