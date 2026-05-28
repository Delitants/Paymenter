<?php

namespace Paymenter\Extensions\Others\Cloudflare;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Models\Extension as ExtensionModel;

/**
 * Cloudflare Integration
 *
 * Restore real client IPs and enable Cloudflare features
 */
#[ExtensionMeta(
    name: 'Cloudflare',
    description: 'Restore real client IPs and enable Cloudflare IP whitelisting',
    version: '1.0.0',
    author: 'Paymenter Community',
    url: 'https://paymenter.org',
    icon: 'ri-cloud-line'
)]
class Cloudflare extends Extension
{
    /**
     * Get configuration fields
     */
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'info',
                'label' => 'Cloudflare Integration',
                'type' => 'placeholder',
                'content' => 'Cloudflare integration is managed automatically. This extension restores real client IPs and enables Cloudflare IP whitelisting. Configure settings via the admin panel under Extensions → Cloudflare.',
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
            ['extension' => 'Cloudflare'],
            ['name' => 'Cloudflare', 'type' => 'other', 'enabled' => true]
        );
    }

    /**
     * Called when extension is uninstalled
     */
    public function uninstalled()
    {
        ExtensionModel::where('extension', 'Cloudflare')->delete();
    }

    /**
     * Boot the extension and auto-register if not exists
     */
    public function boot()
    {
        // Auto-register on boot if not already registered
        ExtensionModel::firstOrCreate(
            ['extension' => 'Cloudflare'],
            ['name' => 'Cloudflare', 'type' => 'other', 'enabled' => true]
        );
    }
}
