<?php

namespace App\Console\Commands;

use App\Services\Accounts\Legacy\LegacyPostingService;
use Illuminate\Console\Command;

final class ApplyBillmanagerLegacyPayment extends Command
{
    protected $signature = 'billmanager:legacy-payment:apply {effect_key}';

    protected $description = 'Apply one verified BILLmanager legacy payment receipt';

    public function handle(LegacyPostingService $posting): int
    {
        $effect = $this->argument('effect_key');
        $result = $posting->apply($effect);
        $this->line(json_encode([
            'effect_key' => $effect, 'disposition' => $result?->disposition ?? 'stored_pending',
            'economic_sha256' => $result?->economic_sha256, 'receipt_sha256' => $result?->receipt_sha256,
            'result_id' => $result?->id, 'movement_id' => $result?->movement_id,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
