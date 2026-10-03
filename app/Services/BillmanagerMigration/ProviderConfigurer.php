<?php

namespace App\Services\BillmanagerMigration;

use App\Models\Server;
use App\Services\Providers\XmlResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProviderConfigurer
{
    public function configure(ImportContext $context, string $encryptedBundle): array
    {
        $data = json_decode(Crypt::decryptString(file_get_contents($encryptedBundle)), true, flags: JSON_THROW_ON_ERROR);
        if (($data['schema_version'] ?? null) !== 1 || ($data['kind'] ?? null) !== 'provider_settings' || ($data['source_host'] ?? null) !== $context->sourceHost || !is_array($data['providers'] ?? null)) {
            throw new RuntimeException('Invalid provider settings source or bundle');
        }

        return DB::transaction(function () use ($context, $data) {
            $map = [];
            $seen = [];
            foreach ($data['providers'] as $provider) {
                $sourceId = (string) ($provider['id'] ?? '');
                if (!ctype_digit($sourceId) || isset($seen[$sourceId])) {
                    throw new RuntimeException('Invalid or duplicate provider identity');
                }
                $seen[$sourceId] = true;
                if (($provider['active'] ?? null) !== 'on') {
                    $context->archive('provider_dependencies', $sourceId, ['module' => $provider['module'] ?? null, 'active' => false, 'readiness' => 'disabled_source_provider']);

                    continue;
                }
                $url = XmlResponse::httpsEndpoint((string) ($provider['url'] ?? ''));
                [$extension,$settings] = match ($provider['module'] ?? null) {
                    'pmispmgr5' => ['ISPmanager', ['url' => $url, 'username' => $provider['login'] ?? '', 'password' => $provider['password'] ?? '']],
                    'pmdnsmgr' => ['DNSmanager', ['url' => $url, 'username' => $provider['login'] ?? '', 'password' => $provider['password'] ?? '']],
                    'pmthesslstore' => ['SSLStore', ['url' => 'https://' . parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PORT) ? ':' . parse_url($url, PHP_URL_PORT) : ''), 'partner_code' => $provider['partner_code'] ?? '', 'auth_token' => $provider['auth_token'] ?? '']],
                    'pmdirecti' => ['ResellerClub', ['environment' => match (parse_url($url, PHP_URL_HOST)) {
                        'httpapi.com' => 'live','test.httpapi.com' => 'test',default => throw new RuntimeException('Unknown registrar environment')
                    }, 'reseller_id' => $provider['reseller_id'] ?? '', 'api_key' => $provider['api_key'] ?? '']],
                    default => throw new RuntimeException('Active provider integration is unsupported'),
                };
                if (!class_exists('Paymenter\\Extensions\\Servers\\' . $extension . '\\' . $extension)) {
                    throw new RuntimeException('Required native provider extension is not installed');
                }
                foreach ($settings as $value) {
                    if (!is_string($value) || $value === '') {
                        throw new RuntimeException('Provider configuration is incomplete');
                    }
                }
                $id = $context->mappedId('provider_servers', $sourceId);
                if ($id === null) {
                    $id = DB::table('extensions')->insertGetId(['name' => 'Imported ' . $extension . ' ' . $sourceId, 'extension' => $extension, 'type' => 'server', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
                    $context->recordMapping('provider_servers', $sourceId, 'extensions', $id);
                    foreach ($settings as $key => $value) {
                        DB::table('settings')->insert(['key' => $key, 'value' => Crypt::encryptString($value), 'encrypted' => true, 'type' => 'string', 'settingable_id' => $id, 'settingable_type' => Server::class]);
                    }
                } else {
                    $server = Server::find($id);
                    if (!$server || $server->extension !== $extension || $server->enabled) {
                        throw new RuntimeException('Mapped provider configuration changed');
                    }
                    $actual = $server->settings->pluck('value', 'key')->all();
                    ksort($actual);
                    ksort($settings);
                    if ($actual !== $settings || $server->settings->contains(fn ($s) => !$s->encrypted)) {
                        throw new RuntimeException('Mapped provider configuration changed');
                    }
                }
                $map[$sourceId] = $id;
            }

            return $map;
        });
    }
}
