<?php

namespace Tests\Feature\Accounts;

use App\Models\AccountMovement;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Accounts\AcceptedFundingReleaseAuthority;
use App\Services\Accounts\AccountFundingGate;
use App\Services\Accounts\AccountWriteContext;
use App\Services\Accounts\OpeningAuthority;
use App\Services\Accounts\OpeningEvidence;
use App\Services\Accounts\WalletLedger;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Concerns\UsesVerifiedDeposit;
use Tests\Fixtures\Accounts\SyntheticOpeningAuthority;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\Fixtures\Opening\RuntimeFixture;
use Tests\TestCase;

#[Group('opening-native')]
class AcceptedFundingReleaseTest extends TestCase
{
    use UsesCommittedDatabase, UsesVerifiedDeposit;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(class_exists(AcceptedFundingReleaseAuthority::class), 'Standing runtime authority contract is missing');
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            ProofFactory::cleanup();
        } finally {
            parent::tearDown();
        }
    }

    private function denied(callable $call, ?string $reason = null): void
    {
        $before = (new OpeningTargetState)->capture();
        try {
            $call();
            self::fail('Invalid runtime funding authorization was accepted');
        } catch (QueryException $exception) {
            throw $exception;
        } catch (\RuntimeException|\DomainException $exception) {
            self::assertNotSame('', $exception->getMessage());
            if ($reason !== null) {
                self::assertStringContainsString($reason, strtolower($exception->getMessage()));
            }
        }
        self::assertSame($before, (new OpeningTargetState)->capture());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_flag_alone_and_missing_runtime_acceptance_remain_closed(): void
    {
        app()->forgetInstance(OpeningAuthority::class);
        app()->offsetUnset(OpeningAuthority::class);
        config(['account-funding.enabled' => true]);
        (new AppServiceProvider(app()))->register();
        self::assertFalse(app()->bound(OpeningAuthority::class));
        $this->denied(fn () => (new AccountFundingGate)->assertEnabled());
        config(['account-funding.runtime_acceptance_path' => '/missing/payload.json', 'account-funding.runtime_signature_path' => '/missing/signature.json',
            'account-funding.runtime_trust_path' => '/missing/trust.json', 'account-funding.runtime_reader_gid' => RuntimeFixture::gid()]);
        (new AppServiceProvider(app()))->register();
        self::assertTrue(app()->bound(OpeningAuthority::class));
        $this->denied(fn () => (new AccountFundingGate)->assertEnabled());
        $explicit = new SyntheticOpeningAuthority;
        app()->instance(OpeningAuthority::class, $explicit);
        (new AppServiceProvider(app()))->register();
        self::assertSame($explicit, app(OpeningAuthority::class));
    }

    public function test_standing_acceptance_never_authorizes_initialize(): void
    {
        $owner = User::factory()->createQuietly();
        $fixture = RuntimeFixture::accepted();
        app()->instance(OpeningAuthority::class, $fixture['authority']);
        $evidence = new OpeningEvidence('synthetic', 'runtime-' . $owner->id, $owner->id, 'USD', '0.0000', '0.00', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), true);
        $this->denied(fn () => $fixture['authority']->assertApproved($evidence));
        $this->denied(fn () => (new WalletLedger)->initialize($evidence, $fixture['authority']));
    }

    public function test_worker_revalidates_replaced_or_revoked_release(): void
    {
        $fixture = RuntimeFixture::accepted();
        app()->instance(OpeningAuthority::class, $fixture['authority']);
        $gate = new AccountFundingGate;
        $gate->assertEnabled();
        $trust = file_get_contents($fixture['trust']);
        $revoked = json_decode($trust, true, flags: JSON_THROW_ON_ERROR);
        $revoked['revoked_nonces'] = [$fixture['value']['nonce']];
        file_put_contents($fixture['trust'], json_encode($revoked, JSON_THROW_ON_ERROR));
        $this->denied(fn () => $gate->assertEnabled());
        file_put_contents($fixture['trust'], $trust);
        $revoked['revoked_keys'] = ['fixture'];
        $revoked['revoked_nonces'] = [];
        file_put_contents($fixture['trust'], json_encode($revoked, JSON_THROW_ON_ERROR));
        $this->denied(fn () => $gate->assertEnabled());
        file_put_contents($fixture['trust'], $trust);
        $original = $fixture['value'];
        $fixture['value']['purpose'] = 'account-funding-release';
        RuntimeFixture::resign($fixture);
        $this->denied(fn () => $gate->assertEnabled());
        $fixture['value'] = $original;
        $fixture['value']['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 1);
        RuntimeFixture::resign($fixture);
        $this->denied(fn () => $gate->assertEnabled());
        $fixture['value'] = $original;
        $fixture['value']['target_identity']['db_database'] = 'another_synthetic_database';
        RuntimeFixture::resign($fixture);
        $this->denied(fn () => $gate->assertEnabled());
        $fixture['value'] = $original;
        RuntimeFixture::resign($fixture);
        $setting = DB::table('settings')->first();
        $oldValue = $setting->value;
        DB::table('settings')->where('id', $setting->id)->update(['value' => $oldValue . '-changed']);
        try {
            $this->denied(fn () => $gate->assertEnabled());
        } finally {
            DB::table('settings')->where('id', $setting->id)->update(['value' => $oldValue]);
        }
        $source = base_path('config/account-funding.php');
        $bytes = file_get_contents($source);
        file_put_contents($source, $bytes . "\n// changed installed bytes\n");
        try {
            $this->denied(fn () => $gate->assertEnabled());
        } finally {
            file_put_contents($source, $bytes);
        }
        chmod($fixture['payload'], 0660);
        $this->denied(fn () => $gate->assertEnabled());
        chmod($fixture['payload'], 0640);
        $replacement = $fixture['payload'] . '.replacement';
        file_put_contents($replacement, file_get_contents($fixture['payload']) . ' ');
        chmod($replacement, 0640);
        chgrp($replacement, RuntimeFixture::gid());
        rename($replacement, $fixture['payload']);
        $this->denied(fn () => $gate->assertEnabled());
    }

    public function test_fresh_nonroot_worker_reads_acceptance_but_cannot_replace_it(): void
    {
        $fixture = RuntimeFixture::accepted();
        $before = (new OpeningTargetState)->capture();
        $environment = getenv();
        $environment['ACCOUNT_FUNDING_ENABLED'] = 'true';
        $environment['ACCOUNT_FUNDING_RUNTIME_READER_GID'] = (string) RuntimeFixture::gid();
        $environment['ACCOUNT_FUNDING_RUNTIME_ACCEPTANCE_PATH'] = $fixture['payload'];
        $environment['ACCOUNT_FUNDING_RUNTIME_SIGNATURE_PATH'] = $fixture['signature'];
        $environment['ACCOUNT_FUNDING_RUNTIME_TRUST_PATH'] = $fixture['trust'];
        $pipes = [];
        $child = proc_open(['/usr/bin/setpriv', '--reuid=33', '--regid=33', '--clear-groups', PHP_BINARY, 'tests/Fixtures/Opening/runtime-worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        self::assertIsResource($child);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($child), $errors);
        self::assertSame(['accepted' => true, 'uid' => 33, 'proof_write_denied' => true, 'ancestor_write_denied' => true], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($before, (new OpeningTargetState)->capture());
    }

    public function test_normal_funding_retains_hold_and_receipt_checks(): void
    {
        $owner = User::factory()->createQuietly();
        $inactive = User::factory()->createQuietly();
        $evidence = new OpeningEvidence('synthetic', 'runtime-' . $owner->id, $owner->id, 'USD', '-40.0050', '100.00', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), true);
        $inactiveEvidence = new OpeningEvidence('synthetic', 'runtime-' . $inactive->id, $inactive->id, 'USD', '10.00', '0.00', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), false);
        $synthetic = new SyntheticOpeningAuthority([$evidence, $inactiveEvidence]);
        app()->instance(OpeningAuthority::class, $synthetic);
        config(['account-funding.enabled' => true]);
        $wallet = (new WalletLedger)->initialize($evidence, $synthetic);
        (new WalletLedger)->initialize($inactiveEvidence, $synthetic);
        $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'status' => 'pending', 'currency_code' => 'USD', 'pricing_tax_rate' => '0.0000']);
        $gateway = $this->depositGateway();
        $invoice->items()->create(['description' => 'Synthetic deposit principal', 'price' => '50.00', 'quantity' => 1, 'tax_amount' => '0.00', 'kind' => 'credit_allocation', 'reference_type' => Credit::class]);
        $invoice->items()->create(['description' => 'Synthetic gateway fee', 'price' => '2.00', 'quantity' => 1, 'tax_amount' => '0.00', 'kind' => 'gateway_fee', 'gateway_id' => $gateway->id]);
        $this->verifiedDeposit($invoice, $gateway, 'synthetic-runtime-receipt', false);
        $receipt = InvoicePaidProcessing::create(['invoice_id' => $invoice->id, 'origin' => 'native', 'processed_at' => now()]);
        $fixture = RuntimeFixture::accepted();
        app()->instance(OpeningAuthority::class, $fixture['authority']);
        $context = AccountWriteContext::paidDeposit($receipt);
        $key = 'synthetic-runtime:' . $invoice->id;
        $first = (new WalletLedger)->post($context, '50.00', 'deposit', $key, $context->fingerprint($key));
        $again = (new WalletLedger)->post($context, '50.00', 'deposit', $key, $context->fingerprint($key));
        self::assertSame($first->id, $again->id);
        self::assertSame(1, AccountMovement::count());
        self::assertSame('9.9950', $wallet->fresh()->balance);
        self::assertSame('9.99', $owner->credits()->sole()->amount);
        self::assertTrue((new WalletLedger)->quote($inactive, 'USD')->blocked);
        $this->denied(fn () => (new WalletLedger)->post($context, '51.00', 'deposit', $key . '-unbacked', $context->fingerprint($key . '-unbacked')));
        DB::table('billmanager_holds')->insert(['model_type' => User::class, 'model_id' => $owner->id, 'reason' => 'synthetic migration hold']);
        $holds = DB::table('billmanager_holds')->get()->toArray();
        self::assertTrue((new WalletLedger)->quote($owner, 'USD')->blocked);
        $this->denied(fn () => (new WalletLedger)->post($context, '50.00', 'deposit', $key, $context->fingerprint($key)), 'migration');
        self::assertEquals($holds, DB::table('billmanager_holds')->get()->toArray());
    }
}
