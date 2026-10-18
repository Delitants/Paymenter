<?php

namespace App\Models;

use App\Models\Builders\LegacyEvidenceBuilder;

final class LegacyReceiptConflict extends Model
{
    use Traits\GuardsLegacyReceipts;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [];

    public function newEloquentBuilder($query)
    {
        return new LegacyEvidenceBuilder($query);
    }
}
