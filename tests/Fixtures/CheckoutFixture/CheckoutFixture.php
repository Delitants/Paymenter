<?php

namespace Paymenter\Extensions\Servers\CheckoutFixture;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\ReadOnlyServer;
use App\Models\Product;

/** Synthetic browser-only fixture; install only in an isolated QA application. */
#[ExtensionMeta(name: 'Synthetic checkout', description: 'No provider operations', version: '1.0.0', author: 'Paymenter tests')]
class CheckoutFixture extends ReadOnlyServer
{
    public function getCheckoutConfig(Product $product, array $values = []): array
    {
        return [
            ['name' => 'vm_type', 'label' => 'Virtualization Type', 'type' => 'select', 'default' => 'qemu', 'options' => ['qemu' => 'QEMU', 'lxc' => 'LXC']],
            ['name' => 'os_template', 'label' => 'OS Template (LXC)', 'type' => 'select', 'default' => '', 'options' => ['' => 'Select template', 'template' => 'Synthetic template']],
            ['name' => 'cloud_image', 'label' => 'Cloud Image', 'type' => 'select', 'default' => '', 'options' => ['' => 'Select image', 'cloud' => 'Synthetic cloud']],
            ['name' => 'iso_image', 'label' => 'ISO Image', 'type' => 'select', 'default' => '', 'options' => ['' => 'Select ISO', 'iso' => 'Synthetic ISO']],
            ['name' => 'network', 'label' => 'Network', 'type' => 'section', 'fields' => [
                ['name' => 'ipv6_enabled', 'label' => 'Synthetic IPv6', 'type' => 'checkbox', 'default' => false],
            ]],
        ];
    }
}
