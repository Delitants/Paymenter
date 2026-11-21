<?php

namespace App\Services\Providers;

use RuntimeException;
use SimpleXMLElement;

final class XmlResponse
{
    public static function parse(string $body): SimpleXMLElement
    {
        if (strlen($body) > 8388608 || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new RuntimeException('Provider XML response is invalid');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false || $xml->xpath('//error')) {
                throw new RuntimeException('Provider returned an invalid or unsuccessful XML response');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function httpsEndpoint(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Provider requires an explicit HTTPS endpoint without URL credentials');
        }

        return rtrim($url, '/');
    }
}
