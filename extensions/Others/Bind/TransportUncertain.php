<?php

namespace Paymenter\Extensions\Others\Bind;

final class TransportUncertain extends \RuntimeException
{
    public function __construct(public readonly string $requestId)
    {
        parent::__construct('DNS operation outcome could not be confirmed. Reconcile the original request reference.');
    }
}
