<?php

namespace Paymenter\Extensions\Servers\SSLStore;

use phpseclib3\File\ASN1;
use phpseclib3\File\X509;
use RuntimeException;
use Throwable;

final class SSLStoreRenewalRequest
{
    private const CONTACT_FIELDS = ['FirstName', 'LastName', 'Email', 'Phone', 'AddressLine1', 'City', 'Region', 'PostalCode', 'Country'];

    public static function fromOriginal(array $source, string $csr, string $approvalEmail): array
    {
        $primary = self::domain($source['CommonName'] ?? null);
        $sans = $source['DNSNames'] ?? null;
        if (is_string($sans)) {
            $sans = trim($sans) === '' ? [] : explode(',', $sans);
        }
        if (!is_array($sans) || !array_is_list($sans) || count($sans) > 250) {
            throw new RuntimeException('SSLStore did not confirm the names on the original certificate');
        }
        $sans = array_values(array_unique(array_map(self::domain(...), $sans)));
        $csr = trim($csr);
        self::verifyRequest($csr, $primary, $sans);

        $email = $approvalEmail === '' ? ($source['ApproverEmail'] ?? '') : trim($approvalEmail);
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter an SSLStore-approved certificate approval email');
        }
        $webserver = $source['WebServerType'] ?? null;
        if (!is_string($webserver) || trim($webserver) === '' || strlen($webserver) > 120) {
            throw new RuntimeException('SSLStore did not confirm the original certificate server type');
        }
        $request = [
            'CSR' => rawurlencode($csr), 'DomainName' => $primary, 'DNSNames' => $sans, 'WebServerType' => $webserver,
            'ApproverEmail' => $email, 'AdminContact' => self::contact($source['AdminContact'] ?? null),
            'TechnicalContact' => self::contact($source['TechnicalContact'] ?? null),
            'FileAuthDVIndicator' => false, 'HTTPSFileAuthDVIndicator' => false,
        ];
        $organizationId = filter_var($source['TSSOrganizationId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($organizationId !== false) {
            $request['TSSOrganizationId'] = $organizationId;
        }
        if (is_string($source['Organization'] ?? null) && trim($source['Organization']) !== '') {
            $request['OrganizationInfo'] = self::organization($source);
        }

        return $request;
    }

    private static function verifyRequest(string $csr, string $primary, array $sans): void
    {
        if (strlen($csr) > 16384 || !preg_match('/\A-----BEGIN ((?:NEW )?CERTIFICATE REQUEST)-----\r?\n([A-Za-z0-9+\/=\r\n]+)-----END \1-----\z/', $csr, $matches)) {
            throw new RuntimeException('Enter a signed PEM certificate request');
        }
        $request = new X509;
        try {
            $der = base64_decode($matches[2], true);
            $decoded = $der === false ? null : ASN1::decodeBER($der);
            $parsed = isset($decoded[0]['length']) && $decoded[0]['length'] === strlen($der) ? $request->loadCSR($der, X509::FORMAT_DER) : null;
            $valid = is_array($parsed) && $request->validateSignature();
        } catch (Throwable) {
            $valid = false;
        }
        if (!$valid) {
            throw new RuntimeException('The certificate request signature could not be verified');
        }
        $names = $request->getDNProp('id-at-commonName');
        if (!is_array($names) || count($names) !== 1 || self::domain($names[0]) !== $primary) {
            throw new RuntimeException('The certificate request must keep the original primary name');
        }
        $attributes = array_filter($parsed['certificationRequestInfo']['attributes'] ?? [],
            fn (array $attribute) => in_array($attribute['type'], ['pkcs-9-at-extensionRequest', '1.2.840.113549.1.9.14'], true));
        $values = array_values($attributes)[0]['value'] ?? [];
        if (!is_array($values) || count($attributes) > 1 || count($values) > 1 || !is_array($values[0] ?? [])) {
            throw new RuntimeException('The certificate request contains ambiguous extensions');
        }
        $sanExtensions = array_filter($values[0] ?? [], fn (array $extension) => in_array($extension['extnId'], ['id-ce-subjectAltName', '2.5.29.17'], true));
        if (count($sanExtensions) > 1) {
            throw new RuntimeException('The certificate request contains ambiguous names');
        }
        $requestedNames = [$primary];
        $names = array_values($sanExtensions)[0]['extnValue'] ?? [];
        if (!is_array($names) || !array_is_list($names)) {
            throw new RuntimeException('The certificate request contains invalid alternative names');
        }
        foreach ($names as $name) {
            if (!is_array($name) || count($name) !== 1) {
                throw new RuntimeException('The certificate request contains invalid alternative names');
            }
            $requestedNames[] = self::domain($name['dNSName'] ?? null);
        }
        $expectedNames = array_values(array_unique([$primary, ...$sans]));
        $requestedNames = array_values(array_unique($requestedNames));
        sort($expectedNames, SORT_STRING);
        sort($requestedNames, SORT_STRING);
        if ($requestedNames !== $expectedNames) {
            throw new RuntimeException('The certificate request must keep the exact original certificate names');
        }
    }

    private static function domain(mixed $value): string
    {
        $domain = is_string($value) ? strtolower(rtrim(trim($value), '.')) : '';
        $host = str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;
        if (!str_contains($host, '.') || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('The original certificate or CSR contains an unsupported DNS name');
        }

        return $domain;
    }

    private static function contact(mixed $contact): array
    {
        if (!is_array($contact)) {
            throw new RuntimeException('SSLStore did not return the original certificate contact');
        }
        $data = [];
        foreach (self::CONTACT_FIELDS as $field) {
            $value = $contact[$field] ?? null;
            if (!is_string($value) || strlen($value) > 512 || (in_array($field, ['FirstName', 'LastName', 'Email', 'Country'], true) && trim($value) === '')) {
                throw new RuntimeException('SSLStore returned an incomplete certificate contact');
            }
            $data[$field] = $value;
        }
        if (!filter_var($data['Email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SSLStore returned an invalid certificate contact email');
        }

        foreach (['AddressLine2', 'Title', 'OrganizationName', 'Fax'] as $field) {
            if (is_string($contact[$field] ?? null) && strlen($contact[$field]) <= 512) {
                $data[$field] = $contact[$field];
            }
        }

        return $data;
    }

    private static function organization(array $source): array
    {
        $organization = [
            'OrganizationName' => $source['Organization'],
            'DUNS' => $source['DUNS'] ?? '',
            'Division' => $source['OrganizationalUnit'] ?? '',
            'JurisdictionCity' => $source['Locality'] ?? '',
            'JurisdictionCountry' => $source['Country'] ?? '',
            'JurisdictionRegion' => $source['State'] ?? '',
        ];
        $organization['OrganizationAddress'] = [
            'AddressLine1' => $source['OrganizationAddress'] ?? '', 'City' => $organization['JurisdictionCity'],
            'Region' => $organization['JurisdictionRegion'], 'Country' => $organization['JurisdictionCountry'],
            'Phone' => $source['OrganizationPhone'] ?? '', 'PostalCode' => $source['OrganizationPostalcode'] ?? '',
        ];

        return $organization;
    }
}
