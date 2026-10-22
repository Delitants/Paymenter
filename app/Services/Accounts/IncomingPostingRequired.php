<?php

namespace App\Services\Accounts;

use App\Models\AccountPostingIssue;
use RuntimeException;
use Throwable;

final class IncomingPostingRequired extends RuntimeException
{
    public function __construct(public readonly AccountPostingIssue $issue, Throwable $previous)
    {
        parent::__construct('Payment received; account posting requires reconciliation.', previous: $previous);
    }
}
