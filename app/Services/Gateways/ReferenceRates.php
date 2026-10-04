<?php

namespace App\Services\Gateways;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class ReferenceRates
{
    public function get(string $currency): array
    {
        if ($currency === 'USD') {
            return ['source' => 'identity', 'date' => now()->utc()->format('Y-m-d'), 'numerator' => '1', 'denominator' => '1'];
        }
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new RuntimeException('The checkout currency is unavailable.');
        }
        try {
            $data = Cache::remember('gateways.ecb.reference-rates.v1', 3600, function () {
                $response = Http::withoutRedirecting()->connectTimeout(10)->timeout(20)
                    ->get('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml');
                if (!$response->successful() || strlen($response->body()) > 65536 || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $response->body())) {
                    throw new RuntimeException;
                }
                $previous = libxml_use_internal_errors(true);
                try {
                    $xml = simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
                    if (!$xml || $xml->getName() !== 'Envelope') {
                        throw new RuntimeException;
                    }
                    $dates = $xml->xpath('//*[local-name()="Cube" and @time]');
                    if (count($dates) !== 1) {
                        throw new RuntimeException;
                    }
                    $rates = ['EUR' => '1'];
                    foreach ($dates[0]->xpath('./*[local-name()="Cube"]') as $row) {
                        $code = (string) $row['currency'];
                        $rate = (string) $row['rate'];
                        if (!preg_match('/^[A-Z]{3}$/D', $code) || isset($rates[$code]) || !preg_match('/^[0-9]+(?:\.[0-9]{1,12})?$/D', $rate) ||
                            !BigDecimal::of($rate)->isPositive()) {
                            throw new RuntimeException;
                        }
                        $rates[$code] = $rate;
                    }

                    return ['date' => (string) $dates[0]['time'], 'rates' => $rates];
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
            });
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['date'], 'UTC');
            $today = CarbonImmutable::now('UTC')->startOfDay();
            if ($date->format('Y-m-d') !== $data['date'] || $date->isAfter($today) || $date->isBefore($today->subDays(4)) ||
                !isset($data['rates']['USD'], $data['rates'][$currency])) {
                throw new RuntimeException;
            }

            return ['source' => 'ECB', 'date' => $data['date'], 'numerator' => $data['rates'][$currency], 'denominator' => $data['rates']['USD']];
        } catch (Throwable) {
            throw new RuntimeException('A current exchange rate is unavailable. No payment has been started.');
        }
    }
}
