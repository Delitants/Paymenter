<?php

namespace App\Services\BillmanagerMigration;

use RuntimeException;

final class TicketMailReference
{
    public const TABLE = 'ticket_mail_references';

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
