<?php

namespace App\Models;

/** Instance writes are guarded; privileged query-builder SQL is outside that boundary. */
final class LegacyReceiptBudget extends Model
{
    use Traits\GuardsLegacyReceipts;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['retained_bytes' => 'integer', 'retained_observations' => 'integer'];
}
