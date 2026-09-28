<?php

namespace App\Services\BillmanagerMigration;

use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TicketImporter
{
    public function import(Snapshot $snapshot, ImportContext $context): ImportReport
    {
        if ($snapshot->sourceHost() !== $context->sourceHost) {
            throw new RuntimeException('Snapshot source does not match import context');
        }
        $timezone = $snapshot->sourceTimezone() ?? config('billmanager-migration.source_timezone');
        if (!$timezone || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException('An explicit source timezone is required for historical timestamps');
        }
        $date = static fn (string $value) => Carbon::parse($value, $timezone)->utc()->format('Y-m-d H:i:s');

        return DB::transaction(function () use ($snapshot, $context, $date) {
            $ticketAccounts = [];
            foreach ($snapshot->rows('tickets') as $row) {
                $account = $context->mappedId('accounts', (string) $row['account_client']);
                $owner = DB::table('billmanager_accounts')->where('id', $account)->value('owner_user_id');
                if (!$owner) {
                    throw new RuntimeException('Ticket account has no imported owner');
                }
                $ticketAccounts[$row['id']] = (int) $row['account_client'];
                $id = $context->mappedId('tickets', (string) $row['id']);
                if ($id === null) {
                    $status = match ((int) $row['status']) {
                        0, 1 => 'open', 2 => 'replied', 3, 10 => 'closed',
                        default => throw new RuntimeException('Unknown source ticket status'),
                    };
                    $priority = match ((int) $row['priority']) {
                        0 => 'low', 1 => 'medium', 2 => 'high',
                        default => throw new RuntimeException('Unknown source ticket priority'),
                    };
                    $id = DB::table('tickets')->insertGetId([
                        'user_id' => $owner, 'subject' => $row['name'], 'legacy_reference' => (string) $row['id'],
                        'status' => $status, 'priority' => $priority,
                        'service_id' => empty($row['item']) ? null : $context->mappedId('items', (string) $row['item']),
                        'created_at' => $date($row['date_start']), 'updated_at' => $date($row['date_last']),
                    ]);
                    $context->recordMapping('tickets', (string) $row['id'], 'tickets', $id);
                    DB::table('billmanager_holds')->insert(['model_type' => Ticket::class, 'model_id' => $id, 'reason' => 'Awaiting support handover']);
                } elseif (!DB::table('tickets')->where('id', $id)->where('user_id', $owner)->exists()) {
                    throw new RuntimeException('Mapped ticket owner changed');
                }
                $context->archive('tickets', (string) $row['id'], $row, (int) $row['account_client']);
            }
            $authors = array_replace(array_column($snapshot->rows('users'), null, 'id'), array_column($snapshot->rows('ticket_authors'), null, 'id'));
            foreach ($snapshot->rows('ticket_authors') as $author) {
                $context->archive('ticket_authors', (string) $author['id'], $author);
            }
            $visible = 0;
            $deleted = 0;
            foreach ($snapshot->rows('ticket_messages') as $row) {
                $account = $ticketAccounts[$row['ticket']] ?? throw new RuntimeException('Message has no selected ticket');
                $context->archive('ticket_messages', (string) $row['id'], $row, $account);
                if (!empty($row['user_delete']) || (!empty($row['date_delete']) && !str_starts_with($row['date_delete'], '0000-'))) {
                    $deleted++;

                    continue;
                }
                $visible++;
                if ($context->mappedId('ticket_messages', (string) $row['id']) !== null) {
                    continue;
                }
                $author = $authors[$row['user']] ?? null;
                $id = DB::table('ticket_messages')->insertGetId([
                    'ticket_id' => $context->mappedId('tickets', (string) $row['ticket']),
                    'user_id' => empty($row['user']) ? null : $context->mappedId('users', (string) $row['user']),
                    'message' => $row['message'],
                    'legacy_author' => json_encode([
                        'source_user_id' => $row['user'], 'name' => $author['realname'] ?? 'Former user',
                        'source_level' => $author['level'] ?? null,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => $date($row['date_post']), 'updated_at' => $date($row['date_post']),
                ]);
                $context->recordMapping('ticket_messages', (string) $row['id'], 'ticket_messages', $id);
            }
            foreach (['ticket_notes', 'ticket_history'] as $table) {
                foreach ($snapshot->rows($table) as $row) {
                    $account = $ticketAccounts[$row['ticket']] ?? throw new RuntimeException('Archive row has no selected ticket');
                    $context->archive($table, (string) $row['id'], $row, $account);
                }
            }

            return new ImportReport('tickets_imported', [
                'tickets' => count($ticketAccounts), 'visible_messages' => $visible, 'deleted_messages_archived' => $deleted,
                'notes_archived' => count($snapshot->rows('ticket_notes')), 'history_archived' => count($snapshot->rows('ticket_history')),
            ]);
        });
    }
}
