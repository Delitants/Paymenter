<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Livewire\Tickets\Show;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\BillmanagerMigration\CustomerImporter;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\MigrationHeldException;
use App\Services\BillmanagerMigration\Snapshot;
use App\Services\BillmanagerMigration\TicketImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $timezone = 'UTC', ?string $drift = null): array
    {
        $tables = [
            'accounts' => [['id' => '10']],
            'users' => [['id' => '11', 'account' => '10', 'level' => '16', 'enabled' => 'on',
                'email' => 'client@example.test', 'realname' => 'Test Client', 'last_login' => '2025-01-01 00:00:00']],
            'tickets' => array_map(fn ($id, $status) => [
                'id' => (string) $id, 'account_client' => '10', 'name' => 'Historical ticket ' . $id,
                'status' => (string) $status, 'priority' => '0', 'date_start' => '2020-01-01 12:00:00',
                'date_last' => '2021-02-03 14:00:00', 'item' => null,
            ], [21, 22, 23], [1, 2, 10]),
            'ticket_authors' => [['id' => '99', 'realname' => 'Historical Operator', 'level' => '29']],
            'ticket_messages' => [
                ['id' => '31', 'ticket' => '23', 'user' => '99', 'message' => str_repeat('x', 101733), 'date_post' => '2021-02-03 13:00:00', 'date_delete' => null],
                ['id' => '32', 'ticket' => '23', 'user' => '11', 'message' => '<script>alert("synthetic")</script> Привіт', 'date_post' => '2021-02-03 14:00:00', 'date_delete' => null],
                ['id' => '33', 'ticket' => '23', 'user' => '11', 'message' => 'Deleted private content', 'date_post' => '2021-02-03 12:30:00', 'date_delete' => '2021-02-03 12:45:00'],
            ],
            'ticket_notes' => [['id' => '41', 'ticket' => '23', 'user' => '99', 'note' => 'Internal staff note', 'date_post' => '2021-02-03 14:01:00']],
            'ticket_history' => [['id' => '51', 'ticket' => '23', 'user' => '99', 'type' => '5', 'old_value' => '2', 'new_value' => '10', 'visible_by_client' => 'off']],
        ];
        if ($drift === 'deleted') {
            $tables['ticket_messages'][0]['date_delete'] = '2026-01-02 00:00:00';
        } elseif ($drift === 'changed') {
            $tables['ticket_messages'][0]['message'] = 'Changed source content';
        } elseif ($drift === 'missing') {
            array_shift($tables['ticket_messages']);
        }
        $path = tempnam(sys_get_temp_dir(), 'ticket-snapshot-');
        file_put_contents($path, json_encode(['schema_version' => 1, 'source_host' => '192.0.2.10',
            'login_cutoff' => '2024-01-01 00:00:00', 'captured_at_utc' => '2026-01-01T00:00:00Z', 'source_timezone' => $timezone, 'tables' => $tables]));
        file_put_contents($path . '.sha256', hash_file('sha256', $path));
        try {
            $snapshot = Snapshot::load($path, '192.0.2.10', '2024-01-01 00:00:00');
        } finally {
            unlink($path);
            unlink($path . '.sha256');
        }
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => $snapshot->checksum(), 'status' => 'running']);
        $context = new ImportContext($id, '192.0.2.10');
        (new CustomerImporter)->import($snapshot, $context);

        return [$snapshot, $context];
    }

    public function test_refreshed_snapshot_rejects_changed_deleted_or_missing_mapped_messages(): void
    {
        [$snapshot, $context] = $this->source();
        (new TicketImporter)->import($snapshot, $context);
        foreach (['changed', 'deleted', 'missing'] as $drift) {
            [$refresh, $next] = $this->source('UTC', $drift);
            $blocked = false;
            try {
                (new TicketImporter)->import($refresh, $next);
            } catch (\RuntimeException $e) {
                $blocked = str_contains($e->getMessage(), 'reconciliation');
            }
            $this->assertTrue($blocked, $drift);
            $this->assertSame(0, DB::table('billmanager_records')->where('import_id', $next->importId)->where('source_table', 'ticket_messages')->count());
            $this->assertSame(2, TicketMessage::count());
        }
    }

    public function test_native_tickets_preserve_states_dates_long_text_and_safe_author_attribution(): void
    {
        [$snapshot, $context] = $this->source();
        Bus::fake();
        $importer = new TicketImporter;
        $importer->import($snapshot, $context);
        $importer->import($snapshot, $context);
        $this->assertSame(['open', 'replied', 'closed'], Ticket::orderBy('id')->pluck('status')->all());
        $this->assertSame(2, TicketMessage::count());
        $this->assertSame(1, User::count());
        $message = TicketMessage::findOrFail($context->mappedId('ticket_messages', '31'));
        $this->assertSame(101733, strlen($message->message));
        $this->assertSame('Historical Operator', $message->author_name);
        $this->assertNull($message->user_id);
        $this->assertSame('2021-02-03 13:00:00', $message->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, DB::table('billmanager_records')->where('source_table', 'ticket_messages')->count());
        $this->assertSame(1, DB::table('billmanager_records')->where('source_table', 'ticket_notes')->count());
        $this->assertSame(1, DB::table('billmanager_records')->where('source_table', 'ticket_history')->count());
        Bus::assertNothingDispatched();
    }

    public function test_customer_can_read_archived_visible_messages_without_deleted_content_or_staff_notes(): void
    {
        [$snapshot, $context] = $this->source();
        (new TicketImporter)->import($snapshot, $context);
        $ticket = Ticket::findOrFail($context->mappedId('tickets', '23'));
        $this->actingAs(User::findOrFail($ticket->user_id));
        $html = Livewire::test(Show::class, ['ticket' => $ticket])->html();
        $this->assertStringContainsString('Historical Operator', $html);
        $this->assertStringContainsString('Привіт', $html);
        $this->assertStringNotContainsString('<script>alert("synthetic")</script>', $html);
        $this->assertStringNotContainsString('Deleted private content', $html);
        $this->assertStringNotContainsString('Internal staff note', $html);
    }

    public function test_source_local_timestamps_are_converted_to_utc_without_losing_original_archive_values(): void
    {
        [$snapshot, $context] = $this->source('America/New_York');
        (new TicketImporter)->import($snapshot, $context);
        $message = TicketMessage::findOrFail($context->mappedId('ticket_messages', '31'));
        $this->assertSame('2021-02-03 18:00:00', $message->created_at->format('Y-m-d H:i:s'));
        $record = DB::table('billmanager_records')->where('source_table', 'ticket_messages')->where('source_id', '31')->first();
        $row = json_decode(Crypt::decryptString($record->payload), true);
        $this->assertSame('2021-02-03 13:00:00', $row['date_post']);
    }

    public function test_imported_ticket_cannot_send_a_reply_before_support_handover(): void
    {
        [$snapshot, $context] = $this->source();
        (new TicketImporter)->import($snapshot, $context);
        $ticket = Ticket::findOrFail($context->mappedId('tickets', '21'));
        Bus::fake();
        try {
            $ticket->messages()->create(['user_id' => $ticket->user_id, 'message' => 'Synthetic reply']);
            $this->fail('Imported ticket accepted a live reply before handover');
        } catch (MigrationHeldException) {
            $this->assertSame(0, $ticket->messages()->count());
            Bus::assertNothingDispatched();
        }
    }
}
