<?php

namespace App\Services\BillmanagerMigration;

use App\Models\Gateway;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GatewayConfigurer
{
    public function configure(Snapshot $snapshot, ImportContext $context, string $encryptedBundle): array
    {
        $data = json_decode(Crypt::decryptString(file_get_contents($encryptedBundle)), true, flags: JSON_THROW_ON_ERROR);
        if ($snapshot->sourceHost() !== $context->sourceHost || ($data['schema_version'] ?? null) !== 1 || ($data['kind'] ?? null) !== 'gateway_settings' || ($data['source_host'] ?? null) !== $context->sourceHost || !is_array($data['gateways'] ?? null) || !$data['gateways']) {
            throw new RuntimeException('Invalid gateway settings source or bundle');
        }
        $methods = array_column($snapshot->rows('paymethods'), null, 'id');
        $currencies = array_column($snapshot->rows('currencies'), 'iso', 'id');

        return DB::transaction(function () use ($context, $data, $methods, $currencies) {
            $map = [];
            $seen = [];
            foreach ($data['gateways'] as $record) {
                $id = (string) ($record['id'] ?? '');
                $method = $methods[$id] ?? null;
                $values = $record['settings'] ?? null;
                if (!ctype_digit($id) || isset($seen[$id]) || !$method || !is_array($values) || ($method['module'] ?? null) !== ($record['module'] ?? null) || ($method['active'] ?? null) !== 'on' || ($record['active'] ?? null) !== 'on') {
                    throw new RuntimeException('Gateway source identity or state does not match snapshot');
                }
                $seen[$id] = true;
                $currency = $currencies[$method['currency'] ?? ''] ?? null;
                if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency) || (isset($values['currency']) && (string) $values['currency'] !== (string) $method['currency'])) {
                    throw new RuntimeException('Gateway currency does not match snapshot');
                }
                $required = [];
                switch ($record['module']) {
                    case 'pmwebmoney':
                        if ($currency !== 'USD' || !preg_match('/^Z[0-9]{12}$/D', (string) ($values['purse'] ?? ''))) {
                            throw new RuntimeException('WebMoney purse or currency requires review');
                        }
                        $extension = 'WebMoney';
                        $settings = ['purse' => $values['purse'], 'secret' => $values['secret'] ?? '', 'currency' => $currency, 'test_mode' => '1'];
                        $required = ['secret'];
                        break;
                    case 'pmauthorizenet':
                        $api = $values['api_url'] ?? '';
                        $form = $values['form_url'] ?? '';
                        $environment = match ([$api, $form]) {
                            ['https://api.authorize.net/xml/v1/request.api', 'https://accept.authorize.net/payment/payment'] => 'production',
                            ['https://apitest.authorize.net/xml/v1/request.api', 'https://test.authorize.net/payment/payment'] => 'test',
                            default => throw new RuntimeException('Authorize.Net endpoints require environment review'),
                        };
                        if ($currency !== 'USD' || !preg_match('/^[a-fA-F0-9]{128}$/D', (string) ($values['signature_key'] ?? ''))) {
                            throw new RuntimeException('Authorize.Net currency or signature key requires review');
                        }
                        $extension = 'AuthorizeNet';
                        $settings = ['api_login_id' => $values['api_login_id'] ?? '', 'transaction_key' => $values['transaction_key'] ?? '', 'signature_key' => $values['signature_key'], 'environment' => $environment, 'currency' => $currency];
                        $required = ['api_login_id', 'transaction_key'];
                        break;
                    case 'pmklarna':
                        if (!in_array($values['environment'] ?? null, ['production', 'test', 'playground'], true)) {
                            throw new RuntimeException('Klarna environment requires review');
                        }
                        $extension = 'Klarna';
                        $settings = ['merchant_id' => $values['merchant_id'] ?? '', 'secret' => $values['secret'] ?? '', 'environment' => $values['environment'] === 'production' ? 'production' : 'test', 'currency' => $currency, 'region' => '', 'purchase_country' => '', 'locale' => ''];
                        $required = ['merchant_id', 'secret'];
                        break;
                    case 'pmwave':
                        $extension = 'Wave';
                        $settings = ['access_token' => $values['wave_access_token'] ?? '', 'business_id' => $values['wave_business_id'] ?? '', 'product_id' => $values['wave_product_id'] ?? '', 'currency' => $currency, 'webhook_business_id' => '', 'webhook_secret' => ''];
                        $required = ['access_token', 'business_id', 'product_id'];
                        break;
                    default:throw new RuntimeException('Active source gateway is unsupported');
                }
                $settings['collection_enabled'] = '0';
                foreach ($settings as $key => $value) {
                    if (!is_string($value) || in_array($key, $required, true) && ($value === '' || trim($value, '*') === '')) {
                        throw new RuntimeException('Gateway credentials are incomplete or masked');
                    }
                }
                if (!class_exists('Paymenter\\Extensions\\Gateways\\' . $extension . '\\' . $extension)) {
                    throw new RuntimeException('Native gateway extension is not installed');
                }
                $target = $context->mappedId('gateway_extensions', $id);
                if ($target === null) {
                    $target = DB::table('extensions')->insertGetId(['name' => 'Imported ' . $extension . ' ' . $id, 'extension' => $extension, 'type' => 'gateway', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
                    $context->recordMapping('gateway_extensions', $id, 'extensions', $target);
                    foreach ($settings as $key => $value) {
                        DB::table('settings')->insert(['key' => $key, 'value' => Crypt::encryptString($value), 'encrypted' => true, 'type' => 'string', 'settingable_id' => $target, 'settingable_type' => Gateway::class]);
                    }
                } else {
                    $gateway = Gateway::find($target);
                    if (!$gateway || $gateway->extension !== $extension || $gateway->enabled) {
                        throw new RuntimeException('Mapped gateway configuration changed');
                    }
                    $actual = $gateway->settings->pluck('value', 'key')->all();
                    ksort($actual);
                    ksort($settings);
                    if ($actual !== $settings || $gateway->settings->contains(fn ($s) => !$s->encrypted)) {
                        throw new RuntimeException('Mapped gateway configuration changed');
                    }
                }
                $context->archive('gateway_source_settings', $id, $record);
                $map[$id] = $target;
            }

            return $map;
        });
    }
}
