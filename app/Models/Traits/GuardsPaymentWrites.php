<?php

namespace App\Models\Traits;

use App\Services\Gateways\PaymentWriteGuard;
use Illuminate\Database\Eloquent\Builder;

trait GuardsPaymentWrites
{
    protected function performInsert(Builder $query)
    {
        return (new PaymentWriteGuard)->withInvoiceLock($this, function () use ($query) {
            (new PaymentWriteGuard)->assertMutation($this);

            return parent::performInsert($query);
        });
    }

    protected function performUpdate(Builder $query)
    {
        return (new PaymentWriteGuard)->withInvoiceLock($this, function () use ($query) {
            (new PaymentWriteGuard)->assertMutation($this);

            return parent::performUpdate($query);
        });
    }

    protected function performDeleteOnModel()
    {
        return (new PaymentWriteGuard)->withInvoiceLock($this, function () {
            (new PaymentWriteGuard)->assertMutation($this, true);

            return parent::performDeleteOnModel();
        });
    }
}
