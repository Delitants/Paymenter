<?php

namespace Paymenter\Extensions\Servers\ISPConfig;

use App\Models\Server;
use App\Models\Service;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SoapClient;
use Exception;

/**
 * @extension(
 *     name="ISPConfig",
 *     type="server",
 *     description="ISPConfig 3 hosting control panel integration",
 *     version="1.0.0",
 *     author="Paymenter",
 *     server="true"
 * )
 */
class ISPConfig
{
    private ?string $session_id = null;
    private ?SoapClient $soap_client = null;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Get extension configuration
     */
    public function getConfig(): array
    {
        return [
            [
                'name' => 'soap_url',
                'type' => 'text',
                'required' => true,
                'label' => 'SOAP API URL',
                'description' => 'ISPConfig Remote API URL (e.g., https://server:8080/remote/index.php)',
            ],
            [
                'name' => 'username',
                'type' => 'text',
                'required' => true,
                'label' => 'API Username',
                'description' => 'ISPConfig admin or reseller username',
            ],
            [
                'name' => 'password',
                'type' => 'password',
                'required' => true,
                'label' => 'API Password',
                'description' => 'ISPConfig user password',
            ],
            [
                'name' => 'verify_ssl',
                'type' => 'boolean',
                'required' => false,
                'label' => 'Verify SSL',
                'description' => 'Verify SSL certificate (disable for self-signed certs)',
                'default' => false,
            ],
            [
                'name' => 'default_server_id',
                'type' => 'text',
                'required' => false,
                'label' => 'Default Server ID',
                'description' => 'Default ISPConfig server ID for provisioning (leave empty for auto-detect)',
            ],
        ];
    }

    /**
     * Test connection to ISPConfig
     */
    public function testConfig(): bool
    {
        try {
            $client = $this->connect();
            $result = $client->login($this->config['username'], $this->config['password']);

            if ($result) {
                $this->session_id = $result;
                $version = $client->get_function_list($this->session_id);
                $client->logout($this->session_id);
                return !empty($version);
            }

            return false;
        } catch (Exception $e) {
            Log::error('ISPConfig connection test failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Connect to ISPConfig SOAP API
     */
    private function connect(): SoapClient
    {
        if ($this->soap_client) {
            return $this->soap_client;
        }

        $verify_ssl = $this->config['verify_ssl'] ?? false;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verify_ssl,
                'verify_peer_name' => $verify_ssl,
            ]
        ]);

        $this->soap_client = new SoapClient(null, [
            'location' => $this->config['soap_url'],
            'uri' => str_replace('/index.php', '/', $this->config['soap_url']),
            'trace' => 1,
            'stream_context' => $context,
        ]);

        return $this->soap_client;
    }

    /**
     * Authenticate and get session ID
     */
    private function authenticate(): string
    {
        if ($this->session_id) {
            return $this->session_id;
        }

        $client = $this->connect();
        $this->session_id = $client->login($this->config['username'], $this->config['password']);

        if (!$this->session_id) {
            throw new Exception('Failed to authenticate with ISPConfig');
        }

        return $this->session_id;
    }

    /**
     * Get product configuration options
     */
    public function getProductConfig(): array
    {
        return [
            [
                'name' => 'type',
                'type' => 'select',
                'required' => true,
                'label' => 'Resource Type',
                'options' => [
                    'web_domain' => 'Web Domain',
                    'subdomain' => 'Subdomain',
                    'mail_domain' => 'Mail Domain',
                    'mail_user' => 'Mailbox',
                    'database' => 'MySQL Database',
                    'ftp_user' => 'FTP User',
                    'cron_job' => 'Cron Job',
                    'dns_zone' => 'DNS Zone',
                ],
            ],
            [
                'name' => 'php_version',
                'type' => 'select',
                'required' => false,
                'label' => 'PHP Version',
                'options' => [
                    'default' => 'System Default',
                    '704' => 'PHP 7.4',
                    '800' => 'PHP 8.0',
                    '801' => 'PHP 8.1',
                    '802' => 'PHP 8.2',
                    '803' => 'PHP 8.3',
                    '804' => 'PHP 8.4',
                ],
                'default' => 'default',
            ],
            [
                'name' => 'ssl',
                'type' => 'boolean',
                'required' => false,
                'label' => 'Enable SSL/Let\'s Encrypt',
                'default' => true,
            ],
            [
                'name' => 'quota_mb',
                'type' => 'text',
                'required' => false,
                'label' => 'Disk Quota (MB)',
                'description' => '0 for unlimited',
                'default' => '0',
            ],
            [
                'name' => 'traffic_mb',
                'type' => 'text',
                'required' => false,
                'label' => 'Traffic Quota (MB/month)',
                'description' => '0 for unlimited',
                'default' => '0',
            ],
        ];
    }

    /**
     * Create server resource
     */
    public function createServer(Server $server): bool
    {
        try {
            $client = $this->connect();
            $session = $client->login($this->config['username'], $this->config['password']);

            $functions = $client->get_function_list($session);
            $client->logout($session);

            return !empty($functions);
        } catch (Exception $e) {
            Log::error('ISPConfig server creation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Start server/provision resource
     */
    public function startServer(Service $service): bool
    {
        try {
            $session = $this->authenticate();
            $client = $this->connect();

            $product = $service->product;
            $settings = $product->settings()->get()->pluck('value', 'key')->toArray();
            $type = $settings['type'] ?? 'web_domain';

            // Get or create client
            $client_id = $this->getOrCreateClient($service->user);

            $server_id = $this->config['default_server_id'] ?? $this->getDefaultServerId($session, $client);

            switch ($type) {
                case 'web_domain':
                    return $this->createWebDomain($session, $client, $client_id, $server_id, $service, $settings);
                case 'subdomain':
                    return $this->createSubdomain($session, $client, $client_id, $server_id, $service, $settings);
                case 'mail_domain':
                    return $this->createMailDomain($session, $client, $client_id, $server_id, $service, $settings);
                case 'mail_user':
                    return $this->createMailUser($session, $client, $client_id, $server_id, $service, $settings);
                case 'database':
                    return $this->createDatabase($session, $client, $client_id, $server_id, $service, $settings);
                case 'ftp_user':
                    return $this->createFtpUser($session, $client, $client_id, $server_id, $service, $settings);
                case 'cron_job':
                    return $this->createCronJob($session, $client, $client_id, $server_id, $service, $settings);
                case 'dns_zone':
                    return $this->createDnsZone($session, $client, $client_id, $server_id, $service, $settings);
                default:
                    throw new Exception('Unknown resource type: ' . $type);
            }
        } catch (Exception $e) {
            Log::error('ISPConfig start server failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get or create ISPConfig client
     */
    private function getOrCreateClient($user): int
    {
        $session = $this->session_id;
        $client = $this->connect();

        // Try to find existing client by email
        $existing = $client->client_get_by_username($session, $user->email);

        if ($existing && isset($existing['client_id'])) {
            return $existing['client_id'];
        }

        // Create new client
        $params = [
            'company_name' => $user->company ?? $user->name,
            'email' => $user->email,
            'login_name' => $user->email,
            'password' => bin2hex(random_bytes(16)),
            'language' => 'en',
            'theme' => 'flat',
            'limit_maildomain' => '-1',
            'limit_mailbox' => '-1',
            'limit_web_domain' => '-1',
            'limit_database' => '-1',
            'limit_ftp_user' => '-1',
            'limit_traffic_mb' => '-1',
            'limit_web_quota' => '-1',
        ];

        $reseller_id = 1; // Default reseller
        $client_id = $client->client_add($session, $reseller_id, $params);

        return $client_id;
    }

    /**
     * Get default server ID
     */
    private function getDefaultServerId($session, $client): int
    {
        $servers = $client->server_get_all($session);
        return !empty($servers) ? $servers[0]['server_id'] : 1;
    }

    /**
     * Create web domain
     */
    private function createWebDomain($session, $client, $client_id, $server_id, Service $service, array $settings): bool
    {
        $domain = $service->domain ?? $service->getMeta('domain');

        if (!$domain) {
            throw new Exception('No domain specified for web domain service');
        }

        $params = [
            'server_id' => $server_id,
            'domain' => $domain,
            'type' => 'vhost',
            'ip_address' => $this->getDefaultIpAddress($session, $client, $server_id),
            'active' => 'y',
            'php' => $settings['php_version'] ?? 'default',
            'ssl' => $settings['ssl'] ?? 'y',
            'ssl_action' => $settings['ssl'] ? 'save' : '',
            'quota_mb' => $settings['quota_mb'] ?? 0,
            'traffic_mb' => $settings['traffic_mb'] ?? 0,
            'document_root' => "/var/www/clients/client{$client_id}/web{$server_id}/web/{$domain}",
            'system_user' => "client{$client_id}",
            'system_group' => "client{$client_id}",
        ];

        $result = $client->sites_web_domain_add($session, $client_id, $params);

        if ($settings['ssl'] ?? false) {
            // Request Let's Encrypt certificate
            $client->sites_web_domain_update($session, $client_id, $result, [
                'ssl' => 'y',
                'ssl_action' => 'save',
            ]);
        }

        $service->setMeta('ispconfig_id', $result);
        $service->setMeta('ispconfig_type', 'web_domain');

        return true;
    }

    /**
     * Create subdomain
     */
    private function createSubdomain($session, $client, $client_id, $server_id, Service $service, array $settings): bool
    {
        $domain = $service->domain ?? $service->getMeta('domain');
        $parent_domain = $service->getMeta('parent_domain');

        if (!$domain || !$parent_domain) {
            throw new Exception('Domain and parent domain required for subdomain');
        }

        $params = [
            'server_id' => $server_id,
            'parent_domain_id' => $this->getParentDomainId($session, $client, $parent_domain),
            'type' => 'vhost',
            'subdomain' => str_replace('.' . $parent_domain, '', $domain),
            'active' => 'y',
            'php' => $settings['php_version'] ?? 'default',
            'ssl' => $settings['ssl'] ?? 'y',
            'ssl_action' => $settings['ssl'] ? 'save' : '',
        ];

        $result = $client->sites_web_subdomain_add($session, $client_id, $params);

        $service->setMeta('ispconfig_id', $result);
        $service->setMeta('ispconfig_type', 'subdomain');

        return true;
    }

    /**
     * Create mail domain
     */
    private function createMailDomain($session, $client, $client_id, $server_id, Service $service, array $settings): bool
    {
        $domain = $service->domain ?? $service->getMeta('domain');

        if (!$domain) {
            throw new Exception('No domain specified for mail domain');
        }

        $params = [
            'server_id' => $server_id,
            'domain' => $domain,
            'active' => 'y',
        ];

        $result = $client->mail_domain_add($session, $client_id, $params);

        $service->setMeta('ispconfig_id', $result);
        $service->setMeta('ispconfig_type', 'mail_domain');

        return true;
    }

    /**
     * Create mail user (mailbox)
     */
    private function createMailUser($session, $client, $client_id, $server_id, Service $service, array $settings): bool
    {
        $email = $service->getMeta('email') ?? $service->user->email;
        $password = $service->getMeta('password') ?? bin2hex(random_bytes(16));
        $quota = $settings['quota_mb'] ?? 100;

        $params = [
            'server_id' => $server_id,
            'email' => $email,
            'password' => $password,
            'name' => $service->user->name,
            'maildir' => "/var/vmail/{$email}",
            'quota' => $quota,
            'active' => 'y',
        ];

        $result = $client->mail_user_add($session, $client_id, $params);

        $service->setMeta('ispconfig_id', $result);
        $service->setMeta('ispconfig_type', 'mail_user');

        return true;
    }

    /**
     * Create database
     */
    private function createDatabase($session, $client, $client_id, $server_id, Service $service, array $settings): bool
    {
        $db_name = $service->getMeta('db_name') ?? 'db_' . substr(md5($service->user->email . time()), 0, 10);
        $db_user = $service->getMeta('db_user') ?? 'u_' . substr(md5($service->user->email . time()), 0, 10);
        $db_password = $service->getMeta('db_password') ?? bin2hex(random_bytes(16));

        $params = [
            'server_id' => $server_id,
            'database_name' => $db_name,
            'database_user' => $db_user,
            'database_password' => $db_password,
            'database_type' => 'mysql',
            'remote' => 'n',
            'active' => 'y',
        ];

        $result = $client->sites_database_add($session, $client_id, $params);

        $service->setMeta('ispconfig_id', $result);
        $service->setMeta('ispconfig_type', 'database');
        $service->setMeta('db_name', $db_name);
        $service->setMeta('db_user', $db_user);
        $service->setMeta('db_password', $db_password);

        return true;
    }

    /**
     * Create FTP user
     */
    private function createFtpUser($session, $client, $client_id, $server_id, Service $service, array $settings): bool
    {
        $ftp_user = $service->getMeta('ftp_user') ?? 'ftp_' . substr(md5($service->user->email . time()), 0, 10);
        $ftp_password = $service->getMeta('ftp_password') ?? bin2hex(random_bytes(16));
        $dir = $service->getMeta('ftp_dir') ?? "/var/www/clients/client{$client_id}/web{$server_id}/web";

        $params = [
            'server_id' => $server_id,
            'username' => $ftp_user,
            'password' => $ftp_password,
            'dir' => $dir,
            'quota_size' => $settings['quota_mb'] ?? -1,
            'active' => 'y',
        ];

        $result = $client->sites_ftp_user_add($session, $client_id, $params);

        $service->setMeta('ispconfig_id', $result);
        $service->setMeta('ispconfig_type', 'ftp_user');
        $service->setMeta('ftp_user', $ftp_user);
        $service->setMeta('ftp_password', $ftp_password);

        return true;
    }

    /**
     * Create cron job
     */
    private function createCronJob($session, $client, $client_id, $server_id, Service $service, array $settings): bool
    {
        $command = $service->getMeta('cron_command') ?? 'echo "Cron job"';
        $schedule = $service->getMeta('cron_schedule') ?? '* * * * *';

        $params = [
            'server_id' => $server_id,
            'type' => 'command',
            'command' => $command,
            'schedule' => $schedule,
            'active' => 'y',
        ];

        $result = $client->sites_cron_add($session, $client_id, $params);

        $service->setMeta('ispconfig_id', $result);
        $service->setMeta('ispconfig_type', 'cron_job');

        return true;
    }

    /**
     * Create DNS zone
     */
    private function createDnsZone($session, $client, $client_id, $server_id, Service $service, array $settings): bool
    {
        $domain = $service->domain ?? $service->getMeta('domain');
        $ns1 = $settings['ns1'] ?? 'ns1.' . $domain;
        $ns2 = $settings['ns2'] ?? 'ns2.' . $domain;
        $email = $settings['email'] ?? 'hostmaster.' . $domain;

        $params = [
            'server_id' => $server_id,
            'origin' => $domain,
            'ns' => $ns1,
            'ns2' => $ns2,
            'email' => $email,
            'refresh' => 3600,
            'retry' => 1800,
            'expire' => 604800,
            'minimum' => 3600,
            'ttl' => 3600,
            'active' => 'y',
        ];

        $result = $client->dns_zone_add($session, $client_id, $params);

        $service->setMeta('ispconfig_id', $result);
        $service->setMeta('ispconfig_type', 'dns_zone');

        return true;
    }

    /**
     * Get default IP address
     */
    private function getDefaultIpAddress($session, $client, $server_id): string
    {
        try {
            $ips = $client->server_ip_get($session, $server_id);
            return $ips['ip_address'] ?? '127.0.0.1';
        } catch (Exception $e) {
            return '127.0.0.1';
        }
    }

    /**
     * Get parent domain ID
     */
    private function getParentDomainId($session, $client, $domain): int
    {
        $domains = $client->sites_web_domain_get($session, -1);
        foreach ($domains as $d) {
            if ($d['domain'] === $domain) {
                return $d['id'];
            }
        }
        return 1;
    }

    /**
     * Stop server/deprovision resource
     */
    public function stopServer(Service $service): bool
    {
        try {
            $session = $this->authenticate();
            $client = $this->connect();

            $ispconfig_id = $service->getMeta('ispconfig_id');
            $type = $service->getMeta('ispconfig_type');

            if (!$ispconfig_id) {
                return true; // Nothing to delete
            }

            switch ($type) {
                case 'web_domain':
                    $client->sites_web_domain_delete($session, $ispconfig_id);
                    break;
                case 'subdomain':
                    $client->sites_web_subdomain_delete($session, $ispconfig_id);
                    break;
                case 'mail_domain':
                    $client->mail_domain_delete($session, $ispconfig_id);
                    break;
                case 'mail_user':
                    $client->mail_user_delete($session, $ispconfig_id);
                    break;
                case 'database':
                    $client->sites_database_delete($session, $ispconfig_id);
                    break;
                case 'ftp_user':
                    $client->sites_ftp_user_delete($session, $ispconfig_id);
                    break;
                case 'cron_job':
                    $client->sites_cron_delete($session, $ispconfig_id);
                    break;
                case 'dns_zone':
                    $client->dns_zone_delete($session, $ispconfig_id);
                    break;
            }

            return true;
        } catch (Exception $e) {
            Log::error('ISPConfig stop server failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Suspend server/resource
     */
    public function suspendServer(Service $service): bool
    {
        try {
            $session = $this->authenticate();
            $client = $this->connect();

            $ispconfig_id = $service->getMeta('ispconfig_id');
            $type = $service->getMeta('ispconfig_type');

            if (!$ispconfig_id) {
                return true;
            }

            switch ($type) {
                case 'web_domain':
                    $client->sites_web_domain_update($session, $service->client_id, $ispconfig_id, ['active' => 'n']);
                    break;
                case 'mail_user':
                    $client->mail_user_update($session, $service->client_id, $ispconfig_id, ['active' => 'n']);
                    break;
                case 'database':
                    $client->sites_database_update($session, $service->client_id, $ispconfig_id, ['active' => 'n']);
                    break;
                case 'ftp_user':
                    $client->sites_ftp_user_update($session, $service->client_id, $ispconfig_id, ['active' => 'n']);
                    break;
            }

            return true;
        } catch (Exception $e) {
            Log::error('ISPConfig suspend failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Unsuspend server/resource
     */
    public function unsuspendServer(Service $service): bool
    {
        try {
            $session = $this->authenticate();
            $client = $this->connect();

            $ispconfig_id = $service->getMeta('ispconfig_id');
            $type = $service->getMeta('ispconfig_type');

            if (!$ispconfig_id) {
                return true;
            }

            switch ($type) {
                case 'web_domain':
                    $client->sites_web_domain_update($session, $service->client_id, $ispconfig_id, ['active' => 'y']);
                    break;
                case 'mail_user':
                    $client->mail_user_update($session, $service->client_id, $ispconfig_id, ['active' => 'y']);
                    break;
                case 'database':
                    $client->sites_database_update($session, $service->client_id, $ispconfig_id, ['active' => 'y']);
                    break;
                case 'ftp_user':
                    $client->sites_ftp_user_update($session, $service->client_id, $ispconfig_id, ['active' => 'y']);
                    break;
            }

            return true;
        } catch (Exception $e) {
            Log::error('ISPConfig unsuspend failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Terminate server/resource
     */
    public function terminateServer(Service $service): bool
    {
        return $this->stopServer($service);
    }

    /**
     * Get available actions
     */
    public function getActions(Service $service): array
    {
        return [
            [
                'name' => 'change_php_version',
                'label' => 'Change PHP Version',
                'type' => 'select',
                'options' => [
                    'default' => 'System Default',
                    '704' => 'PHP 7.4',
                    '800' => 'PHP 8.0',
                    '801' => 'PHP 8.1',
                    '802' => 'PHP 8.2',
                    '803' => 'PHP 8.3',
                    '804' => 'PHP 8.4',
                ],
            ],
            [
                'name' => 'toggle_ssl',
                'label' => 'Toggle SSL',
                'type' => 'button',
            ],
            [
                'name' => 'request_ssl',
                'label' => 'Request Let\'s Encrypt Certificate',
                'type' => 'button',
            ],
        ];
    }

    /**
     * Execute action
     */
    public function executeAction(Service $service, string $action, array $data = []): bool
    {
        try {
            $session = $this->authenticate();
            $client = $this->connect();

            $ispconfig_id = $service->getMeta('ispconfig_id');
            $type = $service->getMeta('ispconfig_type');

            if (!$ispconfig_id) {
                return false;
            }

            switch ($action) {
                case 'change_php_version':
                    if ($type === 'web_domain') {
                        $client->sites_web_domain_update($session, $service->client_id, $ispconfig_id, [
                            'php' => $data['php_version'] ?? 'default',
                        ]);
                        return true;
                    }
                    break;

                case 'toggle_ssl':
                    if ($type === 'web_domain') {
                        $current = $client->sites_web_domain_get($session, $ispconfig_id);
                        $new_ssl = $current['ssl'] === 'y' ? 'n' : 'y';
                        $client->sites_web_domain_update($session, $service->client_id, $ispconfig_id, [
                            'ssl' => $new_ssl,
                            'ssl_action' => $new_ssl === 'y' ? 'save' : '',
                        ]);
                        return true;
                    }
                    break;

                case 'request_ssl':
                    if ($type === 'web_domain') {
                        $client->sites_web_domain_update($session, $service->client_id, $ispconfig_id, [
                            'ssl' => 'y',
                            'ssl_action' => 'save',
                        ]);
                        return true;
                    }
                    break;
            }

            return false;
        } catch (Exception $e) {
            Log::error('ISPConfig action failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get server metrics
     */
    public function getMetrics(Service $service): array
    {
        try {
            $session = $this->authenticate();
            $client = $this->connect();

            $ispconfig_id = $service->getMeta('ispconfig_id');
            $type = $service->getMeta('ispconfig_type');

            $metrics = [];

            switch ($type) {
                case 'web_domain':
                    $domain = $client->sites_web_domain_get($session, $ispconfig_id);
                    $metrics = [
                        'Domain' => $domain['domain'] ?? 'N/A',
                        'Status' => ($domain['active'] ?? 'n') === 'y' ? 'Active' : 'Inactive',
                        'PHP Version' => $domain['php'] ?? 'Default',
                        'SSL' => ($domain['ssl'] ?? 'n') === 'y' ? 'Enabled' : 'Disabled',
                        'Traffic' => ($domain['traffic_mb'] ?? 0) . ' MB',
                        'Quota' => ($domain['quota_mb'] ?? 0) . ' MB',
                    ];
                    break;

                case 'mail_user':
                    $mail = $client->mail_user_get($session, $ispconfig_id);
                    $metrics = [
                        'Email' => $mail['email'] ?? 'N/A',
                        'Status' => ($mail['active'] ?? 'n') === 'y' ? 'Active' : 'Inactive',
                        'Quota' => ($mail['quota'] ?? 0) . ' MB',
                    ];
                    break;

                case 'database':
                    $db = $client->sites_database_get($session, $ispconfig_id);
                    $metrics = [
                        'Name' => $db['database_name'] ?? 'N/A',
                        'User' => $db['database_user'] ?? 'N/A',
                        'Status' => ($db['active'] ?? 'n') === 'y' ? 'Active' : 'Inactive',
                    ];
                    break;

                case 'ftp_user':
                    $ftp = $client->sites_ftp_user_get($session, $ispconfig_id);
                    $metrics = [
                        'Username' => $ftp['username'] ?? 'N/A',
                        'Directory' => $ftp['dir'] ?? 'N/A',
                        'Status' => ($ftp['active'] ?? 'n') === 'y' ? 'Active' : 'Inactive',
                    ];
                    break;
            }

            return $metrics;
        } catch (Exception $e) {
            Log::error('ISPConfig get metrics failed: ' . $e->getMessage());
            return ['Error' => 'Unable to fetch metrics'];
        }
    }
}
