<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsOpeningIsolation;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\ColdRestoreProbe;
use Tests\Fixtures\Opening\IsolationJob;
use Tests\Fixtures\Opening\IsolationMail;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\Fixtures\Opening\ProviderInvocationSpy;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningIsolationTest extends TestCase
{
    use UsesCommittedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(trait_exists(AssertsOpeningIsolation::class), 'Complete opening isolation contract is missing');
    }

    private function probe(): object
    {
        return new class
        {
            use AssertsOpeningIsolation;
        };
    }

    public function test_side_effect_checks_detect_every_dispatch_mail_and_provider_channel_even_with_empty_jobs(): void
    {
        class_exists(ProviderInvocationSpy::class);
        $server = Server::create(['name' => 'Synthetic isolated provider', 'extension' => 'OpeningProviderSpy', 'type' => 'server']);
        $faults = [
            'bus.regular' => fn () => Bus::dispatch(new IsolationJob),
            'bus.sync' => fn () => Bus::dispatchSync(new IsolationJob),
            'bus.after_response' => fn () => Bus::dispatchAfterResponse(new IsolationJob),
            'bus.batch' => fn () => Bus::batch([new IsolationJob])->dispatch(),
            'mail.sent' => fn () => Mail::to('fixture@example.invalid')->send(new IsolationMail),
            'mail.queued' => fn () => Mail::to('fixture@example.invalid')->queue(new IsolationMail),
            'http.attempt' => fn () => Http::get('https://provider.example.invalid/probe'),
            'provider.invocation' => fn () => ExtensionHelper::call($server, 'probe'),
        ];
        foreach ($faults as $channel => $fault) {
            $probe = $this->probe();
            $probe->captureOpeningIsolation();
            $probe->assertOpeningIsolation();
            $fault();
            self::assertSame(0, DB::table('jobs')->count(), $channel);
            try {
                $probe->assertOpeningIsolation();
                self::fail('Captured opening side effect escaped: ' . $channel);
            } catch (AssertionFailedError $exception) {
                self::assertStringContainsString('Opening side effect: ' . $channel, $exception->getMessage());
            }
        }
        $probe = $this->probe();
        $probe->captureOpeningIsolation();
        $probe->assertOpeningIsolation();
    }

    public function test_original_jobs_payments_and_hold_rows_cannot_escape_isolation(): void
    {
        $owner = User::factory()->createQuietly();
        $invoice = Invoice::factory()->createQuietly(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending']);
        $faults = [
            fn () => DB::table('jobs')->insert(['queue' => 'synthetic', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]),
            fn () => DB::table('invoice_paid_processings')->insert(['invoice_id' => $invoice->id, 'origin' => 'native', 'processed_at' => now()]),
            fn () => DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic drift']),
        ];
        foreach ($faults as $fault) {
            $probe = $this->probe();
            $probe->captureOpeningIsolation();
            $fault();
            try {
                $probe->assertOpeningIsolation();
                self::fail('Original database drift escaped isolation');
            } catch (AssertionFailedError $exception) {
                self::assertStringContainsString('Opening side effect: original_database_state', $exception->getMessage());
            }
        }
    }

    public function test_missing_cold_lifecycle_creates_no_fixture_directory(): void
    {
        $script = getenv('OPENING_COLD_RESTORE_PROBE');
        $before = ProofFactory::$directories;
        putenv('OPENING_COLD_RESTORE_PROBE');
        try {
            try {
                ColdRestoreProbe::baseline();
                self::fail('Missing owned lifecycle was accepted');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('owned isolated testing lifecycle', $exception->getMessage());
            }
            self::assertSame($before, ProofFactory::$directories, 'Missing lifecycle created a fixture directory before denial');
        } finally {
            $script === false ? putenv('OPENING_COLD_RESTORE_PROBE') : putenv('OPENING_COLD_RESTORE_PROBE=' . $script);
        }
    }

    public function test_cold_restore_recovers_full_original_state_before_activation(): void
    {
        $owner = User::factory()->createQuietly();
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'Synthetic retained hold']);
        $baseline = ColdRestoreProbe::baseline();
        $result = ColdRestoreProbe::restore($baseline);
        self::assertSame('restored', $result['status']);
        self::assertSame($baseline['sql_sha256'], $result['sql_sha256']);
        self::assertSame($baseline['runtime_sha256'], $result['runtime_sha256']);
        self::assertSame($baseline['configuration_sha256'], $result['configuration_sha256']);
        self::assertSame($baseline['holds_sha256'], $result['holds_sha256']);
        self::assertNotSame($baseline['server_start'], $result['server_start']);
        self::assertSame($baseline['network_namespace'], $result['network_namespace']);
        self::assertSame($baseline['target_identity'], $result['target_identity']);
        self::assertSame(1, User::count());
        self::assertSame(1, DB::table('billmanager_holds')->count());
        self::assertFalse(config('account-funding.enabled'));
    }

    public function test_live_financial_receipt_makes_whole_db_restore_ineligible(): void
    {
        $owner = User::factory()->createQuietly();
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'status' => 'paid', 'currency_code' => 'USD']);
        $baseline = ColdRestoreProbe::baseline();
        $receipt = InvoicePaidProcessing::create(['invoice_id' => $invoice->id, 'origin' => 'native', 'processed_at' => now()]);
        $result = ColdRestoreProbe::restore($baseline);
        self::assertSame('refused_live_financial_state', $result['status']);
        self::assertSame($baseline['server_start'], $result['server_start']);
        self::assertSame($receipt->id, InvoicePaidProcessing::sole()->id);
        self::assertSame('native', InvoicePaidProcessing::sole()->origin);
    }
}
