# VPS Configuration Options - Complete Examples

## Overview

Paymenter uses a hierarchical configuration system where:
- **Products** define the base service (e.g., "VPS")
- **Config Options** allow customers to customize resources (CPU, RAM, Disk)
- **Plans** define pricing for each configuration option

## Created Config Options for V-BASIC Product

### 1. CPU Cores
| Option | Value | Price/month |
|--------|-------|-------------|
| 2 Cores | 2 | $0 (base) |
| 4 Cores | 4 | +$10 |
| 8 Cores | 8 | +$25 |
| 16 Cores | 16 | +$60 |

### 2. Memory (RAM)
| Option | Value (MB) | Price/month |
|--------|------------|-------------|
| 4 GB | 4096 | $0 (base) |
| 8 GB | 8192 | +$15 |
| 16 GB | 16384 | +$40 |
| 32 GB | 32768 | +$100 |

### 3. Disk Size
| Option | Value (GB) | Price/month |
|--------|------------|-------------|
| 25 GB SSD | 25 | $0 (base) |
| 50 GB SSD | 50 | +$8 |
| 100 GB SSD | 100 | +$20 |
| 200 GB SSD | 200 | +$50 |

## Example Pricing Tiers

### Starter VPS
- 2 CPU Cores (base)
- 4 GB RAM (base)
- 25 GB SSD (base)
- **Total: $7.00/month** (base product price)

### Business VPS
- 4 CPU Cores (+$10)
- 8 GB RAM (+$15)
- 50 GB SSD (+$8)
- **Total: $40.00/month**

### Enterprise VPS
- 8 CPU Cores (+$25)
- 16 GB RAM (+$40)
- 100 GB SSD (+$20)
- **Total: $92.00/month**

### Ultimate VPS
- 16 CPU Cores (+$60)
- 32 GB RAM (+$100)
- 200 GB SSD (+$50)
- **Total: $217.00/month**

## How to Set Up Pricing for Config Options

1. **Navigate to:** Administration → Configuration → Config Options
2. **Edit** the config option (e.g., "CPU Cores")
3. **Click on Options tab**
4. **Edit each child option** (e.g., "4 Cores")
5. **Add a Plan** with pricing:
   - Plan Name: Same as option name (e.g., "4 Cores")
   - Type: Recurring
   - Billing Period: 1
   - Billing Unit: Month
   - Add Price: $10.00 USD

## Database Structure

```
config_options (parent options)
├── id: 1, name: "CPU Cores", env_variable: "cpu_cores"
│   └── children (config_options with parent_id)
│       ├── id: 2, name: "2 Cores", value: "2"
│       ├── id: 3, name: "4 Cores", value: "4"
│       ├── id: 4, name: "8 Cores", value: "8"
│       └── id: 5, name: "16 Cores", value: "16"
├── id: 6, name: "Memory (RAM)", env_variable: "memory"
│   └── children...
└── id: 11, name: "Disk Size", env_variable: "disk_size"
    └── children...
```

## How Config Options Work at Checkout

1. Customer selects product "V-BASIC"
2. Customer sees dropdown/radio options for:
   - CPU Cores
   - Memory (RAM)
   - Disk Size
3. Price updates dynamically based on selection
4. Selected values are stored as `ServiceConfig` records
5. Values are passed to Proxmox extension via environment variables:
   - `cpu_cores` → 4
   - `memory` → 8192
   - `disk_size` → 50

## Proxmox Integration

The Proxmox extension receives these values when creating a VM:

```php
// In Proxmox.php getProductConfig()
[
    'name' => 'cpu_cores',
    'type' => 'number',
    'default' => $values['cpu_cores'] ?? 2,
]
```

When a customer orders with config options, the selected values override the defaults.

## Alternative: Multiple Products Approach

If you prefer separate products instead of config options:

| Product | CPU | RAM | Disk | Base Price |
|---------|-----|-----|------|------------|
| VPS Starter | 2 | 4 GB | 25 GB | $7/mo |
| VPS Business | 4 | 8 GB | 50 GB | $40/mo |
| VPS Enterprise | 8 | 16 GB | 100 GB | $92/mo |
| VPS Ultimate | 16 | 32 GB | 200 GB | $217/mo |

**Pros of Config Options:**
- Single product to manage
- Customers can mix and match
- Easy upgrades later
- Cleaner admin interface

**Pros of Multiple Products:**
- More control over each tier
- Different server settings per product
- Easier to disable specific tiers

## Recommended Approach

Use **Config Options** for VPS hosting because:
1. Customers can start small and upgrade
2. Single Proxmox configuration template
3. Easier to manage pricing changes
4. Built-in upgrade path for customers
