<?php

namespace Tests\Feature\Accounts;

use App\Models\AccountMovement;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\User;
use App\Services\Accounts\AccountFundingGate;
use App\Services\Accounts\AccountWriteContext;
use App\Services\Accounts\AccountWriteGuard;
use App\Services\Accounts\OpeningAuthority;
use App\Services\Accounts\OpeningEvidence;
use App\Services\Accounts\WalletLedger;
use App\Services\BillmanagerMigration\Opening\HeldOpeningPermit;
use App\Services\BillmanagerMigration\Opening\InactiveOpeningAuthority;
use App\Services\BillmanagerMigration\Opening\OpeningBatchOperator;
use App\Services\BillmanagerMigration\Opening\OpeningFenceReference;
use App\Services\BillmanagerMigration\Opening\OpeningPreparation;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Accounts\SyntheticOpeningAuthority;
use Tests\Fixtures\Opening\AcceptedFixture;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class HeldOpeningPermitTest extends TestCase
{
    use UsesCommittedDatabase { tearDown as private committedTearDown; }

    private array $argv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->argv = $_SERVER['argv'];
        // Fixture-only console identity. Batch acceptance separately uses genuine Artisan processes.
        $_SERVER['argv'] = [base_path('artisan'), 'billmanager:openings:apply'];
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;
        ProofFactory::cleanup();
        $this->committedTearDown();
    }

    private function authority(array $p): InactiveOpeningAuthority
    {
        return new InactiveOpeningAuthority($p['bundle'], $p['dir'] . '/approval.json', $p['dir'] . '/approval-signature.json', $p['process']);
    }

    private function denied(callable $call): void
    {
        $before = (new OpeningTargetState)->capture();
        try {
            $call();
        } catch (AssertionFailedError|QueryException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
            self::assertSame($before, (new OpeningTargetState)->capture());

            return;
        }
        self::fail('Unapproved held write was accepted.');
    }

    public static function shapes(): array
    {
        return [['12.3400', '0.0000', '12.3400', '12.34', '0.0000', '0.0000', '0.0000'], ['1.0050', '0.0000', '1.0100', '1.01', '0.0000', '0.0000', '0.0000'], ['0.0049', '0.0000', '0.0000', '0.00', '0.0000', '0.0000', '0.0000'], ['0', '0.0000', '0.0000', '0.00', '0.0000', '0.0000', '0.0000'], ['-40.0050', '100.0000', '-40.0050', '0.00', '40.0050', '59.9950', '0.0000'], ['-40.0050', '20.0000', '-40.0050', '0.00', '40.0050', '0.0000', '20.0050']];
    }

    #[DataProvider('shapes')]
    public function test_valid_held_opening_leaves_every_hold_and_quote_blocked(string $opening, string $limit, string $effective, string $cash, string $debt, string $allowance, string $excess): void
    {
        self::assertTrue(class_exists(InactiveOpeningAuthority::class), 'Validated inactive opening authority is missing.');
        $p = AcceptedFixture::make($opening, $limit, realBackup: true);
        $authority = $this->authority($p);
        $holds = DB::table('billmanager_holds')->orderBy('id')->get()->all();
        config(['account-opening.journal_directory' => $p['dir']]);
        (new OpeningBatchOperator)->apply($p['bundle'], $p['dir'] . '/approval.json', $p['dir'] . '/approval-signature.json');
        $wallet = AccountWallet::sole();
        self::assertFalse($wallet->active);
        self::assertSame($effective, $wallet->balance);
        self::assertSame($cash, Credit::sole()->amount);
        $quote = (new WalletLedger)->quote(User::findOrFail($p['owner_id']), 'USD');
        self::assertTrue($quote->blocked);
        self::assertSame('0.00', $quote->fundingAvailable);
        self::assertSame($debt, $quote->debt);
        self::assertSame($allowance, $quote->remainingAllowance);
        self::assertSame($excess, $quote->excessDebt);
        self::assertEquals($holds, DB::table('billmanager_holds')->orderBy('id')->get()->all());
        $before = (new OpeningTargetState)->capture();
        $p['renew']();
        $this->denied(fn () => $authority->duringAccount($p['evidence'], fn ($permit) => (new WalletLedger)->initializeHeldInactive($p['evidence'], $authority, $permit)));
        self::assertSame($wallet->id, AccountWallet::sole()->id);
        self::assertSame($before, (new OpeningTargetState)->capture());
        self::assertSame(0, AccountMovement::count());
        self::assertFalse(config('account-funding.enabled'));
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_permit_cannot_escape_its_transaction_or_opening_action(): void
    {
        self::assertTrue(class_exists(HeldOpeningPermit::class), 'Transaction-bound held opening permit is missing.');
        $p = AcceptedFixture::make();
        $authority = $this->authority($p);
        $permit = null;
        $authority->duringAccount($p['evidence'], function ($issued) use (&$permit, $p) {
            $permit = $issued;
            $issued->assertFor($p['evidence']);
        });
        $this->denied(fn () => $permit->assertFor($p['evidence']));
        $this->denied(fn () => serialize($permit));
        $active = new OpeningEvidence($p['evidence']->sourceSystem, '10', $p['owner_id'], 'USD', '12.3400', '0.0000', $p['evidence']->snapshotHash, $p['evidence']->policyHash, $p['evidence']->grantHash, true);
        $this->denied(fn () => $authority->duringAccount($active, fn () => self::fail('Active scope was entered.')));
        $old = new SyntheticOpeningAuthority([]);
        app()->instance(OpeningAuthority::class, $old);
        $this->denied(fn () => $authority->duringAccount($p['evidence'], fn () => throw new RuntimeException('fixture exception')));
        self::assertSame($old, app(OpeningAuthority::class));
        self::assertFalse(config('account-funding.enabled'));
        self::assertSame(0, AccountWallet::count());
    }

    public function test_every_nested_projection_hold_guard_requires_exact_permit(): void
    {
        self::assertTrue(method_exists(AccountWriteGuard::class, 'assertOpeningHold'), 'Opening-only hold guard is missing.');
        $other = User::factory()->createQuietly();
        $p = AcceptedFixture::make(realBackup: true);
        $seen = 0;
        Credit::created(function () use ($p, $other, &$seen) {
            $seen++;
            $authority = app(OpeningAuthority::class);
            self::assertInstanceOf(InactiveOpeningAuthority::class, $authority);
            // Observe the real issued token inside the native event; the test
            // neither constructs nor rehydrates an authority or permit.
            $permit = (new \ReflectionProperty($authority, 'permit'))->getValue($authority);
            $evidence = (new \ReflectionProperty($authority, 'current'))->getValue($authority);
            $this->denied(fn () => (new WalletLedger)->initialize($evidence, $authority));
            $this->denied(fn () => $permit->assertHold($other, 'write account records'));
            $this->denied(fn () => Credit::create(['user_id' => $p['owner_id'], 'currency_code' => 'USD', 'amount' => '99.00']));
            $wallet = (new WalletLedger)->initializeHeldInactive($evidence, $authority, $permit);
            $this->denied(fn () => Credit::sole()->update(['amount' => '99.00']));
            $this->denied(fn () => (new WalletLedger)->post(AccountWriteContext::heldOpening($evidence, $authority, $permit), '1.00', 'deposit', 'fabricated', str_repeat('a', 64)));
            $hold = (array) DB::table('billmanager_holds')->first();
            $id = $hold['id'];
            DB::table('billmanager_holds')->where('id', $id)->update(['reason' => 'changed fixture hold']);
            $this->denied(fn () => $permit->assertFor($evidence));
            DB::table('billmanager_holds')->where('id', $id)->update(['reason' => $hold['reason']]);
        });
        config(['account-opening.journal_directory' => $p['dir']]);
        (new OpeningBatchOperator)->apply($p['bundle'], $p['dir'] . '/approval.json', $p['dir'] . '/approval-signature.json');
        self::assertSame(1, $seen);
    }

    public function test_source_fact_revalidation_is_distinct_from_initial_financial_absence(): void
    {
        self::assertTrue(method_exists(OpeningPreparation::class, 'sourceFacts'), 'Immutable source facts must support an exact inactive replay.');
        $p = AcceptedFixture::make();
        $report = (new OpeningPreparation)->sourceFacts($p['bundle']);
        self::assertSame(1, $report['counts']['candidate']);
    }

    public function test_previous_resolved_binding_survives_temporary_authority_failure(): void
    {
        $p = AcceptedFixture::make();
        $authority = $this->authority($p);
        $old = new SyntheticOpeningAuthority([]);
        app()->singleton(OpeningAuthority::class, fn () => $old);
        self::assertSame($old, app(OpeningAuthority::class));
        $binding = app()->getBindings()[OpeningAuthority::class];
        $this->denied(fn () => $authority->duringAccount($p['evidence'], fn () => throw new RuntimeException('fixture exception')));
        self::assertSame($binding, app()->getBindings()[OpeningAuthority::class] ?? null, 'Temporary opening authority lost the previous resolved binding.');
        app()->forgetInstance(OpeningAuthority::class);
        self::assertSame($old, app(OpeningAuthority::class));
    }

    public function test_warm_manifest_cache_still_checks_provider_bytes(): void
    {
        $p = AcceptedFixture::make();
        $authority = $this->authority($p);
        $authority->assertAcceptedRelease();
        $path = base_path('app/Providers/AppServiceProvider.php');
        $bytes = file_get_contents($path);
        try {
            file_put_contents($path, $bytes . "\n// Synthetic changed installed provider bytes.\n");
            try {
                $authority->assertAcceptedRelease();
                self::fail('Warm parsing cache accepted changed provider bytes.');
            } catch (RuntimeException $e) {
                self::assertSame('Installed release bytes or population changed.', $e->getMessage());
            }
        } finally {
            file_put_contents($path, $bytes);
        }
    }

    public function test_reopened_transaction_cannot_reuse_live_permit(): void
    {
        $p = AcceptedFixture::make();
        $authority = $this->authority($p);
        $this->denied(fn () => $authority->duringAccount($p['evidence'], function ($permit) use ($p) {
            DB::commit();
            DB::beginTransaction();
            $permit->assertFor($p['evidence']);
        }));
    }

    public function test_snapshot_capture_must_belong_to_original_freeze_window(): void
    {
        $p = AcceptedFixture::make(captured: '2026-01-01T00:00:00Z');
        $authority = $this->authority($p);
        $before = (new OpeningTargetState)->capture();
        try {
            $authority->assertApproved($p['evidence']);
            self::fail('A capture predating the original writer fence was accepted.');
        } catch (RuntimeException $e) {
            self::assertSame('Snapshot capture is outside the original writer fence.', $e->getMessage());
        }
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_inactive_opening_authority_cannot_enable_normal_funding_gate(): void
    {
        $p = AcceptedFixture::make();
        $authority = $this->authority($p);
        $authority->duringAccount($p['evidence'], function () {
            $this->denied(fn () => (new AccountFundingGate)->assertEnabled());
        });
    }

    public function test_immutable_current_lease_reference_uses_complete_signed_generation(): void
    {
        self::assertTrue(class_exists(OpeningFenceReference::class), 'Atomic current lease references are missing.');
        $p = AcceptedFixture::make(heartbeat: false);
        $authority = $this->authority($p);
        foreach (['live-fence.json', 'live-fence-signature.json'] as $name) {
            ProofFactory::write($p['dir'] . '/generation-' . $name, file_get_contents($p['dir'] . '/' . $name));
        }
        $record = ['schema_version' => 1, 'purpose' => 'account-opening-fence-reference', 'payload' => ['path' => $p['dir'] . '/generation-live-fence.json', 'sha256' => hash_file('sha256', $p['dir'] . '/generation-live-fence.json')],
            'signature' => ['path' => $p['dir'] . '/generation-live-fence-signature.json', 'sha256' => hash_file('sha256', $p['dir'] . '/generation-live-fence-signature.json')]];
        ProofFactory::write($p['dir'] . '/current-lease.json', json_encode($record));
        config(['account-opening.fence_index_path' => $p['dir'] . '/current-lease.json']);
        ProofFactory::write($p['dir'] . '/live-fence.json', '{}');
        $authority->assertFenceCurrent();
        $record['payload']['sha256'] = str_repeat('f', 64);
        ProofFactory::write($p['dir'] . '/current-lease.json', json_encode($record));
        $this->denied(fn () => $authority->assertFenceCurrent());
    }

    public function test_current_lease_cannot_regress_within_one_authority(): void
    {
        $p = AcceptedFixture::make(heartbeat: false);
        $old = json_decode(file_get_contents($p['dir'] . '/live-fence.json'), true, flags: JSON_THROW_ON_ERROR);
        $authority = $this->authority($p);
        $p['sign']('live-fence', 'account-opening-fence', [...$old, 'sequence' => $old['sequence'] + 1]);
        $authority->assertFenceCurrent();
        $p['sign']('live-fence', 'account-opening-fence', $old);
        try {
            $authority->assertFenceCurrent();
        } catch (RuntimeException $e) {
            self::assertSame('Current writer fence regressed.', $e->getMessage());

            return;
        }
        self::fail('A still-valid older lease replaced the last observed generation.');
    }

    public function test_held_wallet_cannot_commit_without_its_live_batch_receipt_scope(): void
    {
        $p = AcceptedFixture::make();
        $authority = $this->authority($p);
        $this->denied(fn () => $authority->duringAccount($p['evidence'], fn ($permit) => (new WalletLedger)->initializeHeldInactive($p['evidence'], $authority, $permit)));
        self::assertSame(0, AccountWallet::count());
    }
}
