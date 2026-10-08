<?php

namespace Paymenter\Extensions\Gateways\Klarna;

use RuntimeException;

final class Markets
{
    // Public provider country/currency mapping, not a merchant enablement list.
    // https://docs.klarna.com/acquirer/klarna/get-started/data-requirements/puchase-countries-currencies-locales/
    private const COUNTRIES = [
        'AU' => ['Australia', 'AUD'], 'AT' => ['Austria', 'EUR'], 'BE' => ['Belgium', 'EUR'],
        'CA' => ['Canada', 'CAD'], 'CZ' => ['Czech Republic', 'CZK'], 'DK' => ['Denmark', 'DKK'],
        'FI' => ['Finland', 'EUR'], 'FR' => ['France', 'EUR'], 'DE' => ['Germany', 'EUR'],
        'GR' => ['Greece', 'EUR'], 'HU' => ['Hungary', 'HUF'], 'IE' => ['Ireland', 'EUR'],
        'IT' => ['Italy', 'EUR'], 'MX' => ['Mexico', 'MXN'], 'NL' => ['Netherlands', 'EUR'],
        'NZ' => ['New Zealand', 'NZD'], 'NO' => ['Norway', 'NOK'], 'PL' => ['Poland', 'PLN'],
        'PT' => ['Portugal', 'EUR'], 'RO' => ['Romania', 'RON'], 'SK' => ['Slovakia', 'EUR'],
        'ES' => ['Spain', 'EUR'], 'SE' => ['Sweden', 'SEK'], 'CH' => ['Switzerland', 'CHF'],
        'GB' => ['United Kingdom', 'GBP'], 'US' => ['United States', 'USD'],
    ];

    public static function currency(string $country): string
    {
        if (!isset(self::COUNTRIES[$country])) {
            throw new RuntimeException('Unsupported Klarna purchase country.');
        }

        return self::COUNTRIES[$country][1];
    }

    public function enabled(string $countries, string $settlementCurrency): array
    {
        if ($settlementCurrency !== 'USD' || trim($countries) === '') {
            throw new RuntimeException('Consumer FX requires USD settlement and enabled purchase countries.');
        }
        $result = [];
        foreach (explode(',', $countries) as $country) {
            $country = strtoupper(trim($country));
            if (!isset(self::COUNTRIES[$country])) {
                throw new RuntimeException('Consumer FX contains an unsupported purchase country.');
            }
            [$name, $currency] = self::COUNTRIES[$country];
            $result[$country] = ['name' => $name, 'billing_currency' => $currency, 'locale' => 'en-' . $country];
        }

        return $result;
    }
}
