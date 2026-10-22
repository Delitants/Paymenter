<?php

namespace Tests\Fixtures\LegacyReceipts {
    /** One-shot test barrier around a real ordinary descriptor; no substituted I/O. */
    final class ProofReadBarrier
    {
        public static ?string $path = null;

        public static ?\Closure $action = null;

        public static function before($stream): void
        {
            if (self::$action !== null && (stream_get_meta_data($stream)['uri'] ?? null) === self::$path) {
                $action = self::$action;
                self::$action = null;
                $action();
            }
        }
    }
}

namespace App\Services\BillmanagerMigration\Opening {
    use Tests\Fixtures\LegacyReceipts\ProofReadBarrier;

    function stream_get_contents($stream, ?int $length = null, int $offset = -1): string|false
    {
        ProofReadBarrier::before($stream);

        return \stream_get_contents($stream, $length, $offset);
    }
}
