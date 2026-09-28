<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\BillmanagerAttachment;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\User;
use App\Services\BillmanagerMigration\AttachmentImporter;
use App\Services\BillmanagerMigration\ImportContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $root = sys_get_temp_dir() . '/bill-attachments-' . bin2hex(random_bytes(8));
        mkdir($root . '/source/10', 0700, true);
        mkdir($root . '/destination', 0700, true);
        $this->directories[] = $root;
        $user = User::factory()->create();
        $ticket = Ticket::withoutEvents(fn () => Ticket::create(['user_id' => $user->id, 'subject' => 'Synthetic', 'status' => 'closed']));
        $message = TicketMessage::withoutEvents(fn () => $ticket->messages()->create(['user_id' => $user->id, 'message' => 'Synthetic']));
        $id = DB::table('billmanager_imports')->insertGetId(['source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('a', 64), 'status' => 'running']);
        $context = new ImportContext($id, '192.0.2.10');
        $context->recordMapping('ticket_messages', '31', 'ticket_messages', $message->id);
        $context->archive('ticket_messages', '31', ['id' => '31', 'date_delete' => null], 10);
        $context->archive('ticket_messages', '32', ['id' => '32', 'date_delete' => '2020-01-01 00:00:00'], 10);
        $manifest = [];
        foreach ([41 => 'visible', 42 => 'deleted'] as $attachmentId => $text) {
            $path = '10/' . $attachmentId . '.txt';
            file_put_contents($root . '/source/' . $path, $text);
            $manifest[] = ['id' => (string) $attachmentId, 'account' => '10', 'path' => $path,
                'filename' => 'файл-' . $attachmentId . '.txt', 'ticket_message' => $attachmentId === 41 ? '31' : '32',
                'size' => strlen($text), 'sha256' => hash('sha256', $text)];
        }
        file_put_contents($root . '/manifest.json', json_encode($manifest));

        return [$root, $context, $user];
    }

    public function test_visible_and_deleted_attachments_survive_without_leaking_deleted_files_to_customers(): void
    {
        [$root, $context, $user] = $this->fixture();
        $importer = new AttachmentImporter($root . '/destination');
        $importer->import($root . '/manifest.json', $root . '/source', $context);
        $importer->import($root . '/manifest.json', $root . '/source', $context);
        $this->assertSame(1, TicketMessageAttachment::count());
        $this->assertSame(2, BillmanagerAttachment::count());
        foreach (BillmanagerAttachment::all() as $attachment) {
            $this->assertSame($attachment->sha256, hash_file('sha256', $root . '/destination/' . $attachment->path));
        }
        $this->assertSame('файл-41.txt', TicketMessageAttachment::sole()->filename);
        $deleted = BillmanagerAttachment::where('deleted_message', true)->firstOrFail();
        $this->assertFalse(Gate::forUser($user)->allows('view', $deleted));
        $this->actingAs($user)->withSession($this->loginUser($user))->get(route('billmanager.attachments.show', $deleted))->assertNotFound();
        $supportRole = Role::create(['name' => 'Archive support', 'permissions' => ['admin.tickets.view']]);
        $this->assertTrue(Gate::forUser(User::factory()->create(['role_id' => $supportRole->id]))->allows('view', $deleted));
        $this->assertTrue(Gate::forUser($user)->allows('view', TicketMessageAttachment::sole()));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('view', TicketMessageAttachment::sole()));
    }

    public function test_changed_attachment_is_rejected_without_a_partial_import(): void
    {
        [$root, $context] = $this->fixture();
        file_put_contents($root . '/source/10/42.txt', 'changed');
        try {
            (new AttachmentImporter($root . '/destination'))->import($root . '/manifest.json', $root . '/source', $context);
            $this->fail('Changed file accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
            $this->assertSame(0, TicketMessageAttachment::count());
            $this->assertSame(0, BillmanagerAttachment::count());
            $this->assertSame([], File::allFiles($root . '/destination'));
        }
    }

    public function test_cross_account_file_path_is_rejected(): void
    {
        [$root, $context] = $this->fixture();
        mkdir($root . '/source/20', 0700);
        file_put_contents($root . '/source/20/other.txt', 'visible');
        $manifest = json_decode(file_get_contents($root . '/manifest.json'), true);
        $manifest[0]['path'] = '10/../20/other.txt';
        file_put_contents($root . '/manifest.json', json_encode($manifest));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('account directory');
        (new AttachmentImporter($root . '/destination'))->import($root . '/manifest.json', $root . '/source', $context);
    }
}
