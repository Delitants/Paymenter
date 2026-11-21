<?php

namespace App\Services\Accounts\Legacy;

use App\Services\BillmanagerMigration\Opening\RuntimeProofFile;

final class LegacyTrustReader
{
    public static function read(string $path, int $readerGid): LegacyTransportTrust
    {
        return LegacyTransportTrust::fromBytes(RuntimeProofFile::read($path, $readerGid, 262144)['bytes']);
    }
}
