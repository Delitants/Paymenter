<?php

namespace App\Services\Gateways\Operations;

use RuntimeException;
use Throwable;

/** Preserve decimal JSON lexemes before they can become binary floating-point money. */
final class ProviderJson
{
    public static function decode(string $body): array
    {
        try {
            $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
            if (strlen($body) > 1048576) {
                throw new RuntimeException;
            }
            // Validate the original grammar first; the decoded floats are never used.
            json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            $quoted = false;
            $escaped = false;
            $output = '';
            for ($i = 0, $length = strlen($body); $i < $length; $i++) {
                $char = $body[$i];
                if ($quoted) {
                    $output .= $char;
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $quoted = false;
                    }

                    continue;
                }
                if ($char === '"') {
                    $quoted = true;
                    $output .= $char;

                    continue;
                }
                if ($char === '-' || ($char >= '0' && $char <= '9')) {
                    $start = $i;
                    while ($i + 1 < $length && str_contains('0123456789.eE+-', $body[$i + 1])) {
                        $i++;
                    }
                    $token = substr($body, $start, $i - $start + 1);
                    $output .= strpbrk($token, '.eE') === false ? $token : json_encode($token, JSON_THROW_ON_ERROR);

                    continue;
                }
                $output .= $char;
            }
            $decoded = json_decode($output, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_array($decoded)) {
                throw new RuntimeException;
            }

            return $decoded;
        } catch (Throwable) {
            throw new RuntimeException('Provider JSON could not be verified.');
        }
    }
}
