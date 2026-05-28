<?php

namespace Paymenter\Extensions\Others\IPPool;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Models\Extension as ExtensionModel;

/**
 * IP Pools Management
 *
 * Manage IP address pools for server assignments
 */
#[ExtensionMeta(
    name: 'IP Pools',
    description: 'Manage IP address pools for server assignments',
    version: '1.0.0',
    author: 'Paymenter Community',
    url: 'https://paymenter.org',
    icon: 'ri-global-line'
)]
class IPPool extends Extension
{
    /**
     * Get configuration fields
     */
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'info',
                'label' => 'IP Pools Management',
                'type' => 'placeholder',
                'content' => 'IP Pools is managed through the main navigation menu under Extensions → IP Pools. This extension enables the IP Pools feature.',
            ],
        ];
    }

    /**
     * Test configuration
     */
    public function testConfig(): bool|string
    {
        return true;
    }

    /**
     * Called when extension is installed
     */
    public function installed()
    {
        ExtensionModel::firstOrCreate(
            ['extension' => 'IPPool'],
            ['name' => 'IPPool', 'type' => 'other', 'enabled' => true]
        );
    }

    /**
     * Called when extension is uninstalled
     */
    public function uninstalled()
    {
        ExtensionModel::where('extension', 'IPPool')->delete();
    }

    /**
     * Boot the extension and auto-register if not exists
     */
    public function boot()
    {
        // Auto-register on boot if not already registered
        ExtensionModel::firstOrCreate(
            ['extension' => 'IPPool'],
            ['name' => 'IPPool', 'type' => 'other', 'enabled' => true]
        );
    }
}
