<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Plan;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

class ResellerClubSyncPrices extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'resellerclub:sync-prices
                            {--tlds= : Comma-separated list of TLDs to sync (e.g., .com,.net,.org)}
                            {--markup=0 : Markup percentage to apply (e.g., 20 for 20%)}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Sync domain prices from ResellerClub API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting ResellerClub price sync...');

        // Get ResellerClub server configuration
        $resellerClubServer = Server::where('extension', 'ResellerClub')->first();

        if (!$resellerClubServer) {
            $this->error('ResellerClub server not configured. Please add a ResellerClub server first.');
            return Command::FAILURE;
        }

        $settings = $resellerClubServer->settings->pluck('value', 'key')->toArray();
        $resellerClub = new ResellerClub($settings);

        // Get configuration options
        $tlds = $this->option('tlds');
        $markup = (float) $this->option('markup');
        $dryRun = $this->option('dry-run');

        if ($tlds) {
            $tldList = array_map('trim', explode(',', $tlds));
            $this->info("Syncing specific TLDs: " . implode(', ', $tldList));
        } else {
            $tldList = null;
            $this->info('Syncing all available TLDs...');
        }

        $this->info("Markup: {$markup}%");
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        try {
            // Fetch price list from ResellerClub
            $priceList = $resellerClub->getPriceList($tldList);

            if (empty($priceList)) {
                $this->error('No prices received from ResellerClub API');
                return Command::FAILURE;
            }

            $this->info('Received ' . count($priceList) . ' TLD prices from ResellerClub');

            $updated = 0;
            $created = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($priceList as $tld => $prices) {
                try {
                    $result = $this->syncTldPrice($tld, $prices, $markup, $dryRun);

                    if ($result === 'updated') {
                        $updated++;
                    } elseif ($result === 'created') {
                        $created++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $this->error("Error syncing {$tld}: " . $e->getMessage());
                    $errors++;
                }
            }

            $this->newLine();
            $this->info('=== Sync Summary ===');
            $this->table(
                ['Action', 'Count'],
                [
                    ['Updated', $updated],
                    ['Created', $created],
                    ['Skipped (in use)', $skipped],
                    ['Errors', $errors],
                ]
            );

            Log::info('ResellerClub price sync completed', [
                'updated' => $updated,
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to sync prices: ' . $e->getMessage());
            Log::error('ResellerClub price sync failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }

    /**
     * Sync price for a single TLD
     */
    private function syncTldPrice(string $tld, array $prices, float $markup, bool $dryRun): string
    {
        $tld = strtolower($tld);
        if (!str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
        }

        // Check if product already exists for this TLD
        $existingProduct = Product::where('name', 'LIKE', "%{$tld}")
            ->orWhere('name', $tld)
            ->first();

        // Calculate prices with markup
        $registerPrice = $prices['register'] ?? 0;
        $renewPrice = $prices['renew'] ?? 0;
        $transferPrice = $prices['transfer'] ?? 0;

        // Apply markup
        $registerPriceWithMarkup = $registerPrice * (1 + $markup / 100);
        $renewPriceWithMarkup = $renewPrice * (1 + $markup / 100);
        $transferPriceWithMarkup = $transferPrice * (1 + $markup / 100);

        // Round to 2 decimal places
        $registerPriceWithMarkup = round($registerPriceWithMarkup, 2);
        $renewPriceWithMarkup = round($renewPriceWithMarkup, 2);
        $transferPriceWithMarkup = round($transferPriceWithMarkup, 2);

        if ($existingProduct) {
            // Check if product is in use by any services
            $inUse = $existingProduct->services()->where('status', 'active')->exists();

            if ($inUse) {
                // Product is in use - only update prices, don't change anything else
                $this->warn("⚠️  {$tld} is in use by clients - updating prices only");

                if (!$dryRun) {
                    $this->updateProductPrices($existingProduct, $registerPriceWithMarkup, $renewPriceWithMarkup, $transferPriceWithMarkup);
                }

                return 'updated';
            }

            // Product exists but not in use - can update fully
            $this->info("✓ {$tld} - Updating existing product");

            if (!$dryRun) {
                $this->updateProductPrices($existingProduct, $registerPriceWithMarkup, $renewPriceWithMarkup, $transferPriceWithMarkup);
            }

            return 'updated';
        }

        // Product doesn't exist - create it
        $this->info("+ {$tld} - Creating new product");

        if (!$dryRun) {
            $this->createTldProduct($tld, $registerPriceWithMarkup, $renewPriceWithMarkup, $transferPriceWithMarkup);
        }

        return 'created';
    }

    /**
     * Update product prices
     */
    private function updateProductPrices(Product $product, float $registerPrice, float $renewPrice, float $transferPrice): void
    {
        $product->update([
            'price' => $registerPrice,
        ]);

        // Update or create plans for different actions
        $this->updateOrCreatePlan($product, 'registration', $registerPrice);
        $this->updateOrCreatePlan($product, 'renewal', $renewPrice);
        $this->updateOrCreatePlan($product, 'transfer', $transferPrice);

        // Store ResellerClub price data
        $product->properties()->updateOrCreate(
            ['key' => 'resellerclub_prices'],
            [
                'value' => json_encode([
                    'register' => $registerPrice,
                    'renew' => $renewPrice,
                    'transfer' => $transferPrice,
                    'last_sync' => now()->toIso8601String(),
                ]),
            ]
        );
    }

    /**
     * Update or create a plan for the product
     */
    private function updateOrCreatePlan(Product $product, string $type, float $price): void
    {
        $plan = Plan::where('product_id', $product->id)
            ->where('name', 'LIKE', "%{$type}%")
            ->first();

        if ($plan) {
            $plan->update(['price' => $price]);
        } else {
            Plan::create([
                'product_id' => $product->id,
                'name' => ucfirst($type) . ' Plan',
                'price' => $price,
                'billing_unit' => 'year',
                'billing_period' => 1,
            ]);
        }
    }

    /**
     * Create a new product for a TLD
     */
    private function createTldProduct(string $tld, float $registerPrice, float $renewPrice, float $transferPrice): void
    {
        $product = Product::create([
            'name' => 'Domain' . $tld,
            'description' => 'Domain registration for ' . $tld . ' domains',
            'type' => 'domain',
            'price' => $registerPrice,
            'setup_fee' => 0,
        ]);

        // Create plans
        $this->updateOrCreatePlan($product, 'registration', $registerPrice);
        $this->updateOrCreatePlan($product, 'renewal', $renewPrice);
        $this->updateOrCreatePlan($product, 'transfer', $transferPrice);

        // Store ResellerClub price data
        $product->properties()->create([
            'key' => 'resellerclub_prices',
            'value' => json_encode([
                'register' => $registerPrice,
                'renew' => $renewPrice,
                'transfer' => $transferPrice,
                'last_sync' => now()->toIso8601String(),
            ]),
        ]);

        // Associate with ResellerClub server
        $resellerClubServer = Server::where('extension', 'ResellerClub')->first();
        if ($resellerClubServer) {
            $product->server_id = $resellerClubServer->id;
            $product->save();
        }
    }
}
