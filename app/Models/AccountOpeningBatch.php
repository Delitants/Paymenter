<?php

namespace App\Models;

class AccountOpeningBatch extends Model
{
    use Traits\GuardsOpeningHistory;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['target_identity', 'journal_path', 'journal_device', 'journal_inode'];

    protected $casts = ['target_identity' => 'array'];
}
