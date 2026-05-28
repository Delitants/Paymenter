<?php

namespace App\Console\Commands;

use App\Services\CloudflareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CloudflareRefreshRanges extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cloudflare:refresh-ranges';

    /**
     * The console command description.
     */
    protected $description = 'Refresh Cloudflare IP ranges from the official source';

    /**
     * Execute the console command.
     */
    public function handle(CloudflareService $cloudflare): int
    {
        $this->info('Refreshing Cloudflare IP ranges...');

        try {
            $cloudflare->refreshIpRanges();
            $counts = $cloudflare->getRangesCount();

            $this->info('IP ranges refreshed successfully!');
            $this->table(
                ['Type', 'Count'],
                [
                    ['IPv4 Ranges', $counts['ipv4_count']],
                    ['IPv6 Ranges', $counts['ipv6_count']],
                    ['Total', $counts['total']],
                ]
            );

            Log::info('Cloudflare IP ranges refreshed', $counts);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to refresh IP ranges: ' . $e->getMessage());
            Log::error('Cloudflare IP ranges refresh failed', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }
    }
}
