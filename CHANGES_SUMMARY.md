# Paymenter Changes Summary

## Date: 2026-05-28

### Overview
This session focused on fixing critical issues with IP Pool management, extension handling, and system stability.

---

## Key Changes

### 1. IP Pool Management Redesign (CRITICAL FIX)

**Problem:** The IP Pool edit page was loading all IP addresses at once using a Repeater component, causing memory exhaustion (128MB limit) and 500 errors when managing pools with hundreds of IPs.

**Solution:**
- Replaced the Repeater component with a Filament RelationManager
- IPs now display in a compact, paginated table (25 per page default)
- Added hostname lookup from settings table for Proxmox VMs
- Added status filtering (Free/Assigned)

**Files Modified:**
- `app/Admin/Resources/IpPoolResource.php` - Removed Repeater, added RelationManager
- `app/Admin/Resources/IpPoolResource/Pages/EditIpPool.php` - Added RelationManager support
- `app/Admin/Resources/IpPoolResource/RelationManagers/IpAddressesRelationManager.php` - NEW

**Table Columns:**
- IP Address (sortable, copyable)
- Hostname (auto-detected from Proxmox settings)
- Assigned To (user name/email or "Free")
- Status badge (Free=green, Assigned=yellow)
- Added date

---

### 2. PHP Memory Limit Increase

**Problem:** 128MB memory limit was insufficient for loading large IP pools.

**Solution:**
- Increased PHP memory limit from 128MB to 512MB
- File: `/etc/php/8.3/fpm/php.ini`

---

### 3. ExtensionHelper Null Safety Fix

**Problem:** `array_merge()` error when gateway/server settings were null.

**Solution:**
- Added null-safe operator for settings pluck operation
- File: `app/Helpers/ExtensionHelper.php:90`

```php
$dbConfig = $record ? ($record->settings?->pluck('value', 'key')->toArray() ?? []) : [];
```

---

### 4. Extension System Improvements

**Files Modified:**
- `app/Admin/Pages/Extension.php` - Shows ALL extensions, hides install button for installed
- `app/Admin/Resources/ExtensionResource.php` - Removed filters, shows all extension types
- `app/Classes/FilamentInput.php` - Added support for multiselect and boolean types
- `app/Classes/Extension/Extension.php` - Extension base class updates

**New Extensions Created:**
- `extensions/Others/Cloudflare/` - Cloudflare IP whitelisting integration
- `extensions/Others/IPPool/` - IP Pool management wrapper
- `extensions/Servers/ResellerClub/` - ResellerClub domain registration
- `extensions/Servers/ISPConfig/` - ISPConfig server integration
- `extensions/Gateways/WebMoney/` - WebMoney payment gateway

---

### 5. IP Pools Database Schema

**New Migrations:**
- `database/migrations/2026_05_25_145217_create_ip_pools_table.php`
- `database/migrations/2026_05_25_145218_create_ip_addresses_table.php`

**New Models:**
- `app/Models/IpPool.php`
- `app/Models/IpAddress.php`

**IP Address Features:**
- MorphTo relationship for assignment to any model (User, Service)
- is_assigned flag
- assigned_to_type/assigned_to_id for polymorphic assignment

---

### 6. Proxmox Server Integration

**Files Modified:**
- `app/Admin/Resources/ServerResource.php`
- `app/Admin/Resources/ServerResource/Pages/CreateServer.php`
- `app/Admin/Resources/ServerResource/Pages/EditServer.php`

**New Features:**
- CEPH Storage Pool dropdown with dynamic loading
- Allowed Nodes selection with persistence
- Firewall rules for IP restriction (auto-created on VM creation)
- IP change handling with firewall rule updates
- Firewall cleanup on VM termination

---

### 7. Cloudflare Integration

**New Files:**
- `app/Services/CloudflareService.php`
- `app/Http/Middleware/Cloudflare/CloudflareMiddleware.php`
- `app/Console/Commands/CloudflareRefreshRanges.php`
- `app/Admin/Pages/Cloudflare/CloudflareSettings.php`

**Features:**
- Restore real client IPs behind Cloudflare proxy
- Cloudflare IP range whitelisting
- Automatic IP range refresh

---

### 8. ResellerClub Integration

**New Files:**
- `app/Services/ResellerClubService.php` (via extension)
- `app/Console/Commands/ResellerClubSyncPrices.php`
- `app/Admin/Pages/ResellerClub/PriceSyncSettings.php`

**Features:**
- Domain registration/reseller integration
- Automatic price synchronization

---

### 9. Other Changes

**Files Modified:**
- `app/Classes/Settings.php` - Settings management improvements
- `bootstrap/app.php` - Laravel bootstrap updates
- `resources/views/admin/pages/extension.blade.php` - Simplified extension list view
- `app/Livewire/Components/LocaleSwitch.php` - Locale switching fixes

**New Services:**
- `app/Services/WebServerConfigService.php` - Web server configuration management

---

## Scripts

**New Scripts:**
- `scripts/sync-ip-manager.php` - Sync IPManager CSV data to Paymenter IP Pools
  - Parses Windows line endings correctly
  - FREE keyword detection for unoccupied IPs
  - Creates IP pools by owner/permission groups

---

## Configuration Changes

### PHP Configuration
```
memory_limit = 512M (was 128M)
```

### IP Pool Defaults
- Pagination: 25 IPs per page (options: 15, 25, 50, 100)
- Status filter: Free/Assigned
- Default sort: IP address ascending

---

## Testing Notes

### IP Pool Page
- URL: `/admin/ip-pools/1/edit`
- Should load quickly even with 500+ IPs
- Hostname lookup works for Proxmox VMs with hostname setting
- Filter by status functional

### Extension Installation
- All extensions now appear in "Ready to Install" tab
- Install button hidden for already-installed extensions
- Extensions auto-register via boot() method

### Proxmox Integration
- CEPH storage pools populate after API credentials entered
- Allowed Nodes selection persists after page refresh
- Firewall rules created on VM creation
- Firewall rules updated on IP change
- Firewall rules deleted on VM termination

---

## Known Issues Resolved

| Issue | Status |
|-------|--------|
| IP Pool edit page 500 error (memory exhaustion) | FIXED |
| CEPH Storage Pool dropdown empty | FIXED |
| Allowed Nodes not persisting | FIXED |
| WebMoney 500 error on install | FIXED |
| "Unknown input type: multiselect" | FIXED |
| "Unknown input type: boolean" | FIXED |
| Extensions not showing in list | FIXED |
| Extensions showing as Disabled | FIXED |

---

## Git Information

**Branch:** master
**Commit:** Pending
**Changes:** 50+ files modified/added

---

## Next Steps

1. Verify Proxmox firewall rules are created correctly on VM creation
2. Test IP change handling from Paymenter admin
3. Verify firewall rule cleanup on VM deletion
4. Monitor IP Pool page performance with full dataset
