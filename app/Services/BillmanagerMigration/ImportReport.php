<?php

namespace App\Services\BillmanagerMigration;

final class ImportReport implements \JsonSerializable
{
    public function __construct(
        public readonly string $status,
        public readonly array $counts,
        public readonly array $conflicts = [],
        public readonly array $totals = [],
    ) {}

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
