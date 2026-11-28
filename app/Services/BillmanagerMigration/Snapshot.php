<?php

namespace App\Services\BillmanagerMigration;

use DateTimeImmutable;
use InvalidArgumentException;

final class Snapshot
{
    private function __construct(private readonly array $data, private readonly string $digest) {}

    public static function load(string $path, string $expectedSource, string $expectedCutoff, bool $activityScope = false): self
    {
        return self::fromBytes(file_get_contents($path), trim(file_get_contents($path . '.sha256')), $expectedSource, $expectedCutoff, $activityScope);
    }

    public static function fromBytes(string $payload, string $digest, string $expectedSource, string $expectedCutoff, bool $activityScope = false): self
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $digest) || !hash_equals($digest, hash('sha256', $payload))) {
            throw new InvalidArgumentException('Snapshot checksum mismatch');
        }
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema_version'] ?? null) !== ($activityScope ? 2 : 1) || ($data['source_host'] ?? null) !== $expectedSource) {
            throw new InvalidArgumentException('Unsupported snapshot source or schema');
        }
        $cutoffField = $activityScope ? 'activity_cutoff' : 'login_cutoff';
        if (($data[$cutoffField] ?? null) !== $expectedCutoff || !is_array($data['tables'] ?? null)) {
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
        $ownedTables = $activityScope ? ['users', 'items', 'historical_items'] : ['users', 'items'];
        foreach ($ownedTables as $table) {
            foreach ($data['tables'][$table] ?? [] as $row) {
                if (!isset($accounts[$row['account'] ?? ''])) {
                    throw new InvalidArgumentException('Unknown account in ' . $table);
                }
                if (!$activityScope && $table === 'users' && (($row['enabled'] ?? '') !== 'on' || ($row['last_login'] ?? '') < $expectedCutoff)) {
                    throw new InvalidArgumentException('User outside approved login cutoff');
                }
            }
        }
        if ($activityScope) {
            self::validateActivityScope($data, $accounts, $expectedCutoff);
        }

        return new self($data, $digest);
    }

    private static function validateActivityScope(array $data, array $accounts, string $cutoff): void
    {
        $boundary = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $cutoff);
        if (($data['consistent_snapshot'] ?? false) !== true || !$boundary || $boundary->format('Y-m-d H:i:s') !== $cutoff) {
            throw new InvalidArgumentException('Invalid activity cutoff or source snapshot');
        }
        $tables = $data['tables'];
        $recent = static function (mixed ...$dates) use ($cutoff): bool {
            foreach ($dates as $date) {
                if (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?)?$/D', $date, $parts)
                    && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                    $timestamp = strlen($date) === 10 ? $date . ' 00:00:00' : $date;
                    if ($timestamp >= $cutoff) {
                        return true;
                    }
                }
            }

            return false;
        };
        $eligible = [];
        foreach ($tables['accounts'] as $account) {
            if ($recent($account['registration_date'] ?? null)) {
                $eligible[$account['id']] = true;
            }
        }
        $members = [];
        foreach ($tables['users'] as $user) {
            if ((string) ($user['level'] ?? '') !== '16' || !in_array($user['enabled'] ?? null, ['on', 'off'], true)) {
                throw new InvalidArgumentException('Invalid customer identity in activity snapshot');
            }
            $members[$user['account']] = true;
            if ($recent($user['last_login'] ?? null)) {
                $eligible[$user['account']] = true;
            }
        }
        foreach (['items', 'historical_items'] as $table) {
            foreach ($tables[$table] ?? [] as $item) {
                $current = in_array((string) ($item['status'] ?? ''), ['1', '2', '3', '5'], true);
                if ($current !== ($table === 'items')) {
                    throw new InvalidArgumentException('Service status does not match activity snapshot table');
                }
                if ($current || $recent($item['createdate'] ?? null, $item['updatedate'] ?? null, $item['opendate'] ?? null,
                    $item['expiredate'] ?? null, $item['suspenddate'] ?? null)) {
                    $eligible[$item['account']] = true;
                }
            }
        }
        $walletOwners = array_column($tables['subaccounts'] ?? [], 'account', 'id');
        $paymentOwners = [];
        foreach ($tables['payments'] ?? [] as $payment) {
            $owner = $walletOwners[$payment['subaccount'] ?? ''] ?? null;
            if ($owner === null || !isset($accounts[$owner])) {
                throw new InvalidArgumentException('Unknown payment owner in activity snapshot');
            }
            $paymentOwners[$payment['id']] = $owner;
            if ($recent($payment['createdate'] ?? null, $payment['paydate'] ?? null)) {
                $eligible[$owner] = true;
            }
        }
        foreach ($tables['expenses'] ?? [] as $expense) {
            if ($recent($expense['cdate'] ?? null, $expense['realdate'] ?? null)) {
                $owner = $walletOwners[$expense['subaccount'] ?? ''] ?? null;
                if ($owner !== null) {
                    $eligible[$owner] = true;
                }
            }
        }
        $profileOwners = array_column($tables['profiles'] ?? [], 'account', 'id');
        $invoiceOwners = [];
        foreach ($tables['invoices'] ?? [] as $invoice) {
            $owner = $profileOwners[$invoice['customer'] ?? ''] ?? null;
            if ($owner !== null) {
                $invoiceOwners[$invoice['id']] = $owner;
                if ($recent($invoice['cdate'] ?? null, $invoice['sdate'] ?? null)) {
                    $eligible[$owner] = true;
                }
            }
        }
        foreach (['payment_history' => $paymentOwners, 'invoice_history' => $invoiceOwners] as $table => $owners) {
            foreach ($tables[$table] ?? [] as $row) {
                $owner = $owners[$row['reference'] ?? ''] ?? null;
                if ($owner !== null && $recent($row['changedate'] ?? null)) {
                    $eligible[$owner] = true;
                }
            }
        }
        $ticketOwners = array_column($tables['tickets'] ?? [], 'account_client', 'id');
        foreach ($tables['tickets'] ?? [] as $ticket) {
            if ($recent($ticket['date_start'] ?? null, $ticket['date_last'] ?? null)) {
                $eligible[$ticket['account_client']] = true;
            }
        }
        foreach (['ticket_messages', 'ticket_history'] as $table) {
            foreach ($tables[$table] ?? [] as $row) {
                $owner = $ticketOwners[$row['ticket'] ?? ''] ?? null;
                if ($owner !== null && $recent($row['date_post'] ?? null)) {
                    $eligible[$owner] = true;
                }
            }
        }
        if (array_diff_key($accounts, $eligible) || array_diff_key($accounts, $members)) {
            throw new InvalidArgumentException('Account outside approved activity cutoff or missing customer identity');
        }
    }

    public function hasActivityScope(): bool
    {
        return $this->data['schema_version'] === 2;
    }

    public function rows(string $table): array
    {
        return $this->data['tables'][$table] ?? [];
    }

    public function sourceHost(): string
    {
        return $this->data['source_host'];
    }

    public function capturedAt(): string
    {
        return $this->data['captured_at_utc'];
    }

    public function checksum(): string
    {
        return $this->digest;
    }

    public function sourceTimezone(): ?string
    {
        return $this->data['source_timezone'] ?? null;
    }

    public function counts(): array
    {
        return array_map('count', $this->data['tables']);
    }
}
