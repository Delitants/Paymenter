<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;

class BillmanagerAttachment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['deleted_message' => 'boolean'];

    protected $hidden = ['path'];

    public function getLocalPathAttribute(): string
    {
        return storage_path('app/' . $this->path);
    }

    public function downloadFilename(): string
    {
        $record = BillmanagerRecord::where([
            'import_id' => $this->import_id,
            'source_table' => 'ticket_attachments',
            'source_id' => $this->source_id,
            'source_account_id' => $this->source_account_id,
        ])->first();

        try {
            $payload = $record?->payload;
        } catch (DecryptException) {
            return $this->filename;
        }

        $name = $payload['original']['name'] ?? null;
        if (($payload['sha256'] ?? null) !== $this->sha256 || (string) ($payload['size'] ?? '') !== (string) $this->filesize
            || !is_string($name) || trim($name) === '' || in_array($name, ['.', '..'], true)
            || str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1f\x7f]/u', $name) !== 0) {
            return $this->filename;
        }

        return $name;
    }
}
