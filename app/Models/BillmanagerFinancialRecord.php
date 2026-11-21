<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

class BillmanagerFinancialRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['details'];

    protected $casts = ['details' => 'encrypted:array'];

    public function getNativeInvoiceAttribute(): ?Invoice
    {
        if ($this->source_table !== 'invoices') {
            return null;
        }
        $source = DB::table('billmanager_imports')->where('id', $this->import_id)->value('source_host');
        $id = DB::table('billmanager_mappings')->where(['source_host' => $source, 'source_table' => 'invoices', 'source_id' => $this->source_id, 'target_table' => 'invoices'])->value('target_id');

        return $id ? Invoice::where('user_id', $this->user_id)->find($id) : null;
    }

    protected static function booted(): void
    {
        static::saving(fn () => throw new \RuntimeException('Historical financial records are immutable'));
        static::deleting(fn () => throw new \RuntimeException('Historical financial records are immutable'));
    }
}
