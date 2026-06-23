# Proxmox VM Provisioning Fix

## Problem
Paid products were creating services but VMs weren't being created on Proxmox. The service would show as "active" but no `proxmox_vm_id` was assigned.

## Root Causes Identified

1. **No Queue Worker Running**
   - Queue driver configured as Redis but no worker was processing jobs
   - `CreateJob` was dispatched but never executed

2. **Invalid IP Configuration Format**
   - Proxmox cloud-init requires CIDR notation (e.g., `ip=31.3.231.239/27,gw=31.3.231.225`)
   - Code was sending just the IP address without subnet mask or gateway
   - Error: `ipconfig0: invalid format - value does not look like a valid ipv4 network configuration`

3. **Hardcoded Cloud-Init Storage**
   - Code referenced `local-lvm:cloudinit/meta/userdata-{$vmId}`
   - User's storage pool is `ceph_pool`, not `local-lvm`
   - Proxmox couldn't create cloud-init files on non-existent storage

4. **Missing service_id in Config**
   - `createQemuVm()` couldn't store credentials because `service_id` wasn't passed
   - `cloud_init_password` and `assigned_ipv4` were never persisted

5. **Undefined Variable Error**
   - Code referenced `$assignedIp` but variable was named `$assignedIpv4`
   - Caused crash after VM creation succeeded

## Fixes Applied

### 0. VM Auto-Start on Order Completion (Latest Fix)

**Problem**: VMs were created but left in "stopped" state after user completes order. User had to manually start them.

**Root Cause**: The `createQemuVm()` and `createLxcContainer()` functions created the VM/container but never called the start API endpoint.

**Fix**: Added auto-start logic at the end of both creation functions:
- Uses form-encoded POST request (required by Proxmox API for state changes)
- Logs success/warning for monitoring
- Doesn't fail the entire operation if start fails (VM is still created)

**Code Location**: 
- `extensions/Servers/Proxmox/Proxmox.php:2158-2174` (QEMU VMs)
- `extensions/Servers/Proxmox/Proxmox.php:1855-1871` (LXC Containers)

```php
// Auto-start VM after successful creation (form-encoded request required by Proxmox API)
$http = Http::withHeaders([
    'Authorization' => 'PVEAPIToken=' . $this->config('api_token_id') . '=' . $this->config('api_token_secret'),
    'Accept' => 'application/json',
])->withoutVerifying()->timeout($this->config('timeout') ?? self::DEFAULT_TIMEOUT);

try {
    $startResponse = $http->asForm()->post($this->config('host') . "/api2/json/nodes/{$node}/qemu/{$vmId}/status/start", []);
    if ($startResponse->successful()) {
        Log::info("Proxmox VM {$vmId} auto-started successfully on node {$node}");
    } else {
        Log::warning("Proxmox VM {$vmId} start returned non-success status: " . $startResponse->status());
    }
} catch (Exception $e) {
    Log::warning("Failed to auto-start Proxmox VM {$vmId}: " . $e->getMessage());
    // Don't fail the entire operation - VM is created, just not started
}
```

### 1. IP Configuration with CIDR Notation
**File**: `extensions/Servers/Proxmox/Proxmox.php`

```php
// Build IP config for Proxmox (requires CIDR notation with subnet mask)
$ipConfig = [];
if ($assignedIpv4) {
    // Get subnet mask from the IP pool of the assigned IP
    $assignedIpModel = \App\Models\IpAddress::where('ip_address', $assignedIpv4)->first();
    if ($assignedIpModel && $assignedIpModel->ipPool && $assignedIpModel->ipPool->subnet_mask) {
        $pool = $assignedIpModel->ipPool;
        // Convert subnet mask to CIDR prefix
        $cidr = substr_count(decbin(ip2long($pool->subnet_mask)), '1');
        $ipConfig[] = "ip={$assignedIpv4}/{$cidr},gw={$pool->gateway}";
    } else {
        $ipConfig[] = "ip={$assignedIpv4}";
    }
}
```

### 2. Dynamic Cloud-Init Storage
**File**: `extensions/Servers/Proxmox/Proxmox.php` (both LXC and QEMU)

```php
// Add Cloud-Init settings
if ($config['cloud_init'] ?? true) {
    $createData['agent'] = 1;
    $createData['ciuser'] = 'paymenter';
    $createData['cipassword'] = Str::random(16);
    // Use configured storage pool for cloud-init metadata (fallback to local-lvm if not set)
    $cloudInitStorage = !empty($config['storage_pool']) ? $config['storage_pool'] : 'local-lvm';
    // cicustom format: user=STORAGE:cloudinit/filename (Proxmox creates the files automatically)
    // Only set cicustom if we have a custom userdata file, otherwise let Proxmox auto-generate
}
```

### 3. Service ID and Cloud-Init Defaults
**File**: `extensions/Servers/Proxmox/Proxmox.php`

```php
public function createServer(Service $service, $settings, $properties)
{
    // Merge settings with properties
    $config = array_merge($settings, $properties);

    // Add service_id to config for use in IP assignment and credential storage
    $config['service_id'] = $service->id;

    // Default cloud_init to true if not set
    if (!isset($config['cloud_init'])) {
        $config['cloud_init'] = true;
    }
    // ...
}
```

### 4. Fixed Variable Name and Storage Logic
**File**: `extensions/Servers/Proxmox/Proxmox.php`

```php
// Create firewall rules to restrict VM to assigned IP only
if ($assignedIpv4) {  // Fixed: was $assignedIp (undefined)
    $this->createIpFirewallRules($node, $vmId, 'qemu', $assignedIpv4, $config['ipv6'] ?? null);
}

// Store IP assignment if specified
$serviceId = $config['service_id'] ?? null;
if ($serviceId) {
    // Store assigned IPv4 for firewall updates and display
    if ($assignedIpv4) {
        $service = Service::find($serviceId);
        $service?->properties()->updateOrCreate(
            ['key' => 'assigned_ipv4'],
            ['value' => $assignedIpv4]
        );
    }
}
```

### 5. Graceful VM Deletion Error Handling
**File**: `extensions/Servers/Proxmox/Proxmox.php`

```php
private function deleteVm(string $node, int $vmId, string $vmType): void
{
    try {
        $this->stopServerByType($node, $vmId, $vmType);
    } catch (Exception $e) {
        // Ignore stop errors, continue with deletion
    }

    $endpoint = $vmType === 'lxc'
        ? "/api2/json/nodes/{$node}/lxc/{$vmId}"
        : "/api2/json/nodes/{$node}/qemu/{$vmId}";

    try {
        $this->request($endpoint, 'DELETE', [], true);
    } catch (Exception $e) {
        // Ignore "does not exist" errors - VM was never created
        if (!str_contains($e->getMessage(), 'does not exist')) {
            throw $e;
        }
    }
}
```

### 6. Enhanced Error Logging
**File**: `extensions/Servers/Proxmox/Proxmox.php`

```php
if (!$response->successful()) {
    $errorData = $response->json();
    Log::error('Proxmox API Error Details', [
        'endpoint' => $endpoint,
        'method' => $method,
        'data' => $data,
        'errors' => $errorData['errors'] ?? null,
        'message' => $errorData['message'] ?? null,
        'status' => $response->status(),
    ]);
    throw new Exception('Proxmox API Error: ' . ($errorData['errors'][0] ?? $errorData['message'] ?? $response->status()));
}
```

### 7. Product Email Template with Credentials
Updated product email template to include VM credentials:

```blade
- Hostname: {{ $service->properties->where('key', 'hostname')->first()?->value ?? 'N/A' }}
- IP Address: {{ $service->properties->where('key', 'assigned_ipv4')->first()?->value ?? 'Auto-assigned' }}
- VM ID: {{ $service->properties->where('key', 'proxmox_vm_id')->first()?->value ?? 'N/A' }}
- Proxmox Node: {{ $service->properties->where('key', 'proxmox_node')->first()?->value ?? 'N/A' }}
- Cloud-init Password: {{ $service->properties->where('key', 'cloud_init_password')->first()?->value ?? 'N/A' }}
```

### 8. Queue Worker Setup

**Temporary**: Run manually
```bash
php artisan queue:work --timeout=120 --sleep=3 --tries=1
```

**Production**: Systemd service created at `/etc/systemd/system/paymenter-queue.service`
```bash
sudo systemctl daemon-reload
sudo systemctl enable paymenter-queue
sudo systemctl start paymenter-queue
sudo systemctl status paymenter-queue
```

**Current Status**: Service is enabled and running since 2026-06-20.

### 9. Cloud-Init Cloud Image Support (Latest)

**Problem**: Cloud-init only configures an existing OS - it doesn't install one. When an installer ISO is attached, the VM boots into the installer wizard requiring manual interaction.

**Root Cause**: Installer ISOs are for manual installation. Cloud-init requires **cloud images** (pre-installed OS in qcow2/img format) to work automatically.

**Solution**: 
1. Admin uploads cloud images to Proxmox storage via **Storage → Content → Upload → Disk image** (or use Storage type "Import")
2. Cloud images are stored in `cephfs:import/<filename>.qcow2`
3. Product `iso_image` setting references the cloud image (e.g., `centos-10`)
4. VM creation uses Proxmox API `import-from` parameter to automatically import the cloud image

**Cloud Image Mapping** (product setting → filename in cephfs:import/):
- `centos-10` → `CentOS-Stream-GenericCloud-10-latest.x86_64.qcow2`
- `centos-9` → `CentOS-Stream-GenericCloud-9-latest.x86_64.qcow2`
- `ubuntu-24.04` → `ubuntu-24.04-cloud.img`
- `ubuntu-22.04` → `ubuntu-22.04-cloud.img`
- `debian-12` → `debian-12-cloud.qcow2`

**Proxmox API `import-from` syntax**:
```
scsi0=<storage>:0,import-from=<source-image>
```

Example: `scsi0=ceph_pool:0,import-from=cephfs:import/CentOS-Stream-GenericCloud-10-latest.x86_64.qcow2`

**VM 6643** (CentOS 10 cloud image):
- Created with `import-from` parameter
- Cloud-init user: `root`
- IP: 37.220.3.111/27, gw=37.220.3.97
- Status: running

## Verification

Service 1 now has all required properties:
```
Status: active
VM ID: 6643
Node: vm2
IP: 37.220.3.111
Password: kR9Puf3dLprYkYxS
```

Queue Status:
```
Pending jobs: 0
Failed jobs: 0
Queue Worker: RUNNING
```

## Files Modified

1. `extensions/Servers/Proxmox/Proxmox.php` - Core provisioning fixes + auto-start VMs on creation + private_bridge "disabled" fix
2. `app/Classes/FilamentInput.php` - Dynamic IP loading (committed separately)
3. `/etc/systemd/system/paymenter-queue.service` - Production queue worker
4. Product email template (database) - Credential notification

### Fix for "disabled" Bridge Value

**Problem**: When `private_bridge` is set to `"disabled"` in product settings, the code was passing it as a literal bridge name to Proxmox, causing VM startup to fail with:
```
bridge 'disabled' does not exist
QEMU exited with code 1
```

**Fix**: Added explicit check for `"disabled"` value in `createQemuVm()`:
```php
// Skip if bridge is set to "disabled" (special value meaning no private network)
if (!empty($config['private_bridge']) && $config['private_bridge'] !== 'disabled') {
    $createData['net1'] = "model={$config['network_model']},bridge={$config['private_bridge']}";
}
```

**VM 6643 Recovery**: Removed invalid `net1` config and started VM:
```bash
curl -X POST "https://vm1.uapeer.eu:8006/api2/json/nodes/vm2/qemu/6643/config" \
  -H "Authorization: PVEAPIToken=..." -d "delete=net1"
curl -X POST "https://vm1.uapeer.eu:8006/api2/json/nodes/vm2/qemu/6643/status/start" \
  -H "Authorization: PVEAPIToken=..."
```

## Testing

To test the complete flow:
1. Create a new product with Proxmox server assigned
2. Place an order (free or paid with invoice payment)
3. Check queue worker processes the `CreateJob`
4. Verify service properties include:
   - `proxmox_vm_id`
   - `proxmox_node`
   - `proxmox_vm_type`
   - `cloud_init_password`
   - `assigned_ipv4`
5. Verify email notification includes credentials

## Troubleshooting

Check queue status:
```bash
php artisan queue:failed
php artisan queue:retry all
php artisan queue:work --stop-when-empty
```

Check logs:
```bash
tail -f storage/logs/laravel-2026-06-21.log | grep -i proxmox
```

Manually dispatch CreateJob:
```bash
php artisan tinker --execute="App\Jobs\Server\CreateJob::dispatch(App\Models\Service::find(1));"
```
