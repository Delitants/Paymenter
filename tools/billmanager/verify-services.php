<?php

use App\Models\Service;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\BillmanagerMigration\Snapshot;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    // Read-only reconciliation; output contains counts, never customer records.
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (count($argv) !== 4) {
        throw new RuntimeException('Usage: verify-services.php SNAPSHOT SOURCE LOGIN_CUTOFF');
    }
    $snapshot = Snapshot::load($argv[1], $argv[2], $argv[3]);
    $batch = DB::table('billmanager_imports')->where('source_host', $snapshot->sourceHost())->where('snapshot_sha256', $snapshot->checksum())->sole();
    $context = new ImportContext($batch->id, $snapshot->sourceHost());
    $counts = ['services' => 0, 'held' => 0, 'proxmox_attached' => 0, 'pending' => 0, 'active' => 0, 'suspended' => 0];
    foreach ($snapshot->rows('items') as $row) {
        $service = Service::findOrFail($context->mappedId('items', (string) $row['id']));
        $metadata = $service->billmanagerDetails;
        $owner = DB::table('billmanager_accounts')->where('id', $context->mappedId('accounts', (string) $row['account']))->value('owner_user_id');
        $addons = array_values(array_filter($snapshot->rows('addons'), fn ($a) => (string) $a['parent'] === (string) $row['id']));
        $parameters = array_values(array_filter($snapshot->rows('itemparams'), fn ($p) => (string) $p['item'] === (string) $row['id']));
        if (!$metadata || $metadata->details['original'] !== $row || $metadata->details['addons'] !== $addons || $metadata->details['parameters'] !== $parameters ||
            $service->user_id != $owner || !MigrationHold::isHeld($service) || !$service->product->hidden || $service->product->stock != 0 || $service->quantity != 1) {
            throw new RuntimeException('Service ownership, archive, catalog or hold mismatch');
        }
        $price = $metadata->details['price'];
        if ($price['source_amount'] !== $row['cost'] || !BigDecimal::of($price['native_amount'])->isEqualTo($service->price) ||
            !BigDecimal::of($service->price)->minus($row['cost'])->isEqualTo($price['rounding_delta']) ||
            $service->plan->type !== $price['type'] || $service->plan->billing_period !== $price['billing_period'] || $service->plan->billing_unit !== $price['billing_unit']) {
            throw new RuntimeException('Service renewal amount or period mismatch');
        }
        $expectedStatus = match ((string) $row['status']) {
            '1', '5' => 'pending', '2' => 'active', '3' => 'suspended'
        };
        if ($service->status !== $expectedStatus) {
            throw new RuntimeException('Service status mismatch');
        }
        $counts['services']++;
        $counts['held']++;
        $counts[$service->status]++;
        $counts['proxmox_attached'] += $service->properties()->where('key', 'proxmox_vm_id')->exists() ? 1 : 0;
    }
    $counts['queued_jobs'] = DB::table('jobs')->count();
    echo json_encode(['status' => 'verified', 'counts' => $counts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Service reconciliation failed; no acceptance result was issued.\n");
    exit(1);
}
