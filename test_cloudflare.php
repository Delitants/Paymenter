<?php
// Direct test script for Cloudflare settings

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use App\Services\CloudflareService;

echo "=== Testing Cloudflare Settings ===\n\n";

// Test 1: Check DB values
echo "1. Database Values:\n";
$settings = [
    'cloudflare_enabled',
    'cloudflare_whitelist_enabled',
    'cloudflare_custom_whitelist',
    'cloudflare_auto_update_ranges'
];

foreach ($settings as $key) {
    $setting = Setting::where('key', $key)->first();
    if ($setting) {
        echo "   $key = ";
        var_dump($setting->value);
    } else {
        echo "   $key = NOT FOUND\n";
    }
}

// Test 2: Check what getSettings() returns
echo "\n2. getSettings() simulation:\n";
$getSettingsResult = [
    'enabled' => in_array(Setting::where('key', 'cloudflare_enabled')->first()?->value, ['1', 1, true], true),
    'whitelist_enabled' => in_array(Setting::where('key', 'cloudflare_whitelist_enabled')->first()?->value, ['1', 1, true], true),
    'custom_whitelist' => Setting::where('key', 'cloudflare_custom_whitelist')->first()?->value ?? '',
    'auto_update_ranges' => !in_array(Setting::where('key', 'cloudflare_auto_update_ranges')->first()?->value, ['0', 0, false, null], true),
];
print_r($getSettingsResult);

// Test 3: Check middleware behavior
echo "\n3. Middleware Checks:\n";
$cfEnabled = in_array(Setting::where('key', 'cloudflare_enabled')->first()?->value, ['1', 1, true], true);
$whitelistEnabled = in_array(Setting::where('key', 'cloudflare_whitelist_enabled')->first()?->value, ['1', 1, true], true);
echo "   Cloudflare enabled: " . ($cfEnabled ? 'YES' : 'NO') . "\n";
echo "   Whitelist enabled: " . ($whitelistEnabled ? 'YES' : 'NO') . "\n";

// Test 4: Check if test IP is blocked
echo "\n4. IP Check (5.152.196.40):\n";
$cf = app(CloudflareService::class);
$testIp = '5.152.196.40';
$isCfIp = $cf->isCloudflareIp($testIp);
echo "   Is Cloudflare IP: " . ($isCfIp ? 'YES' : 'NO') . "\n";
echo "   Would be allowed: " . ($isCfIp ? 'YES (Cloudflare IP)' : 'NO (blocked)') . "\n";

// Test 5: Check custom whitelist
echo "\n5. Custom Whitelist:\n";
$customWhitelist = Setting::where('key', 'cloudflare_custom_whitelist')->first()?->value ?? '';
echo "   Raw value: '$customWhitelist'\n";
$whitelistIps = array_filter(array_map('trim', explode("\n", $customWhitelist)));
echo "   Parsed IPs: " . count($whitelistIps) . "\n";
if (!empty($whitelistIps)) {
    foreach ($whitelistIps as $ip) {
        echo "     - $ip\n";
    }
}

echo "\n=== Done ===\n";
