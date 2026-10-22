<?php

namespace Tests\Fixtures\Opening;

/** Test infrastructure only; does not authorize a product operation. */
final class NativeNetworkAdmission
{
    private const FALLBACKS = ['erspan0' => 1, 'gre0' => 778, 'gretap0' => 1, 'ip6_vti0' => 769, 'ip6gre0' => 823, 'ip6tnl0' => 769, 'ip_vti0' => 768, 'sit0' => 776, 'tunl0' => 768];

    public static function allows(array $snapshot, bool $allowKernelFallbacks): bool
    {
        $interfaces = $snapshot['interfaces'] ?? null;
        if (!is_array($interfaces)) {
            return false;
        }
        if (!$allowKernelFallbacks) {
            return array_keys($interfaces) === ['lo'];
        }
        $expected = [...self::FALLBACKS, 'lo' => 772];
        if (count($interfaces) !== count($expected) || array_diff_key($interfaces, $expected) || array_diff_key($expected, $interfaces)) {
            return false;
        }
        foreach ($expected as $name => $type) {
            $metadata = $interfaces[$name];
            if (!is_array($metadata) || ($metadata['type'] ?? null) !== $type || !is_int($metadata['flags'] ?? null) || !is_string($metadata['state'] ?? null)) {
                return false;
            }
            if ($name !== 'lo' && (($metadata['flags'] & 1) !== 0 || $metadata['state'] !== 'down')) {
                return false;
            }
        }
        $caps = $snapshot['capabilities'] ?? null;
        if (!is_string($caps) || !preg_match('/^[a-f0-9]{16}$/Di', $caps) || (hexdec(substr($caps, -8)) & ((1 << 12) | (1 << 13) | (1 << 21))) !== 0 || !is_array($snapshot['addresses'] ?? null)) {
            return false;
        }
        foreach ($snapshot['addresses'] as $address) {
            if (($address['interface'] ?? null) !== 'lo' || !in_array($address['family'] ?? null, [2, 10], true) || !is_string($address['address'] ?? null) ||
                ($address['family'] === 2 ? !preg_match('/^127\.[0-9]+\.[0-9]+\.[0-9]+$/D', $address['address']) : $address['address'] !== '::1')) {
                return false;
            }
        }
        $ipv4 = $snapshot['ipv4_routes'] ?? null;
        $ipv6 = $snapshot['ipv6_routes'] ?? null;
        if (!is_string($ipv4) || !str_starts_with($ipv4, 'Iface') || !is_string($ipv6)) {
            return false;
        }
        foreach (array_slice(explode("\n", trim($ipv4)), 1) as $row) {
            $columns = preg_split('/\s+/', trim($row));
            if (trim($row) !== '' && (count($columns) !== 11 || $columns[0] !== 'lo')) {
                return false;
            }
        }
        foreach (explode("\n", trim($ipv6)) as $row) {
            $columns = preg_split('/\s+/', trim($row));
            if (trim($row) !== '' && (count($columns) !== 10 || $columns[9] !== 'lo')) {
                return false;
            }
        }

        return true;
    }

    /** Read actual kernel metadata; never accepts caller-supplied filesystem paths. */
    public static function current(bool $allowKernelFallbacks): bool
    {
        $deviceRows = @file('/proc/net/dev');
        if ($deviceRows === false) {
            return false;
        }
        $interfaces = [];
        foreach (array_slice($deviceRows, 2) as $row) {
            if (!str_contains($row, ':')) {
                return false;
            }
            $name = trim(explode(':', $row, 2)[0]);
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) {
                return false;
            }
            $type = @file_get_contents('/sys/class/net/' . $name . '/type');
            $flags = @file_get_contents('/sys/class/net/' . $name . '/flags');
            $state = @file_get_contents('/sys/class/net/' . $name . '/operstate');
            if ($type === false || $flags === false || $state === false || !ctype_digit(trim($type)) || !preg_match('/^0x[a-f0-9]+$/Di', trim($flags))) {
                return false;
            }
            $interfaces[$name] = ['type' => (int) trim($type), 'flags' => hexdec(trim($flags)), 'state' => trim($state)];
        }
        if (!$allowKernelFallbacks) {
            return self::allows(['interfaces' => $interfaces], false);
        }
        $actual = net_get_interfaces();
        if ($actual === false || array_diff_key($interfaces, $actual) || array_diff_key($actual, $interfaces)) {
            return false;
        }
        $addresses = [];
        foreach ($actual as $name => $interface) {
            foreach ($interface['unicast'] ?? [] as $address) {
                if (in_array($address['family'] ?? null, [2, 10], true)) {
                    $addresses[] = ['interface' => $name, 'family' => $address['family'], 'address' => $address['address'] ?? null];
                }
            }
        }
        $status = @file_get_contents('/proc/self/status');
        preg_match('/^CapEff:\s+([a-f0-9]{16})$/mi', $status ?: '', $caps);

        return self::allows(['interfaces' => $interfaces, 'addresses' => $addresses, 'ipv4_routes' => @file_get_contents('/proc/net/route'), 'ipv6_routes' => @file_get_contents('/proc/net/ipv6_route'), 'capabilities' => $caps[1] ?? null], true);
    }
}
