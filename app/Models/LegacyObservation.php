<?php

namespace App\Models;

use App\Models\Builders\LegacyEvidenceBuilder;

final class LegacyObservation extends Model
{
    use Traits\GuardsLegacyReceipts;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['payload_bytes', 'signature_bytes'];

    public function newEloquentBuilder($query)
    {
        return new LegacyEvidenceBuilder($query);
    }
}
