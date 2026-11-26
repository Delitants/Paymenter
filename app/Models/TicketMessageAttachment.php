<?php

namespace App\Models;

use Illuminate\Support\Str;

class TicketMessageAttachment extends Model
{
    use Traits\GuardsMigrationWrites;

    protected $fillable = [
        'path',
        'filename',
        'filesize',
        'mime_type',
        'ticket_message_id',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    public function ticketMessage()
    {
        return $this->belongsTo(TicketMessage::class);
    }

    public function getLocalPathAttribute(): string
    {
        return storage_path("app/{$this->path}");
    }

    public function getFilenameAttribute(?string $filename): ?string
    {
        if ($this->getKey() === null || !str_starts_with($this->path ?? '', 'tickets/legacy/')) {
            return $filename;
        }

        $legacy = BillmanagerAttachment::query()
            ->select('billmanager_attachments.*')
            ->join('billmanager_mappings as mapping', function ($join) {
                $join->on('mapping.import_id', '=', 'billmanager_attachments.import_id')
                    ->on('mapping.source_id', '=', 'billmanager_attachments.source_id');
            })
            ->where('mapping.source_table', 'ticket_attachments')
            ->where('mapping.target_table', 'ticket_message_attachments')
            ->where('mapping.target_id', $this->getKey())
            ->where('billmanager_attachments.path', $this->path)
            ->where('billmanager_attachments.filesize', $this->filesize)
            ->where('billmanager_attachments.deleted_message', false)
            ->limit(2)->get();

        return $legacy->count() === 1 ? $legacy->first()->downloadFilename() : $filename;
    }

    // Function to check if attachment can be previewed
    public function canPreview(): bool
    {
        return Str::startsWith($this->mime_type, ['image/']);
    }
}
