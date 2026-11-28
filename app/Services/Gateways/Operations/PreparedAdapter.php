<?php

namespace App\Services\Gateways\Operations;

use App\Models\PaymentOperation;

interface PreparedAdapter extends Adapter
{
    /** Recheck provider eligibility while the original operation is still queued. */
    public function prepareExecution(PaymentOperation $operation): array;

    /** Transmit one write for the locked original claim after successful preparation. */
    public function executePrepared(PaymentOperation $operation, array $context): OperationResult;
}
