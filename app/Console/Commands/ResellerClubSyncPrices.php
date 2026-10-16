<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Server;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\ResellerClub\CatalogSync;
use Paymenter\Extensions\Servers\ResellerClub\SyncSchedule;
use Throwable;

class ResellerClubSyncPrices extends Command
{
    protected $signature = 'resellerclub:sync-prices
        {--server= : Explicit ResellerClub server ID}
        {--category= : Category for new draft domain products}
        {--source= : customer selling prices, cost prices or managed markup}
        {--tlds= : Optional comma-separated TLD selection}
        {--all : Override saved filtering and sync every active TLD}
        {--markup= : Percentage markup, applied once}
        {--dry-run : Validate and preview without database writes}
        {--scheduled : Run only when enabled and due}';

    protected $description = 'Refresh the active ResellerClub TLD catalog and domain prices';

    public static function settings(): array
    {
        return Setting::whereNull('settingable_id')->whereNull('settingable_type')->where('key', 'like', 'resellerclub.%')->get()->mapWithKeys(fn ($s) => [substr($s->key, 13) => $s->value])->all();
    }

    public static function saveSetting(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => 'resellerclub.' . $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => (string) $value]);
    }

    private static function scope(array $settings): string
    {
        $tlds = ($settings['sync_all_tlds'] ?? '1') === '1' ? null : array_values(array_unique(array_map(
            fn ($tld) => '.' . strtolower(ltrim(trim($tld), '.')), array_values(array_filter(array_map('trim', explode(',', $settings['tld_list'] ?? '')), fn ($value) => $value !== ''))
        )));
        if ($tlds !== null) {
            sort($tlds);
        }

        return hash('sha256', json_encode([(string) ($settings['server_id'] ?? ''), (string) ($settings['category_id'] ?? ''),
            $settings['price_source'] ?? 'customer', (string) ($settings['markup_percentage'] ?? '0'), $tlds], JSON_THROW_ON_ERROR));
    }

    public function handle(): int
    {
        $settings = self::settings();
        if ($this->option('scheduled') && (($settings['sync_enabled'] ?? '0') !== '1' || !class_exists(CatalogSync::class))) {
            return self::SUCCESS;
        }
        $scheduled = $settings;
        $scheduled['last_sync'] = ($settings['last_schedule_scope'] ?? '') === self::scope($settings)
            ? ($settings['last_scheduled_sync'] ?? null) : null;
        if ($this->option('scheduled') && !SyncSchedule::due($scheduled, CarbonImmutable::now())) {
            return self::SUCCESS;
        }
        try {
            $server = Server::where('extension', 'ResellerClub')->findOrFail($this->option('server') ?: ($settings['server_id'] ?? null));
            $category = Category::findOrFail($this->option('category') ?: ($settings['category_id'] ?? null));
            $source = $this->option('source') ?: ($settings['price_source'] ?? 'customer');
            $markup = $this->option('markup') ?? ($settings['markup_percentage'] ?? '0');
            $tlds = $this->option('tlds');
            if ($this->option('all') && $tlds !== null) {
                throw new \RuntimeException('Choose all TLDs or an explicit selection');
            }
            if ($tlds === null && !$this->option('all') && ($settings['sync_all_tlds'] ?? '1') !== '1') {
                $tlds = $settings['tld_list'] ?? '';
            }
            $selected = $tlds === null ? null : array_values(array_filter(array_map('trim', explode(',', $tlds))));
            $summary = (new CatalogSync)->sync($server, $category, $source, (string) $markup, (bool) $this->option('dry-run'), $selected);
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
            if (!$this->option('dry-run')) {
                self::saveSetting('last_sync', now()->toIso8601String());
                $runScope = self::scope(['server_id' => $server->id, 'category_id' => $category->id,
                    'price_source' => $source, 'markup_percentage' => $markup,
                    'sync_all_tlds' => $selected === null ? '1' : '0', 'tld_list' => implode(',', $selected ?? [])]);
                if ($runScope === self::scope($settings)) {
                    self::saveSetting('last_schedule_scope', $runScope);
                    self::saveSetting('last_scheduled_sync', now()->toIso8601String());
                }
                self::saveSetting('last_status', $summary['review_tlds'] ? 'review_required' : 'success');
                self::saveSetting('last_review_count', $summary['review_tlds']);
                self::saveSetting('last_count', $summary['tlds']);
                self::saveSetting('last_error', '');
                Log::info('ResellerClub catalog synchronized', $summary + ['server_id' => $server->id]);
            }

            return self::SUCCESS;
        } catch (Throwable) {
            // HTTP exceptions, SQL, and provider responses can contain private values.
            $message = 'Catalog sync failed. Verify the selected server, category, currency, API access and pricing settings. Last successful prices were retained.';
            if (!$this->option('dry-run')) {
                self::saveSetting('last_status', 'failed');
                self::saveSetting('last_error', $message);
                self::saveSetting('last_attempt', now()->toIso8601String());
            }
            $this->error($message);

            return self::FAILURE;
        }
    }
}
