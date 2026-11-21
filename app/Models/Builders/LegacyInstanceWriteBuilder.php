<?php

namespace App\Models\Builders;

use App\Services\Accounts\Legacy\LegacyReceiptWriteGuard;
use Illuminate\Database\Eloquent\Builder;

/** Internal model-save bridge; never returned by an evidence model's public query. */
final class LegacyInstanceWriteBuilder extends Builder
{
    public function __call($method, $parameters)
    {
        if (in_array(strtolower($method), ['insert', 'insertgetid'], true)) {
            LegacyReceiptWriteGuard::assertPendingSql($this->getModel(), 'create', $parameters[0]);
        }

        return parent::__call($method, $parameters);
    }

    public function update(array $values)
    {
        LegacyReceiptWriteGuard::assertPendingSql($this->getModel(), 'update', $values);

        return parent::update($values);
    }
}
