# ResellerClub Extension for Paymenter

Full integration with **ResellerClub** for domain registration, transfer, renewal, and management. Supports 1000+ TLDs including .com, .net, .org, .io, .co, and many country-code domains.

## Features

- **Domain Registration** - Register new domains instantly
- **Domain Transfer** - Transfer domains with EPP code
- **Domain Renewal** - Renew domains for 1-10 years
- **DNS Management** - Update nameservers
- **Transfer Lock** - Enable/disable domain transfer lock
- **Contact Management** - Auto-create customer contacts
- **Test Environment** - Sandbox mode for development
- **Auto-provisioning** - Register domains automatically on order

## Requirements

- **Paymenter** (latest version)
- **ResellerClub Account** - [Sign up here](https://www.resellerclub.com)
- PHP 8.2+
- cURL extension

## Installation

1. Copy the `ResellerClub` folder to `/var/www/paymenter/extensions/Servers/`
2. In Paymenter admin panel, navigate to **Settings → Servers**
3. Click **Add Server** and select **ResellerClub**
4. Configure your ResellerClub credentials

## Configuration

### Server Settings

| Setting | Description | Example |
|---------|-------------|---------|
| API Key | Your ResellerClub API Key | `xxxx-xxxx-xxxx-xxxx` |
| Reseller ID | Your Reseller ID | `123456` |
| Environment | Test or Live | `Test` |
| Auto-configure DNS | Set default nameservers | `true/false` |
| Default NS1 | Primary nameserver | `ns1.yourserver.com` |
| Default NS2 | Secondary nameserver | `ns2.yourserver.com` |

### Getting API Credentials

1. **Log in** to [ResellerClub](https://my.resellerclub.com)
2. Go to **Settings → API**
3. Generate or copy your **API Key**
4. Note your **Reseller ID** (shown in dashboard)
5. For production, switch environment to **Live**

### Test Mode

By default, the extension uses the **Test (Sandbox)** environment:
- Test API URL: `https://test.httpapi.com/api`
- Live API URL: `https://httpapi.com/api`

**Important:** Domains registered in test mode are NOT real domains.

## Product Configuration

When creating a domain product, configure:

| Setting | Description |
|---------|-------------|
| TLD | Top-level domain (e.g., `.com`) |
| Registration Period | Default years (1-10) |
| Transfer Lock | Enable by default |
| Privacy Protection | WHOIS privacy (if available) |
| Auto Renew | Automatic renewal |
| DNS Management | Enable DNS management |
| Email Forwarding | Enable email forwarding |
| ID Protection | Enable ID protection |

## Supported TLDs

The extension supports all ResellerClub TLDs including:

### Generic TLDs
- `.com`, `.net`, `.org`, `.info`, `.biz`
- `.io`, `.co`, `.me`, `.tv`, `.cc`
- `.xyz`, `.online`, `.site`, `.store`, `.tech`
- And 500+ more...

### Country Code TLDs
- `.uk`, `.us`, `.ca`, `.au`, `.de`
- `.in`, `.cn`, `.jp`, `.ru`, `.br`
- And 300+ more...

### Privacy Protection Availability

Privacy protection is **NOT** available for:
- `.AU`, `.CA`, `.CN`, `.DE`, `.ES`, `.EU`
- `.FR`, `.IN`, `.NL`, `.NZ`, `.PRO`
- `.RU`, `.SX`, `.TEL`, `.UK`, `.US`

## API Endpoints Used

| Action | Endpoint |
|--------|----------|
| Check Availability | `/domains/check.json` |
| Register Domain | `/domains/register.json` |
| Transfer Domain | `/domains/transfer.json` |
| Renew Domain | `/domains/renew.json` |
| Get Details | `/domains/details.json` |
| Modify Nameservers | `/domains/modify-ns.json` |
| Enable Transfer Lock | `/domains/enable-transfer-lock.json` |
| Disable Transfer Lock | `/domains/disable-transfer-lock.json` |
| Add Contact | `/contacts/add.json` |

## Usage

### Register a Domain

1. Create a product with ResellerClub as the server
2. Set TLD (e.g., `.com`)
3. Customer orders the product
4. Domain is automatically registered

### Transfer a Domain

1. Customer provides EPP/Auth code
2. Extension initiates transfer
3. Transfer completes in 5-7 days

### Renew a Domain

1. Admin or customer clicks "Renew"
2. Domain renewed for specified years
3. Expiry date updated automatically

### Update Nameservers

1. Go to service details
2. Click "Update Nameservers"
3. Enter new nameserver addresses

## Contact Management

The extension automatically:
- Creates customer contacts on first order
- Reuses existing contacts for same email
- Assigns all 4 contact types (Registrant, Admin, Tech, Billing)

## Webhooks

ResellerClub can send webhooks for:
- Domain transfer status changes
- Renewal reminders
- Expiration notifications

Configure webhooks in ResellerClub dashboard to point to:
`https://your-domain.com/api/resellerclub/webhook`

## Troubleshooting

### API Connection Failed

1. Verify API Key is correct
2. Check Reseller ID matches
3. Ensure environment (test/live) is correct
4. Check firewall allows outbound HTTPS

### Domain Registration Failed

1. Verify domain is available (check first)
2. Ensure contact information is complete
3. Check nameservers are valid
4. Verify payment method is configured

### Transfer Failed

1. Verify EPP/Auth code is correct
2. Ensure domain is unlocked at current registrar
3. Check domain is not within 60-day lock period
4. Verify contact email is valid

### TLD-Specific Requirements

Some TLDs have special requirements:

| TLD | Requirement |
|-----|-------------|
| `.AU` | Eligibility ID (ACN/ABN) required |
| `.CN` | MIIT ICP Number if hosted in China |
| `.EU` | Pass -1 for admin contact |
| `.UK` | Opt-out flag for WHOIS |
| `.DE` | German presence required |

## Testing

1. Use **Test Environment** for development
2. Test domain registration with `test-domain-12345.com`
3. Verify webhooks in test mode first
4. Switch to Live only when ready

## Automatic Price Sync

The extension includes automatic price synchronization from ResellerClub:

### Setup

1. Go to **Admin → ResellerClub → Price Sync**
2. Configure settings:
   - Enable automatic sync
   - Set markup percentage
   - Select TLDs to sync (or sync all)
3. Add cron job for scheduled sync:

```bash
# Weekly sync (Monday 3 AM)
0 3 * * 1 cd /var/www/paymenter && php artisan resellerclub:sync-prices --markup=20

# Or run manually
php artisan resellerclub:sync-prices --markup=20 --dry-run
```

### Command Options

| Option | Description |
|--------|-------------|
| `--tlds=.com,.net,.org` | Sync specific TLDs only |
| `--markup=20` | Apply 20% markup |
| `--dry-run` | Preview changes without saving |

### How It Works

1. Fetches prices from ResellerClub API
2. Applies your markup percentage
3. Creates new products for new TLDs
4. Updates prices for existing products
5. **Skips products in use** (only updates prices, doesn't modify)

## Links

- [ResellerClub API Documentation](https://manage.resellerclub.com/kb/answer/744)
- [Domain Registration API](https://manage.resellerclub.com/kb/answer/752)
- [Domain Renewal API](https://manage.resellerclub.com/kb/answer/746)
- [HTTP API Guide](https://manage.resellerclub.com/kb/answer/744)

## License

MIT License

## Support

For issues and feature requests, please open an issue on the Paymenter repository.
