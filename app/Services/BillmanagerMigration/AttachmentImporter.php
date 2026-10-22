<?php

namespace App\Services\BillmanagerMigration;

use App\Models\BillmanagerRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AttachmentImporter
{
    public function __construct(private ?string $destinationRoot = null) {}

    public function import(string $manifestPath, string $sourceDirectory, ImportContext $context): ImportReport
    {
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !array_is_list($manifest)) {
            throw new RuntimeException('Invalid attachment manifest');
        }
        $root = realpath($sourceDirectory);
        if ($root === false) {
            throw new RuntimeException('Attachment source directory is missing');
        }
        $prepared = [];
        $identities = [];
        foreach ($manifest as $row) {
            foreach (['id', 'account', 'ticket_message', 'path', 'filename', 'size', 'sha256'] as $field) {
                if (!isset($row[$field])) {
                    throw new RuntimeException('Incomplete attachment manifest');
                }
            }
            if (!ctype_digit((string) $row['id']) || !ctype_digit((string) $row['account']) || isset($identities[$row['id']])) {
                throw new RuntimeException('Invalid or duplicate attachment identity');
            }
            $identities[$row['id']] = true;
            $parts = explode('/', $row['path']);
            $path = realpath($root . '/' . $row['path']);
            if ($parts[0] !== (string) $row['account'] || in_array('..', $parts, true) || in_array('.', $parts, true)
                || !$path || !str_starts_with($path, $root . '/' . $row['account'] . '/') || !is_file($path)) {
                throw new RuntimeException('Attachment is outside its account directory');
            }
            if (!preg_match('/^[a-f0-9]{64}$/D', $row['sha256']) || !ctype_digit((string) $row['size'])
                || filesize($path) !== (int) $row['size'] || !hash_equals($row['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Attachment size or checksum mismatch');
            }
            $message = BillmanagerRecord::where(['import_id' => $context->importId, 'source_table' => 'ticket_messages', 'source_id' => $row['ticket_message']])->first();
            if (!$message || (string) $message->source_account_id !== (string) $row['account']) {
                throw new RuntimeException('Attachment message belongs to another account or snapshot');
            }
            $original = $message->payload;
            $deleted = !empty($original['user_delete']) || (!empty($original['date_delete']) && !str_starts_with($original['date_delete'], '0000-'));
            $native = $context->mappedId('ticket_messages', (string) $row['ticket_message']);
            if (!$deleted && !$native) {
                throw new RuntimeException('Visible attachment has no native message');
            }
            $prepared[] = [$row, $path, $deleted, $native];
        }
        $created = [];
        $destination = $this->destinationRoot ?? storage_path('app');
        try {
            return DB::transaction(function () use ($prepared, $context, $destination, &$created) {
                $visible = 0;
                foreach ($prepared as [$row, $source, $deleted, $message]) {
                    $relative = 'tickets/legacy/' . hash('sha256', $context->sourceHost) . '/' . $row['id'] . '-' . $row['sha256'];
                    $target = $destination . '/' . $relative;
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                        throw new RuntimeException('Cannot create private attachment directory');
                    }
                    if (!file_exists($target)) {
                        $out = fopen($target, 'xb');
                        if (!$out) {
                            throw new RuntimeException('Cannot create private attachment');
                        }
                        $created[] = $target;
                        chmod($target, 0600);
                        $in = fopen($source, 'rb');
                        try {
                            stream_copy_to_stream($in, $out);
                        } finally {
                            fclose($in);
                            fclose($out);
                        }
                    }
                    if (filesize($target) !== (int) $row['size'] || !hash_equals($row['sha256'], hash_file('sha256', $target))) {
                        throw new RuntimeException('Copied attachment checksum mismatch');
                    }
                    $context->archive('ticket_attachments', (string) $row['id'], $row, (int) $row['account']);
                    DB::table('billmanager_attachments')->insertOrIgnore([
                        'import_id' => $context->importId, 'source_id' => $row['id'], 'source_account_id' => $row['account'],
                        'filename' => $row['filename'], 'path' => $relative, 'filesize' => $row['size'], 'sha256' => $row['sha256'], 'deleted_message' => $deleted,
                    ]);
                    if (!$deleted) {
                        $visible++;
                        if ($context->mappedId('ticket_attachments', (string) $row['id']) === null) {
                            $id = DB::table('ticket_message_attachments')->insertGetId([
                                'uuid' => (string) Str::uuid(), 'filename' => $row['filename'], 'path' => $relative,
                                'filesize' => $row['size'], 'mime_type' => mime_content_type($target) ?: 'application/octet-stream',
                                'ticket_message_id' => $message,
                            ]);
                            $context->recordMapping('ticket_attachments', (string) $row['id'], 'ticket_message_attachments', $id);
                        }
                    }
                }

                return new ImportReport('attachments_imported', ['files' => count($prepared), 'visible_files' => $visible, 'deleted_message_files' => count($prepared) - $visible]);
            });
        } catch (\Throwable $e) {
            foreach ($created as $path) {
                unlink($path);
            }
            throw $e;
        }
    }
}
