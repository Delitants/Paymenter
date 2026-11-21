<?php

namespace Tests\Unit\Opening;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Opening\NativeNetworkAdmission;

final class NativeNetworkAdmissionTest extends TestCase
{
    private static function isolated(): array
    {
        $interfaces = ['lo' => ['type' => 772, 'flags' => 9, 'state' => 'unknown']];
        foreach (['erspan0' => 1, 'gre0' => 778, 'gretap0' => 1, 'ip6_vti0' => 769, 'ip6gre0' => 823, 'ip6tnl0' => 769, 'ip_vti0' => 768, 'sit0' => 776, 'tunl0' => 768] as $name => $type) {
            $interfaces[$name] = ['type' => $type, 'flags' => 128, 'state' => 'down'];
        }

        return ['interfaces' => $interfaces, 'addresses' => [['interface' => 'lo', 'family' => 2, 'address' => '127.0.0.1'], ['interface' => 'lo', 'family' => 10, 'address' => '::1']],
            'ipv4_routes' => "Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\tMTU\tWindow\tIRTT\n", 'ipv6_routes' => '', 'capabilities' => '00000000000000c1'];
    }

    public function test_default_keeps_exact_loopback_requirement(): void
    {
        self::assertTrue(class_exists(NativeNetworkAdmission::class));
        self::assertFalse(NativeNetworkAdmission::allows(self::isolated(), false));
        self::assertTrue(NativeNetworkAdmission::allows(['interfaces' => ['lo' => []]], false));
    }

    public function test_opt_in_accepts_only_complete_disabled_fallback_snapshot(): void
    {
        self::assertTrue(NativeNetworkAdmission::allows(self::isolated(), true));
    }

    public static function deniedSnapshots(): iterable
    {
        foreach (['unexpected', 'up-flag', 'operstate', 'wrong-type', 'missing-device', 'ipv4-address', 'ipv6-address', 'ipv4-route', 'ipv6-route', 'net-admin', 'net-raw', 'sys-admin', 'missing-metadata', 'bad-capabilities', 'missing-loopback', 'missing-routes', 'missing-addresses'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('deniedSnapshots')]
    public function test_unsafe_or_incomplete_snapshot_refuses_admission(string $case): void
    {
        $value = self::isolated();
        switch ($case) {
            case 'unexpected': $value['interfaces']['eth0'] = ['type' => 1, 'flags' => 0, 'state' => 'down'];
                break;
            case 'up-flag': $value['interfaces']['tunl0']['flags'] |= 1;
                break;
            case 'operstate': $value['interfaces']['tunl0']['state'] = 'up';
                break;
            case 'wrong-type': $value['interfaces']['tunl0']['type'] = 1;
                break;
            case 'missing-device': unset($value['interfaces']['sit0']);
                break;
            case 'ipv4-address': $value['addresses'][] = ['interface' => 'tunl0', 'family' => 2, 'address' => '192.0.2.1'];
                break;
            case 'ipv6-address': $value['addresses'][] = ['interface' => 'sit0', 'family' => 10, 'address' => '2001:db8::1'];
                break;
            case 'ipv4-route': $value['ipv4_routes'] .= "tunl0\t00000000\t00000000\t0001\t0\t0\t0\t00000000\t0\t0\t0\n";
                break;
            case 'ipv6-route': $value['ipv6_routes'] = str_repeat('0', 32) . ' 00 ' . str_repeat('0', 32) . ' 00 ' . str_repeat('0', 32) . ' 00000000 00000000 00000000 00000000 sit0';
                break;
            case 'net-admin': $value['capabilities'] = '0000000000001000';
                break;
            case 'net-raw': $value['capabilities'] = '0000000000002000';
                break;
            case 'sys-admin': $value['capabilities'] = '0000000000200000';
                break;
            case 'missing-metadata': unset($value['interfaces']['gre0']['flags']);
                break;
            case 'bad-capabilities': $value['capabilities'] = 'unavailable';
                break;
            case 'missing-loopback': unset($value['interfaces']['lo']);
                break;
            case 'missing-routes': unset($value['ipv4_routes']);
                break;
            case 'missing-addresses': unset($value['addresses']);
                break;
        }
        self::assertFalse(NativeNetworkAdmission::allows($value, true));
    }
}
