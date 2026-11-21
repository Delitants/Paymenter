<?php

namespace App\Services\BillmanagerMigration;

use RuntimeException;

final class ProxmoxMatcher
{
    public function match(array $service, array $resources): array
    {
        $hostname = strtolower(rtrim(trim($service['domain'] ?? ''), '.'));
        $ids = array_values(array_filter([(string) ($service['panelid'] ?? ''), (string) ($service['id'] ?? '')], fn ($id) => ctype_digit($id) && (int) $id > 0));
        $candidates = array_values(array_filter($resources, fn ($r) => in_array((string) ($r['vmid'] ?? ''), $ids, true) && in_array($r['type'] ?? '', ['qemu', 'lxc'], true)));
        $matches = array_values(array_filter($candidates, fn ($r) => $hostname !== '' &&
            strtolower(rtrim((string) ($r['name'] ?? ''), '.')) === $hostname &&
            preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D', $r['node'] ?? '') && empty($r['template'])));
        if (count($matches) !== 1) {
            throw new RuntimeException('Proxmox resource identity is missing, contradictory or ambiguous');
        }
        // A duplicated VMID across inventory nodes is never a safe attachment.
        if (count(array_filter($resources, fn ($r) => (string) ($r['vmid'] ?? '') === (string) $matches[0]['vmid'])) !== 1) {
            throw new RuntimeException('Proxmox resource identity has a duplicate VMID');
        }

        return $matches[0];
    }
}
