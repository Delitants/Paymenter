<?php

namespace Tests\Fixtures\LegacyReceipts;

final class GoldenVectors
{
    public static function vectors(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/identity-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function identity(): array
    {
        return self::vectors()[0]['identity'];
    }

    public static function economic(): array
    {
        return self::vectors()[0]['economic'];
    }
}
