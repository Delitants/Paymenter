# WebMoney Gateway for Paymenter

This extension provides full integration with **WebMoney Transfer** for accepting payments via the Web Merchant Interface.

## Features

- **Multiple Payment Methods** - WM-purses, bank cards, WebMoney Check, Paymer checks, cryptocurrencies, Alipay
- **SHA-256 Signature Verification** - Secure payment verification
- **Test Mode** - Develop and test without real payments
- **Auto-fulfillment** - Automatically activate services after payment
- **Multiple Currencies** - Support for WMZ, WME, WMU, WMR, WMX, WMG, WMH, etc.
- **Webhook Support** - Automatic payment confirmation via Result URL

## Requirements

- **Paymenter** (latest version)
- **WebMoney Account** with merchant privileges
- **WebMoney Purse** (e.g., Z123456789012 for WMZ)
- PHP 8.2+

## Installation

1. Copy the `WebMoney` folder to `/var/www/paymenter/extensions/Gateways/`
2. In Paymenter admin panel, navigate to **Settings → Gateways**
3. Find "WebMoney Gateway" and click **Install**
4. Configure your WebMoney settings

## Configuration

### Gateway Settings

| Setting | Description | Example |
|---------|-------------|---------|
| Merchant Purse | Your WebMoney purse | `Z123456789012` |
| Secret Key | Secret key from merchant settings | (50 characters) |
| Signature Algorithm | Verification method | `SHA-256` |
| Client WMID | Your WMID (for SIGN algorithm) | `123456789012` |
| Test Mode | Enable test payments | `true/false` |
| Payment Methods | Accepted payment types | `WM, Card, Crypto` |
| Auto-fulfill | Activate services automatically | `true/false` |

### Setting Up WebMoney Merchant

1. **Create a WebMoney Account** at [webmoney.com](https://www.webmoney.com)

2. **Create a Purse** for your desired currency:
   - **WMZ** (Z-purse) - USD
   - **WME** (E-purse) - EUR
   - **WMU** (U-purse) - UAH
   - **WMR** (R-purse) - RUB
   - **WMX** (X-purse) - Bitcoin equivalent
   - **WMG** (G-purse) - Gold
   - And more...

3. **Register as Merchant**:
   - Go to [merchant.wmtransfer.com](https://merchant.wmtransfer.com)
   - Log in with your WebMoney credentials
   - Register your purse as a merchant

4. **Configure Merchant Settings**:
   - Set **Secret Key** (50 characters, case-sensitive)
   - Set **Result URL**: `https://your-domain.com/gateways/webmoney/webhook`
   - Enable **SHA-256** signature verification
   - Configure test mode for development

5. **Get Your Credentials**:
   - Copy your **Purse Number** (e.g., Z123456789012)
   - Copy your **Secret Key** (50 characters)
   - Note your **WMID** (12 digits)

## Payment Flow

1. Customer clicks "Pay" on invoice
2. Paymenter generates payment form with signature
3. Customer is redirected to WebMoney
4. Customer completes payment via WebMoney interface
5. WebMoney sends callback to Result URL
6. Paymenter verifies signature and marks invoice as paid
7. Services are automatically activated (if enabled)

## Webhook Configuration

**Result URL**: `https://your-domain.com/gateways/webmoney/webhook`

The webhook will:
- Verify the signature/hash
- Validate amount and purse
- Record the payment
- Auto-fulfill orders (if enabled)
- Return "YES" to confirm receipt

## Test Mode

Enable test mode to process payments without real money:

1. In Paymenter: Enable "Test Mode" in gateway settings
2. In WebMoney Merchant: Enable "Test/Work mode"
3. Test payments will be marked with `LMI_MODE=1`

## Supported Payment Methods

| Method | Code | Description |
|--------|------|-------------|
| WM Purses | WM | All WebMoney purse types |
| Bank Cards | GC | Visa, Mastercard, etc. |
| WebMoney Check | C | Digital checks |
| Paymer Checks | P | Prepaid checks |
| Cryptocurrencies | CB | BTC, ETH, USDT, etc. |
| Alipay P2P | AP | Alipay transfers |

## Security

### Signature Verification

The gateway uses **SHA-256** hash verification:

```
Hash = SHA256(purse;amount;paymentNo;description;secretKey;payerPurse;payerWMID)
```

### Best Practices

1. **Keep Secret Key secure** - Never share or commit to version control
2. **Use HTTPS** - Always use SSL for production
3. **Verify webhooks** - All callbacks are signature-verified
4. **Monitor test mode** - Disable for production

## Troubleshooting

### Payment not confirmed

1. Check that Result URL is correctly set in WebMoney Merchant
2. Verify webhook returns exactly "YES"
3. Check server logs for errors
4. Ensure SSL certificate is valid

### Signature verification fails

1. Verify Secret Key matches exactly (case-sensitive)
2. Check SHA-256 algorithm is selected
3. Ensure no extra spaces in key

### Purse format error

- Must be: Letter + 12 digits (e.g., `Z123456789012`)
- First letter indicates currency type

## API Reference

### WebMoney Interfaces Used

- **Web Merchant Interface** - Primary payment processing
- **Interface X1** - Invoice generation
- **Interface X2** - Fund transfers (recurring payments)
- **Interface X3** - Transaction status
- **Interface X4** - Payment verification

### Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/gateways/webmoney/pay/{invoice}` | GET | Payment page |
| `/gateways/webmoney/webhook` | POST | Payment callback |

## Links

- [WebMoney Developer API](https://www.webmoney.com/eng/developers/api.shtml)
- [Web Merchant Interface Documentation](https://en.webmoney.wiki/projects/webmoney/wiki/Web_Merchant_Interface)
- [Interface X20](https://en.webmoney.wiki/projects/webmoney/wiki/Interface_X20)
- [WebMoney IP Addresses](https://en.webmoney.wiki/projects/webmoney/wiki/WM_IP_addresses)

## License

MIT License

## Support

For issues and feature requests, please open an issue on the Paymenter repository.
