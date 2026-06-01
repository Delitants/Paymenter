<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueNetwork implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value) || strpos($value, '/') === false) {
            return;
        }

        [$ip, $cidr] = explode('/', $value);
        $cidr = (int) $cidr;
        $ipVersion = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'ipv6' : 'ipv4';

        // Check for exact duplicate
        $exists = DB::table('ip_pools')
            ->where('network_address', $value)
            ->exists();

        if ($exists) {
            $fail("Network {$value} already exists in another pool.");
            return;
        }

        // Check for overlapping networks (IPv4 only)
        if ($ipVersion === 'ipv4') {
            $newStart = ip2long($ip);
            $newEnd = $newStart + pow(2, (32 - $cidr)) - 1;

            $existingPools = DB::table('ip_pools')
                ->where('ip_version', 'ipv4')
                ->whereNotNull('network_address')
                ->get();

            foreach ($existingPools as $pool) {
                [$existingIp, $existingCidr] = explode('/', $pool->network_address);
                $existingCidr = (int) $existingCidr;
                $existingStart = ip2long($existingIp);
                $existingEnd = $existingStart + pow(2, (32 - $existingCidr)) - 1;

                if ($newStart <= $existingEnd && $newEnd >= $existingStart) {
                    $fail("Network {$value} overlaps with existing pool: {$pool->network_address}.");
                    return;
                }
            }
        }
    }
}
