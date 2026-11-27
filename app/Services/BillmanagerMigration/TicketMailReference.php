<?php

namespace App\Services\BillmanagerMigration;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TicketMailReference
{
    public const TABLE = 'ticket_mail_references';

    private ?array $archive = null;

    /** @return list<int>|null Native tickets, or null when a matching archive cannot be resolved. */
    public function matchingTickets(array $messageIds): ?array
    {
        $hashes = [];
        foreach ($messageIds as $messageId) {
            if (is_string($messageId) && ($hash = self::messageIdHash($messageId)) !== null) {
                $hashes[$hash] = true;
            }
        }
        if (!$hashes) {
            return [];
        }

        $archive = $this->archive ??= $this->archivedReferences();
        $tickets = [];
        foreach (array_intersect_key($archive, $hashes) as $references) {
            foreach ($references as [$host, $sourceTicket, $account]) {
                $target = DB::table('billmanager_mappings as mapping')
                    ->join('tickets', 'tickets.id', '=', 'mapping.target_id')
                    ->join('billmanager_accounts as accounts', 'accounts.owner_user_id', '=', 'tickets.user_id')
                    ->where('mapping.source_host', $host)
                    ->where('mapping.source_table', 'tickets')
                    ->where('mapping.source_id', $sourceTicket)
                    ->where('mapping.target_table', 'tickets')
                    ->where('accounts.source_account_id', $account)
                    ->value('tickets.id');
                if ($target === null) {
                    return null;
                }
                $tickets[(int) $target] = (int) $target;
            }
        }

        return array_values($tickets);
    }

    private function archivedReferences(): array
    {
        $index = [];
        $records = DB::table('billmanager_records as archive')
            ->join('billmanager_imports as imports', 'imports.id', '=', 'archive.import_id')
            ->where('archive.source_table', self::TABLE)
            ->where('archive.source_account_id', '>', 0)
            ->select('archive.payload', 'archive.payload_sha256', 'archive.source_account_id', 'imports.source_host')
            ->cursor();
        foreach ($records as $record) {
            $raw = Crypt::decryptString($record->payload);
            if (!hash_equals($record->payload_sha256, hash('sha256', $raw))) {
                throw new RuntimeException('Archived ticket mail reference failed verification');
            }
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $messageId = $payload['email_message_id'] ?? null;
            $ticket = $payload['source_ticket_id'] ?? null;
            $hash = is_string($messageId) ? self::messageIdHash($messageId) : null;
            if ($hash === null) {
                continue;
            }
            if ($hash !== ($payload['message_id_sha256'] ?? null) || !is_string($ticket) || !preg_match('/\A[1-9][0-9]*\z/D', $ticket)) {
                throw new RuntimeException('Archived ticket mail reference is inconsistent');
            }
            $index[$hash][] = [$record->source_host, $ticket, (int) $record->source_account_id];
        }

        return $index;
    }

    public static function payload(array $row): ?array
    {
        $messageId = $row['email_message_id'] ?? null;
        if ($messageId === null || $messageId === '') {
            return null;
        }
        if (!is_string($messageId) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $row['ticket'])) {
            throw new RuntimeException('Invalid source ticket mail reference');
        }

        return [
            'source_ticket_id' => (string) $row['ticket'],
            'email_message_id' => $messageId,
            'message_id_sha256' => self::messageIdHash($messageId),
        ];
    }

    private static function messageIdHash(string $messageId): ?string
    {
        $token = trim($messageId, " \r\n\t");
        if (str_starts_with($token, '<') && str_ends_with($token, '>')) {
            $token = substr($token, 1, -1);
        }
        if (strlen($token) > 998 || !preg_match('/\A([^\x00-\x20\x7f-\xff<>@]+)@([A-Za-z0-9.-]+)\z/D', $token, $matches)) {
            return null;
        }

        return hash('sha256', $matches[1] . '@' . strtolower($matches[2]));
    }
}
