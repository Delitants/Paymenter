# ISPConfig Extension for Paymenter

Full integration with **ISPConfig 3** hosting control panel. Supports web domains, mail, databases, DNS, FTP users, cron jobs, and more.

## Features

- **Web Domains** - Create and manage virtual hosts with PHP, SSL, and Let's Encrypt
- **Subdomains** - Manage subdomains under existing domains
- **Mail Domains** - Email domain management
- **Mail Users** - Create mailboxes with quotas
- **MySQL Databases** - Database and user provisioning
- **FTP Users** - FTP account creation with directory access
- **Cron Jobs** - Scheduled task management
- **DNS Zones** - DNS zone and record management
- **Auto-provisioning** - Automatic client and resource creation

## Requirements

- **Paymenter** (latest version)
- **ISPConfig 3** server
- PHP SOAP extension
- PHP 8.2+

## Installation

1. Copy the `ISPConfig` folder to `/var/www/paymenter/extensions/Servers/`
2. In Paymenter admin panel, navigate to **Settings → Servers**
3. Click **Add Server** and select **ISPConfig**
4. Configure your ISPConfig credentials

## Configuration

### Server Settings

| Setting | Description | Example |
|---------|-------------|---------|
| SOAP API URL | ISPConfig Remote API endpoint | `https://server:8080/remote/index.php` |
| API Username | ISPConfig admin/reseller username | `admin` |
| API Password | ISPConfig user password | `your_password` |
| Verify SSL | Verify SSL certificate | `false` (for self-signed) |
| Default Server ID | Default server for provisioning | `1` (auto-detect if empty) |

### Getting ISPConfig API Access

1. **Log in** to ISPConfig control panel
2. Go to **System → Server Settings → Services**
3. Ensure the **Remote API** service is enabled
4. The default API URL is: `https://your-server:8080/remote/index.php`

**Important:** The ISPConfig remote API uses SOAP. Make sure:
- Port 8080 is accessible from your Paymenter server
- The API user has sufficient permissions (admin or reseller level)
- PHP SOAP extension is installed (`php-soap` package)

## Product Configuration

When creating a product with ISPConfig, configure:

| Setting | Description |
|---------|-------------|
| Resource Type | Type of resource to provision |
| PHP Version | Default PHP version for web domains |
| Enable SSL | Auto-enable SSL/Let's Encrypt |
| Disk Quota (MB) | Storage limit (0 = unlimited) |
| Traffic Quota (MB) | Monthly bandwidth limit (0 = unlimited) |

### Resource Types

| Type | Description |
|------|-------------|
| Web Domain | Full virtual host with domain |
| Subdomain | Subdomain under existing domain |
| Mail Domain | Email domain |
| Mailbox | Email mailbox account |
| MySQL Database | Database with user |
| FTP User | FTP account |
| Cron Job | Scheduled command |
| DNS Zone | DNS zone management |

## Supported ISPConfig Functions

The extension uses the ISPConfig Remote API (SOAP-based):

### Session Management
- `login()` / `logout()`
- `get_function_list()`

### Client Management
- `client_get_by_username()`
- `client_add()`

### Web Management
- `sites_web_domain_add/update/delete()`
- `sites_web_subdomain_add/update/delete()`
- `sites_web_folder_add/update/delete()`

### Mail Management
- `mail_domain_add/update/delete()`
- `mail_user_add/update/delete()`
- `mail_alias_add/update/delete()`
- `mail_forward_add/update/delete()`

### Database Management
- `sites_database_add/update/delete()`

### FTP Management
- `sites_ftp_user_add/update/delete()`

### Cron Management
- `sites_cron_add/update/delete()`

### DNS Management
- `dns_zone_add/update/delete()`
- `dns_a_add/update/delete()`
- `dns_mx_add/update/delete()`
- `dns_txt_add/update/delete()`

## API Documentation

For complete API reference, see:
- [Official ISPConfig Remote API Docs](https://docs.ispconfig.org/?p=15)
- [HowToForge API Guide](https://howtoforge.com/how-to-create-remote-api-scripts-for-ispconfig-3)
- [API Source Code](https://git.ispconfig.org/ispconfig/ispconfig3/-/tree/master/remoting_client/API-docs)

## Usage

### Create a Web Domain Product

1. Create product with ISPConfig as server
2. Set resource type to **Web Domain**
3. Enable SSL for automatic Let's Encrypt
4. Customer orders with domain name
5. Domain is automatically provisioned

### Create a Database Product

1. Create product with ISPConfig as server
2. Set resource type to **MySQL Database**
3. Customer receives database credentials
4. Database is automatically created

### Manage Existing Resources

From the service details page:
- View current status and metrics
- Change PHP version
- Toggle SSL on/off
- Request Let's Encrypt certificate
- Suspend/unsuspend service

## Troubleshooting

### SOAP Connection Failed

1. Verify ISPConfig API is enabled on port 8080
2. Check firewall allows outbound HTTPS from Paymenter
3. Ensure PHP SOAP extension is installed:
   ```bash
   php -m | grep soap
   ```
4. For self-signed certs, disable **Verify SSL**

### Permission Denied

1. Ensure API user has admin or reseller privileges
2. Check client limits in ISPConfig
3. Verify server has available resources

### SSL Certificate Not Issued

1. Ensure domain DNS points to server IP
2. Check port 80/443 are accessible
3. Wait for Let's Encrypt queue processing

## ISPConfig File Locations

Key ISPConfig paths for reference:

| Path | Purpose |
|------|---------|
| `/usr/local/ispconfig/interface/lib/classes/remoting.inc.php` | API class |
| `/var/www/clients/client{client_id}/web{server_id}/web/` | Web root |
| `/var/vmail/` | Mail storage |
| `/etc/mysql/` | Database config |

## Testing

1. Use a test ISPConfig installation first
2. Create a reseller account for testing
3. Test each resource type individually
4. Verify suspend/unsuspend works correctly

## License

MIT License

## Support

For issues and feature requests, please open an issue on the Paymenter repository.
