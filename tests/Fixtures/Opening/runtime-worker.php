<?php

use App\Services\Accounts\AccountFundingGate;
use Illuminate\Contracts\Console\Kernel;

// Genuine non-root, fresh-container service-reader acceptance. No testing signer.
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
(new AccountFundingGate)->assertEnabled();
$path = config('account-funding.runtime_acceptance_path');
$bytes = file_get_contents($path);
$proofDenied = @file_put_contents($path, $bytes) === false;
$ancestorDenied = @file_put_contents(dirname($path) . '/unauthorized-worker-write', 'denied') === false;
if (!$proofDenied || !$ancestorDenied) {
    throw new RuntimeException('Synthetic service could replace runtime acceptance.');
}
echo json_encode(['accepted' => true, 'uid' => posix_geteuid(), 'proof_write_denied' => $proofDenied, 'ancestor_write_denied' => $ancestorDenied], JSON_THROW_ON_ERROR);
