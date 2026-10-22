<?php

namespace App\Services\BillmanagerMigration\Opening;

use RuntimeException;

final class CanonicalPolicy
{
    public static function bytes(array $policy): string
    {
        return json_encode(self::sort($policy), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sort(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_float($item) || (is_string($key) && StrictProofJson::monetaryKey($key) && is_int($item))) {
                throw new RuntimeException('Canonical financial values must be decimal strings.');
            }
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            } elseif (is_object($item) || is_resource($item)) {
                throw new RuntimeException('Canonical policy requires JSON values.');
            }
        }

        return $value;
    }
}
