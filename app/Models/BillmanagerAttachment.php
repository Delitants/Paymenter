<?php

namespace App\Models;

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
}
