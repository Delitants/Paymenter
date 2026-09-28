<?php

namespace App\Services\BillmanagerMigration;

use InvalidArgumentException;

final class Snapshot
{
    private function __construct(private readonly array $data) {}

    public static function load(string $path, string $expectedSource, string $expectedCutoff): self
    {
        $payload = file_get_contents($path);
        $digest = trim(file_get_contents($path . '.sha256'));
        if (!preg_match('/^[a-f0-9]{64}$/', $digest) || !hash_equals($digest, hash('sha256', $payload))) {
            throw new InvalidArgumentException('Snapshot checksum mismatch');
        }
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema_version'] ?? null) !== 1 || ($data['source_host'] ?? null) !== $expectedSource) {
            throw new InvalidArgumentException('Unsupported snapshot source or schema');
        }
        if (($data['login_cutoff'] ?? null) !== $expectedCutoff || !is_array($data['tables'] ?? null)) {
            throw new InvalidArgumentException('Invalid snapshot cutoff or tables');
        }
        foreach ($data['tables'] as $table => $rows) {
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new InvalidArgumentException('Invalid table rows');
            }
            $seen = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('Invalid record');
                }
                if (isset($row['id'])) {
                    $id = (string) $row['id'];
                    if (isset($seen[$id])) {
                        throw new InvalidArgumentException('Duplicate source identity in ' . $table);
                    }
                    $seen[$id] = true;
                }
            }
        }
        $accounts = array_fill_keys(array_column($data['tables']['accounts'] ?? [], 'id'), true);
        if (!$accounts || empty($data['tables']['users'])) {
            throw new InvalidArgumentException('Snapshot has no selected accounts or users');
        }
        foreach (['users', 'items'] as $table) {
            foreach ($data['tables'][$table] ?? [] as $row) {
                if (!isset($accounts[$row['account'] ?? ''])) {
                    throw new InvalidArgumentException('Unknown account in ' . $table);
                }
                if ($table === 'users' && (($row['enabled'] ?? '') !== 'on' || ($row['last_login'] ?? '') < $data['login_cutoff'])) {
                    throw new InvalidArgumentException('User outside approved login cutoff');
                }
            }
        }

        return new self($data);
    }

    public function rows(string $table): array
    {
        return $this->data['tables'][$table] ?? [];
    }

    public function counts(): array
    {
        return array_map('count', $this->data['tables']);
    }
}
