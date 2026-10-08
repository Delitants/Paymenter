<?php

namespace App\Services\BillmanagerMigration;

use App\Models\Gateway;
use App\Services\Billing\MoneyCalculator;
use App\Services\Gateways\GatewayFeePolicy;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GatewayFeeConfigurer
{
    public function configure(Snapshot $snapshot, ImportContext $context, string $encryptedBundle): array
    {
        $data = json_decode(Crypt::decryptString(file_get_contents($encryptedBundle)), true, flags: JSON_THROW_ON_ERROR);
        $import = DB::table('billmanager_imports')->where('id', $context->importId)->first();
        if (!$import || $import->source_host !== $context->sourceHost || $import->snapshot_sha256 !== $snapshot->checksum() || $snapshot->sourceHost() !== $context->sourceHost || ($data['schema_version'] ?? null) !== 1 || ($data['kind'] ?? null) !== 'gateway_settings' || ($data['source_host'] ?? null) !== $context->sourceHost || !is_array($data['fees'] ?? null) || !$data['fees']) {
            throw new RuntimeException('Invalid gateway fee source, snapshot or bundle.');
        }
        $methods = array_column($snapshot->rows('paymethods'), null, 'id');
        $currencies = array_column($snapshot->rows('currencies'), 'iso', 'id');
        $extensions = ['pmauthorizenet' => 'AuthorizeNet', 'pmklarna' => 'Klarna', 'pmwave' => 'Wave', 'pmwebmoney' => 'WebMoney'];

        return DB::transaction(function () use ($context, $data, $methods, $currencies, $extensions) {
            $map = [];
            foreach ($data['fees'] as $record) {
                $id = (string) ($record['id'] ?? '');
                $method = $methods[$id] ?? null;
                if (!ctype_digit($id) || isset($map[$id]) || !$method || ($record['module'] ?? null) !== ($method['module'] ?? null) || ($method['active'] ?? null) !== 'on' || ($record['active'] ?? null) !== 'on' || (string) ($record['currency'] ?? '') !== (string) ($method['currency'] ?? '') || !isset($extensions[$record['module']])) {
                    throw new RuntimeException('Gateway fee identity or active state changed.');
                }
                $currency = $currencies[$record['currency']] ?? null;
                if (!is_string($currency) || !preg_match('/\A[A-Z]{3}\z/D', $currency)) {
                    throw new RuntimeException('Gateway fee currency is unsupported.');
                }
                $calculator = new MoneyCalculator;
                $percent = (string) $calculator->rate((string) ($record['commissionpercent'] ?? ''));
                $fixed = (string) $calculator->money((string) ($record['commissionamount'] ?? ''));
                foreach (['commissionpercent', 'commissionamount'] as $key) {
                    if (isset($method[$key]) && !BigDecimal::of((string) $method[$key])->isEqualTo((string) $record[$key])) {
                        throw new RuntimeException('Fee values differ from the authoritative snapshot.');
                    }
                }
                $target = $context->mappedId('gateway_extensions', $id);
                $gateway = $target === null ? null : Gateway::whereKey($target)->lockForUpdate()->first();
                if (!$gateway || $gateway->extension !== $extensions[$record['module']] || $gateway->enabled || $gateway->settings()->where('key', 'collection_enabled')->first()?->value !== '0' || $gateway->settings()->where('key', 'currency')->first()?->value !== $currency) {
                    throw new RuntimeException('Fee preparation requires the mapped disabled gateway.');
                }
                $values = ['customer_fee_enabled' => '1', 'customer_fee_percent' => $percent, 'customer_fee_fixed' => $fixed, 'customer_fee_currency' => $currency];
                $existing = $gateway->settings()->whereIn('key', array_keys($values))->get();
                $archived = DB::table('billmanager_records')->where(['import_id' => $context->importId, 'source_table' => 'gateway_source_fees', 'source_id' => $id])->exists();
                if ($archived) {
                    $actual = $existing->pluck('value', 'key')->all();
                    ksort($actual);
                    $expected = $values;
                    ksort($expected);
                    if ($actual !== $expected || $existing->contains(fn ($setting) => !$setting->encrypted)) {
                        throw new RuntimeException('Mapped customer fee configuration changed.');
                    }
                } elseif ($existing->isNotEmpty()) {
                    throw new RuntimeException('Existing fee settings require reconciliation.');
                } else {
                    foreach ($values as $key => $value) {
                        $gateway->settings()->create(['key' => $key, 'type' => 'string', 'encrypted' => true, 'value' => $value]);
                    }
                }
                (new GatewayFeePolicy)->values($gateway, $currency);
                $context->archive('gateway_source_fees', $id, $record);
                $map[$id] = $target;
            }

            return $map;
        });
    }
}
