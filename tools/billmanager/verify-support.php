<?php

use App\Models\BillmanagerAttachment;
use App\Models\BillmanagerRecord;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    // Read-only reconciliation. Run from the isolated application directory.
    require getcwd() . '/vendor/autoload.php';
    $app = require getcwd() . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $snapshot = Snapshot::load($argv[1], $argv[2], $argv[3]);
    $batch = DB::table('billmanager_imports')->where('snapshot_sha256', $snapshot->checksum())->sole();
    $context = new ImportContext($batch->id, $snapshot->sourceHost());
    $check = static function ($condition, $message) {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };
    $counts = ['tickets' => 0, 'visible_messages' => 0, 'deleted_messages' => 0, 'attachment_hashes' => 0];
    foreach ($snapshot->rows('tickets') as $row) {
        $ticket = Ticket::findOrFail($context->mappedId('tickets', (string) $row['id']));
        $owner = DB::table('billmanager_accounts')->where('id', $context->mappedId('accounts', (string) $row['account_client']))->value('owner_user_id');
        $check($ticket->user_id == $owner && $ticket->subject === $row['name'], 'Ticket identity or content mismatch');
        $check($ticket->legacy_reference === (string) $row['id'], 'Ticket reference mismatch');
        $check(MigrationHold::isHeld($ticket), 'Ticket hold missing');
        $counts['tickets']++;
    }
    foreach ($snapshot->rows('ticket_messages') as $row) {
        $archived = BillmanagerRecord::where(['import_id' => $batch->id, 'source_table' => 'ticket_messages', 'source_id' => $row['id']])->sole();
        $check($archived->payload === $row, 'Archived message mismatch');
        $id = $context->mappedId('ticket_messages', (string) $row['id']);
        $deleted = !empty($row['user_delete']) || (!empty($row['date_delete']) && !str_starts_with($row['date_delete'], '0000-'));
        if ($deleted) {
            $check($id === null, 'Deleted message exposed');
            $counts['deleted_messages']++;
        } else {
            $native = TicketMessage::findOrFail($id);
            $check($native->message === $row['message'], 'Native message content mismatch');
            $check($native->ticket_id == $context->mappedId('tickets', (string) $row['ticket']), 'Message ticket mismatch');
            $counts['visible_messages']++;
        }
    }
    foreach (BillmanagerAttachment::where('import_id', $batch->id)->cursor() as $attachment) {
        $check(is_file($attachment->local_path) && hash_equals($attachment->sha256, hash_file('sha256', $attachment->local_path)), 'Attachment hash mismatch');
        $id = $context->mappedId('ticket_attachments', (string) $attachment->source_id);
        $check($attachment->deleted_message ? $id === null : $id !== null, 'Attachment visibility mismatch');
        $counts['attachment_hashes']++;
    }
    $check($counts['attachment_hashes'] === count($snapshot->rows('ticket_attachments')), 'Attachment count mismatch');
    $counts['queued_jobs'] = DB::table('jobs')->count();
    echo json_encode(['status' => 'verified', 'counts' => $counts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Support reconciliation failed; no acceptance result was issued.\n");
    exit(1);
}
