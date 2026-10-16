<?php

namespace App\Services\Accounts;

use RuntimeException;

/** A current source proof cannot wait behind a frame waiting for this owner. */
final class AccountReceiptBusy extends RuntimeException {}
