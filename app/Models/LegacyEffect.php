<?php

namespace App\Models;

use App\Models\Builders\LegacyEvidenceBuilder;

final class LegacyEffect extends Model
{
    use Traits\GuardsLegacyReceipts;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['identity_json', 'economic_json'];

    public function newEloquentBuilder($query)
    {
        return new LegacyEvidenceBuilder($query);
    }
}
