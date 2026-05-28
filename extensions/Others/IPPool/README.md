# IP Pools Extension for Paymenter

[![Paymenter Version](https://img.shields.io/badge/Paymenter-1.0-blue)](https://paymenter.org)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

Manage IP address pools for server assignments in Paymenter billing platform.

## Features

- **IP Pool Management**: Create and manage pools of IP addresses
- **Automatic Assignment**: Automatically assign IPs from pools to services
- **IPv4 & IPv6 Support**: Full support for both IPv4 and IPv6 addresses
- **CIDR Notation**: Support for CIDR notation for easy range management
- **Integration**: Seamlessly integrates with Paymenter server management

## Requirements

- Paymenter v1.0 or higher
- PHP 8.1 or higher
- Server with IP pool management capability (Proxmox, Virtualizor, etc.)

## Installation

### Via Paymenter Admin Panel

1. Log in to your Paymenter admin panel
2. Navigate to **Extensions** → **Available Extensions**
3. Find **IP Pools** in the list
4. Click **Install**
5. The extension will auto-register and appear in **Installed Extensions**

### Manual Installation

1. Clone this repository to your Paymenter installation:
   ```bash
   cd /var/www/paymenter/extensions/Others
   git clone https://github.com/Delitants/paymenter-ippool-extension.git IPPool
   ```

2. The extension will auto-register on next application boot.

3. Verify installation in admin panel under **Extensions** → **Installed**

## Usage

After installation:

1. Navigate to **Extensions** → **IP Pools** in the admin panel
2. Create new IP pools by specifying:
   - Pool name
   - IP range (single IP or CIDR notation)
   - Associated server
3. Assign pools to products/services as needed

### Configuration

This extension requires no manual configuration. All settings are managed through the Paymenter admin panel.

## API Reference

### Extension Methods

| Method | Description |
|--------|-------------|
| `getConfig()` | Returns extension configuration fields (info only) |
| `testConfig()` | Tests extension configuration (always returns true) |
| `installed()` | Auto-registers extension in database |
| `uninstalled()` | Removes extension from database |
| `boot()` | Auto-registers on application boot |

## File Structure

```
IPPool/
├── README.md           # This file
├── IPPool.php          # Main extension class
└── LICENSE             # MIT License
```

## Troubleshooting

### Extension not appearing in Installed list

1. Clear application cache:
   ```bash
   php artisan cache:clear
   php artisan view:clear
   ```

2. Verify extension is registered:
   ```bash
   php artisan tinker
   >>> \App\Models\Extension::where('extension', 'IPPool')->first()
   ```

### IP Pools menu not showing

Ensure the extension is enabled:
```sql
SELECT * FROM extensions WHERE extension = 'IPPool';
```

The `enabled` column should be `1`.

## Version History

### 1.0.0 (2026-05-27)
- Initial release
- IP pool management interface
- Auto-registration support
- IPv4 and IPv6 support

## Support

- **Documentation**: [Paymenter Docs](https://paymenter.org/docs)
- **Issues**: [GitHub Issues](https://github.com/Delitants/paymenter-ippool-extension/issues)
- **Discussions**: [GitHub Discussions](https://github.com/Delitants/paymenter-ippool-extension/discussions)

## License

This extension is open-source software licensed under the [MIT License](LICENSE).

## Credits

- **Author**: Paymenter Community
- **Extension**: IP Pools Management
- **Platform**: [Paymenter](https://paymenter.org)

---

Made with ❤️ for the Paymenter community
