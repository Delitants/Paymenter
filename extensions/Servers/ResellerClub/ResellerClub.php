<?php

namespace Paymenter\Extensions\Servers\ResellerClub;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ResellerClub Domain Integration
 *
 * Full integration with ResellerClub for domain registration, transfer,
 * renewal, and management via HTTP API.
 *
 * @link https://manage.resellerclub.com/kb/answer/744
 * @link https://www.resellerclub.com/domain-reseller/api
 */
#[ExtensionMeta(
    name: 'ResellerClub',
    description: 'Register and manage domains via ResellerClub (1000+ TLDs supported)',
    version: '1.0.0',
    author: 'Paymenter Community',
    url: 'https://paymenter.org/docs/extensions/resellerclub',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJjdXJyZW50Q29sb3IiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj48Y2lyY2xlIGN4PSIxMiIgY3k9IjgiIHI9IjYiLz48cGF0aCBkPSJNMTIgMTR2OG0tNC00aDhtLTQtNEgxNiIvPjwvc3ZnPg=='
)]
class ResellerClub extends Server
{
    private const API_URL_TEST = 'https://test.httpapi.com/api';
    private const API_URL_LIVE = 'https://httpapi.com/api';

    /**
     * Get price list from ResellerClub
     */
    public function getPriceList(?array $tlds = null): array
    {
        $data = [];

        if ($tlds) {
            // Fetch specific TLDs
            foreach ($tlds as $tld) {
                $tld = trim($tld, '.');
                try {
                    $response = $this->request('/pricing/get-price.json', 'POST', [
                        'name' => $tld,
                    ]);

                    if (isset($response['price']) && is_array($response['price'])) {
                        $prices = $response['price'];
                        $data['.' . $tld] = [
                            'register' => (float) ($prices['registration'] ?? 0),
                            'renew' => (float) ($prices['renewal'] ?? 0),
                            'transfer' => (float) ($prices['transfer'] ?? 0),
                        ];
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to fetch price for .' . $tld, ['error' => $e->getMessage()]);
                }
            }
        } else {
            // Fetch all prices (bulk)
            try {
                $response = $this->request('/pricing/get-price-list.json', 'POST');

                if (isset($response['pricing']) && is_array($response['pricing'])) {
                    foreach ($response['pricing'] as $pricing) {
                        $tld = '.' . strtolower($pricing['name'] ?? '');
                        $data[$tld] = [
                            'register' => (float) ($pricing['registration'] ?? 0),
                            'renew' => (float) ($pricing['renewal'] ?? 0),
                            'transfer' => (float) ($pricing['transfer'] ?? 0),
                        ];
                    }
                }
            } catch (Exception $e) {
                Log::error('Failed to fetch price list', ['error' => $e->getMessage()]);
            }
        }

        return $data;
    }

    /**
     * Get single TLD pricing
     */
    public function getPrice(string $tld): array
    {
        $tld = trim($tld, '.');

        $response = $this->request('/pricing/get-price.json', 'POST', [
            'name' => $tld,
        ]);

        if (isset($response['price']) && is_array($response['price'])) {
            $prices = $response['price'];
            return [
                'tld' => '.' . $tld,
                'register' => (float) ($prices['registration'] ?? 0),
                'renew' => (float) ($prices['renewal'] ?? 0),
                'transfer' => (float) ($prices['transfer'] ?? 0),
                'currency' => $response['currency'] ?? 'USD',
            ];
        }

        return [];
    }

    /**
     * Get gateway configuration fields
     */
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'text',
                'description' => 'Your ResellerClub API Key',
                'required' => true,
                'encrypted' => true,
                'placeholder' => 'Your API key',
            ],
            [
                'name' => 'reseller_id',
                'label' => 'Reseller ID',
                'type' => 'text',
                'description' => 'Your ResellerClub Reseller ID',
                'required' => true,
                'placeholder' => '123456',
            ],
            [
                'name' => 'environment',
                'label' => 'Environment',
                'type' => 'select',
                'description' => 'Test or Live environment',
                'options' => [
                    'test' => 'Test (Sandbox)',
                    'live' => 'Live (Production)',
                ],
                'default' => 'test',
                'required' => true,
            ],
            [
                'name' => 'auto_dns',
                'label' => 'Auto-configure DNS',
                'type' => 'checkbox',
                'description' => 'Automatically configure DNS for new domains',
                'default' => false,
            ],
            [
                'name' => 'default_ns1',
                'label' => 'Default Nameserver 1',
                'type' => 'text',
                'description' => 'Primary nameserver',
                'required' => false,
                'placeholder' => 'ns1.yourserver.com',
            ],
            [
                'name' => 'default_ns2',
                'label' => 'Default Nameserver 2',
                'type' => 'text',
                'description' => 'Secondary nameserver',
                'required' => false,
                'placeholder' => 'ns2.yourserver.com',
            ],
        ];
    }

    /**
     * Test the connection configuration
     */
    public function testConfig(): bool|string
    {
        $apiKey = $this->config('api_key');
        $resellerId = $this->config('reseller_id');
        $environment = $this->config('environment') ?? 'test';

        if (empty($apiKey)) {
            return 'API Key is required';
        }

        if (empty($resellerId)) {
            return 'Reseller ID is required';
        }

        // Test API connection by checking a domain
        try {
            $response = $this->request('/domains/check.json', 'POST', [
                'domain-name' => 'google.com',
            ]);

            if (isset($response['status']) && in_array($response['status'], ['ok', 'available', 'registered'])) {
                return true;
            }

            return 'Connection successful but unexpected response';
        } catch (Exception $e) {
            return 'Connection failed: ' . $e->getMessage();
        }
    }

    /**
     * Make HTTP request to ResellerClub API
     */
    private function request(string $endpoint, string $method = 'POST', array $data = []): mixed
    {
        $baseUrl = $this->config('environment') === 'live'
            ? self::API_URL_LIVE
            : self::API_URL_TEST;

        $url = $baseUrl . $endpoint;

        $data['auth-userid'] = $this->config('reseller_id');
        $data['api-key'] = $this->config('api_key');

        $response = Http::asForm()->$method($url, $data);

        if (!$response->successful()) {
            $errorBody = $response->body();
            Log::error('ResellerClub API Error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $errorBody,
            ]);

            // Try to parse error from JSON
            $errorData = $response->json();
            if (isset($errorData['message'])) {
                throw new Exception('ResellerClub API: ' . $errorData['message']);
            }

            throw new Exception('ResellerClub API Error: ' . $response->status());
        }

        $result = $response->json();

        Log::info('ResellerClub API Response', [
            'endpoint' => $endpoint,
            'response' => $result,
        ]);

        return $result;
    }

    /**
     * Get product configuration for domains
     */
    public function getProductConfig($values = []): array
    {
        return [
            [
                'name' => 'tld',
                'label' => 'TLD',
                'type' => 'text',
                'description' => 'Top-level domain (e.g., .com, .net, .org)',
                'required' => true,
                'placeholder' => '.com',
            ],
            [
                'name' => 'registration_years',
                'label' => 'Registration Period',
                'type' => 'select',
                'description' => 'Default registration period',
                'options' => [
                    '1' => '1 Year',
                    '2' => '2 Years',
                    '3' => '3 Years',
                    '4' => '4 Years',
                    '5' => '5 Years',
                    '6' => '6 Years',
                    '7' => '7 Years',
                    '8' => '8 Years',
                    '9' => '9 Years',
                    '10' => '10 Years',
                ],
                'default' => '1',
                'required' => true,
            ],
            [
                'name' => 'transfer_lock',
                'label' => 'Transfer Lock',
                'type' => 'checkbox',
                'description' => 'Enable domain transfer lock by default',
                'default' => true,
            ],
            [
                'name' => 'privacy_protection',
                'label' => 'Privacy Protection',
                'type' => 'checkbox',
                'description' => 'Enable WHOIS privacy protection (if available for TLD)',
                'default' => false,
            ],
            [
                'name' => 'auto_renew',
                'label' => 'Auto Renew',
                'type' => 'checkbox',
                'description' => 'Enable automatic domain renewal',
                'default' => true,
            ],
            [
                'name' => 'dns_management',
                'label' => 'DNS Management',
                'type' => 'checkbox',
                'description' => 'Enable DNS management for this domain',
                'default' => true,
            ],
            [
                'name' => 'email_forwarding',
                'label' => 'Email Forwarding',
                'type' => 'checkbox',
                'description' => 'Enable email forwarding',
                'default' => false,
            ],
            [
                'name' => 'id_protection',
                'label' => 'ID Protection',
                'type' => 'checkbox',
                'description' => 'Enable ID protection (if available)',
                'default' => false,
            ],
        ];
    }

    /**
     * Check domain availability
     */
    public function checkAvailability(string $domain): array
    {
        try {
            $response = $this->request('/domains/check.json', 'POST', [
                'domain-name' => $domain,
            ]);

            return [
                'available' => ($response['status'] ?? '') === 'available',
                'status' => $response['status'] ?? 'unknown',
                'domain' => $domain,
            ];
        } catch (Exception $e) {
            return [
                'available' => false,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create/Get customer contact ID
     */
    private function createOrGetContact(array $contactData, string $type = 'customer'): int
    {
        // First try to find existing contact
        try {
            $response = $this->request('/contacts/search.json', 'POST', [
                'name' => $contactData['first_name'] . ' ' . $contactData['last_name'],
                'email' => $contactData['email'],
            ]);

            if (!empty($response['contacts'])) {
                return (int) $response['contacts'][0]['contact_id'];
            }
        } catch (Exception $e) {
            // Contact not found, create new one
        }

        // Create new contact
        $createData = [
            'first-name' => $contactData['first_name'],
            'last-name' => $contactData['last_name'],
            'company-name' => $contactData['company_name'] ?? '',
            'address1' => $contactData['address1'],
            'address2' => $contactData['address2'] ?? '',
            'city' => $contactData['city'],
            'state' => $contactData['state'] ?? '',
            'country' => $contactData['country'],
            'zipcode' => $contactData['zipcode'],
            'phone' => $contactData['phone'],
            'email' => $contactData['email'],
        ];

        $response = $this->request('/contacts/add.json', 'POST', $createData);

        return (int) ($response['contact_id'] ?? 0);
    }

    /**
     * Create a new domain registration
     */
    public function createServer(Service $service, $settings, $properties)
    {
        $domain = $service->label ?? $service->product->name;
        $domain = str_replace(['http://', 'https://', 'www.'], '', $domain);
        $domain = strtolower(trim($domain));

        // Get or create customer contact
        $user = $service->user;
        $contactData = [
            'first_name' => $user->first_name ?? 'Domain',
            'last_name' => $user->last_name ?? 'Owner',
            'email' => $user->email,
            'company_name' => $user->company_name ?? '',
            'address1' => $user->address1 ?? '123 Main St',
            'address2' => $user->address2 ?? '',
            'city' => $user->city ?? 'City',
            'state' => $user->state ?? 'State',
            'country' => $user->country ?? 'US',
            'zipcode' => $user->zipcode ?? '12345',
            'phone' => $user->phone ?? '+1.1234567890',
        ];

        $contactId = $this->createOrGetContact($contactData);

        // Get nameservers
        $nameservers = [];
        if ($this->config('default_ns1')) {
            $nameservers[] = $this->config('default_ns1');
        }
        if ($this->config('default_ns2')) {
            $nameservers[] = $this->config('default_ns2');
        }
        if (empty($nameservers)) {
            $nameservers = ['ns1.default.com', 'ns2.default.com'];
        }

        // Build registration data
        $registerData = [
            'domain-name' => $domain,
            'years' => $settings['registration_years'] ?? 1,
            'customer-id' => $contactId,
            'reg-contact-id' => $contactId,
            'admin-contact-id' => $contactId,
            'tech-contact-id' => $contactId,
            'billing-contact-id' => $contactId,
            'ns1' => $nameservers[0],
            'ns2' => $nameservers[1] ?? ($nameservers[0] ?? ''),
            'invoice-option' => 'NoInvoice', // We handle billing in Paymenter
            'auto-renew' => ($settings['auto_renew'] ?? true) ? 'true' : 'false',
            'domain-privacy-protection' => ($settings['privacy_protection'] ?? false) ? 'true' : 'false',
        ];

        // Add additional nameservers
        foreach ($nameservers as $index => $ns) {
            $registerData['ns' . ($index + 1)] = $ns;
        }

        // Register the domain
        $response = $this->request('/domains/register.json', 'POST', $registerData);

        if (empty($response['order_id']) && empty($response['entity_id'])) {
            throw new Exception('Domain registration failed: No order ID returned');
        }

        $orderId = $response['order_id'] ?? $response['entity_id'];

        // Store order ID in service properties
        $service->properties()->updateOrCreate(
            ['key' => 'resellerclub_order_id'],
            ['value' => $orderId]
        );

        $service->properties()->updateOrCreate(
            ['key' => 'resellerclub_domain'],
            ['value' => $domain]
        );

        $service->properties()->updateOrCreate(
            ['key' => 'resellerclub_contact_id'],
            ['value' => $contactId]
        );

        return [
            'order_id' => $orderId,
            'domain' => $domain,
            'status' => 'registered',
        ];
    }

    /**
     * Transfer a domain to ResellerClub
     */
    public function transferDomain(Service $service, $settings, $properties)
    {
        $domain = $service->label ?? $service->product->name;
        $domain = str_replace(['http://', 'https://', 'www.'], '', $domain);
        $domain = strtolower(trim($domain));

        $authCode = $properties['auth_code'] ?? '';

        if (empty($authCode)) {
            throw new Exception('EPP/Auth code is required for domain transfer');
        }

        $user = $service->user;
        $contactId = $this->createOrGetContact([
            'first_name' => $user->first_name ?? 'Domain',
            'last_name' => $user->last_name ?? 'Owner',
            'email' => $user->email,
            'company_name' => $user->company_name ?? '',
            'address1' => $user->address1 ?? '123 Main St',
            'address2' => $user->address2 ?? '',
            'city' => $user->city ?? 'City',
            'state' => $user->state ?? 'State',
            'country' => $user->country ?? 'US',
            'zipcode' => $user->zipcode ?? '12345',
            'phone' => $user->phone ?? '+1.1234567890',
        ]);

        $transferData = [
            'domain-name' => $domain,
            'auth-key' => $authCode,
            'customer-id' => $contactId,
            'reg-contact-id' => $contactId,
            'admin-contact-id' => $contactId,
            'tech-contact-id' => $contactId,
            'billing-contact-id' => $contactId,
            'invoice-option' => 'NoInvoice',
        ];

        $response = $this->request('/domains/transfer.json', 'POST', $transferData);

        $orderId = $response['order_id'] ?? $response['entity_id'] ?? null;

        if (!$orderId) {
            throw new Exception('Domain transfer failed');
        }

        $service->properties()->updateOrCreate(
            ['key' => 'resellerclub_order_id'],
            ['value' => $orderId]
        );

        $service->properties()->updateOrCreate(
            ['key' => 'resellerclub_domain'],
            ['value' => $domain]
        );

        return [
            'order_id' => $orderId,
            'domain' => $domain,
            'status' => 'transfer_pending',
        ];
    }

    /**
     * Renew a domain
     */
    public function renewDomain(Service $service, int $years = 1): bool
    {
        $properties = $service->properties->pluck('value', 'key')->toArray();
        $orderId = $properties['resellerclub_order_id'] ?? null;
        $domain = $properties['resellerclub_domain'] ?? null;

        if (!$orderId) {
            throw new Exception('Order ID not found for this service');
        }

        // Get current expiry date
        $domainDetails = $this->getDomainDetails($domain);
        $expDate = $domainDetails['expiry'] ?? time() + (365 * 24 * 60 * 60);

        $renewData = [
            'order-id' => $orderId,
            'years' => $years,
            'exp-date' => $expDate,
            'invoice-option' => 'NoInvoice',
        ];

        $response = $this->request('/domains/renew.json', 'POST', $renewData);

        return isset($response['order_id']) || isset($response['entity_id']);
    }

    /**
     * Get domain details
     */
    public function getDomainDetails(?string $domain = null): array
    {
        $properties = app()->bound('service')
            ? app('service')->properties->pluck('value', 'key')->toArray()
            : [];

        $domain = $domain ?? ($properties['resellerclub_domain'] ?? null);

        if (!$domain) {
            return ['error' => 'Domain not specified'];
        }

        try {
            $response = $this->request('/domains/details.json', 'POST', [
                'domain-name' => $domain,
            ]);

            return [
                'domain' => $response['domain_name'] ?? $domain,
                'status' => $response['status'] ?? 'unknown',
                'expiry' => $response['expiry'] ?? null,
                'created' => $response['created_on'] ?? null,
                'nameservers' => $response['nameservers'] ?? [],
                'contacts' => $response['contacts'] ?? [],
                'auto_renew' => ($response['auto_renew'] ?? false),
                'locked' => ($response['lock_status'] ?? 'unlocked') === 'locked',
            ];
        } catch (Exception $e) {
            return [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update nameservers for a domain
     */
    public function updateNameservers(Service $service, array $nameservers): bool
    {
        $properties = $service->properties->pluck('value', 'key')->toArray();
        $domain = $properties['resellerclub_domain'] ?? null;

        if (!$domain) {
            throw new Exception('Domain not found for this service');
        }

        $modifyData = ['domain-name' => $domain];

        foreach ($nameservers as $index => $ns) {
            $modifyData['ns' . ($index + 1)] = $ns;
        }

        $response = $this->request('/domains/modify-ns.json', 'POST', $modifyData);

        return isset($response['status']) && $response['status'] === 'Success';
    }

    /**
     * Get domain status
     */
    public function getServerInfo(Service $service): array
    {
        $properties = $service->properties->pluck('value', 'key')->toArray();
        $domain = $properties['resellerclub_domain'] ?? null;

        if (!$domain) {
            return ['error' => 'Domain not configured'];
        }

        $details = $this->getDomainDetails($domain);

        return [
            'domain' => $domain,
            'status' => $details['status'] ?? 'unknown',
            'expiry' => isset($details['expiry']) ? date('Y-m-d', $details['expiry']) : 'Unknown',
            'nameservers' => implode(', ', $details['nameservers'] ?? []),
            'auto_renew' => $details['auto_renew'] ? 'Yes' : 'No',
            'locked' => $details['locked'] ? 'Yes' : 'No',
        ];
    }

    /**
     * Suspend a domain (enable transfer lock)
     */
    public function suspendServer(Service $service, $settings = [], $properties = []): bool
    {
        $prop = $service->properties->pluck('value', 'key')->toArray();
        $domain = $prop['resellerclub_domain'] ?? null;

        if (!$domain) {
            throw new Exception('Domain not found');
        }

        // Enable transfer lock
        $this->request('/domains/enable-transfer-lock.json', 'POST', [
            'domain-name' => $domain,
        ]);

        return true;
    }

    /**
     * Unsuspend a domain (disable transfer lock)
     */
    public function unsuspendServer(Service $service, $settings = [], $properties = []): bool
    {
        $prop = $service->properties->pluck('value', 'key')->toArray();
        $domain = $prop['resellerclub_domain'] ?? null;

        if (!$domain) {
            throw new Exception('Domain not found');
        }

        // Disable transfer lock
        $this->request('/domains/disable-transfer-lock.json', 'POST', [
            'domain-name' => $domain,
        ]);

        return true;
    }

    /**
     * Terminate/delete a domain (only possible before registration completes)
     */
    public function terminateServer(Service $service, $settings = [], $properties = []): bool
    {
        $prop = $service->properties->pluck('value', 'key')->toArray();
        $orderId = $prop['resellerclub_order_id'] ?? null;

        if (!$orderId) {
            return true; // Nothing to delete
        }

        try {
            // Note: Domains can typically only be deleted within grace period
            $this->request('/domains/delete.json', 'POST', [
                'order-id' => $orderId,
            ]);
        } catch (Exception $e) {
            // Domain deletion may not be possible after registration
            Log::warning('Could not delete domain: ' . $e->getMessage());
        }

        $service->properties()->delete();

        return true;
    }

    /**
     * Upgrade server (not applicable for domains)
     */
    public function upgradeServer(Service $service, $settings = [], $properties = []): bool
    {
        // Domains don't have upgrades in the traditional sense
        // Could handle TLD upgrades here if needed
        return true;
    }

    /**
     * Get available actions for the domain
     */
    public function getActions(Service $service): array
    {
        $properties = $service->properties->pluck('value', 'key')->toArray();
        $domain = $properties['resellerclub_domain'] ?? null;

        if (!$domain) {
            return [];
        }

        return [
            [
                'type' => 'button',
                'label' => 'Manage Domain',
                'url' => 'https://my.resellerclub.com',
                'external' => true,
            ],
            [
                'type' => 'action',
                'action' => 'renew',
                'label' => 'Renew',
                'icon' => 'refresh',
            ],
            [
                'type' => 'action',
                'action' => 'nameservers',
                'label' => 'Update Nameservers',
                'icon' => 'server',
            ],
            [
                'type' => 'action',
                'action' => 'lock',
                'label' => 'Toggle Transfer Lock',
                'icon' => 'lock',
            ],
        ];
    }

    /**
     * Renew action handler
     */
    public function renew(Service $service, int $years = 1): bool
    {
        return $this->renewDomain($service, $years);
    }

    /**
     * Update nameservers action handler
     */
    public function updateNs(Service $service, array $nameservers): bool
    {
        return $this->updateNameservers($service, $nameservers);
    }

    /**
     * Toggle transfer lock
     */
    public function toggleLock(Service $service): bool
    {
        $properties = $service->properties->pluck('value', 'key')->toArray();
        $domain = $properties['resellerclub_domain'] ?? null;

        if (!$domain) {
            throw new Exception('Domain not found');
        }

        $details = $this->getDomainDetails($domain);

        if ($details['locked']) {
            $this->request('/domains/disable-transfer-lock.json', 'POST', [
                'domain-name' => $domain,
            ]);
        } else {
            $this->request('/domains/enable-transfer-lock.json', 'POST', [
                'domain-name' => $domain,
            ]);
        }

        return true;
    }
}
