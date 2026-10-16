<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentOperation;
use App\Models\Role;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHold;
use App\Services\Gateways\InvoicePaymentDependencies;
use App\Services\Gateways\Operations\GatewayOperations;
use App\Services\Gateways\Operations\ManualSettlements;
use App\Services\Gateways\Operations\OperationPolicy;
use App\Services\Gateways\Operations\Refunds;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Paymenter\Extensions\Gateways\SyntheticOperations\SyntheticOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

class AdminPaymentRaceTest extends TestCase
{
    use UsesCommittedDatabase;

    #[DataProvider('claims')]
    public function test_independent_process_refund_claims_do_not_duplicate_or_overreserve(bool $sameRequest): void
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $actor = User::factory()->create(['role_id' => Role::create(['name' => 'Synthetic race administrator', 'permissions' => ['*']])->id]);
        $this->actingAs($actor);
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '100.00', 'quantity' => 1]);
        $gateway = Gateway::create(['name' => 'Synthetic race gateway', 'extension' => 'Wave', 'type' => 'gateway', 'enabled' => false]);
        $gateway->settings()->create(['key' => 'admin_payment_operations_enabled', 'value' => '1']);
        $transaction = (new ManualSettlements)->record($actor, $invoice, $gateway, '100.00', 'synthetic-race-receipt', 'Synthetic received funds', '2026-10-01T12:00:00Z', (string) Str::uuid())->resultTransaction;
        $key = (string) Str::uuid();
        $worker = tempnam(sys_get_temp_dir(), 'paymenter-refund-race-');
        file_put_contents($worker, '<?php
require $argv[1]."/vendor/autoload.php";
$app = require $argv[1]."/bootstrap/app.php";
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (!app()->environment("testing") || \\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName() !== $argv[2] || !str_ends_with($argv[2], "_test") || config("mail.default") !== "array") { throw new RuntimeException("Unsafe synthetic race worker"); }
\\Illuminate\\Support\\Facades\\Http::preventStrayRequests();
$actor=\\App\\Models\\User::findOrFail($argv[3]);
\\Illuminate\\Support\\Facades\\Auth::login($actor);
echo "ready\\n"; flush();
try {
$operation=(new \\App\\Services\\Gateways\\Operations\\Refunds)->recordExternal($actor, \\App\\Models\\InvoiceTransaction::findOrFail($argv[4]), "100.00", false, $argv[6], "Synthetic racing refund", "2026-10-01T13:00:00Z", $argv[5]);
echo "claimed:".$operation->id."\\n";
} catch (RuntimeException $e) { if (!str_contains($e->getMessage(), "refundable")) { throw $e; } echo "blocked\\n"; }
');
        $connection = config('database.connections.' . config('database.default'));
        $process = new Process([PHP_BINARY, $worker, base_path(), DB::connection()->getDatabaseName(), (string) $actor->id, (string) $transaction->id, $sameRequest ? $key : (string) Str::uuid(), $sameRequest ? 'synthetic-race-refund' : 'synthetic-second-refund'], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_SOCKET' => $connection['unix_socket'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array',
        ]);
        $process->setTimeout(20);
        $started = false;
        DB::listen(function ($query) use (&$started, $process) {
            if (!$started && str_contains($query->sql, '`invoices`') && str_contains($query->sql, 'for update')) {
                $started = true;
                $process->start();
                $deadline = microtime(true) + 5;
                while (!str_contains($process->getOutput(), 'ready') && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $process->getOutput(), $process->getErrorOutput());
            }
        });
        try {
            $operation = (new Refunds)->recordExternal($actor, $transaction, '100.00', false, 'synthetic-race-refund', 'Synthetic racing refund', '2026-10-01T13:00:00Z', $key);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString($sameRequest ? 'claimed:' . $operation->id : 'blocked', $process->getOutput());
            $this->assertSame(1, PaymentOperation::where('kind', 'external_refund')->count());
            $this->assertSame('100.00', $transaction->fresh()->refunded_amount);
            $this->assertSame(1, $invoice->transactions()->count());
            $this->assertSame('paid', $invoice->fresh()->status);
        } finally {
            $process->stop();
            unlink($worker);
        }
    }

    #[DataProvider('providerClaims')]
    public function test_independent_provider_claims_issue_one_write_per_original_payment(bool $sameRequest, bool $recoverQueued): void
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $actor = User::factory()->create(['role_id' => Role::create(['name' => 'Synthetic provider race administrator', 'permissions' => ['*']])->id]);
        $this->actingAs($actor);
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'pending']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => '100.00', 'quantity' => 1]);
        $gateway = Gateway::create(['name' => 'Synthetic provider race', 'extension' => 'SyntheticOperations', 'type' => 'gateway', 'enabled' => false]);
        $gateway->settings()->create(['key' => 'admin_payment_operations_enabled', 'value' => '1']);
        $transaction = $invoice->transactions()->create(['gateway_id' => $gateway->id, 'amount' => '100.00', 'transaction_id' => 'synthetic-original-payment', 'is_credit_transaction' => false, 'status' => InvoiceTransactionStatus::Succeeded]);
        $fixtureFile = tempnam(sys_get_temp_dir(), 'paymenter-provider-fixture-');
        $writes = tempnam(sys_get_temp_dir(), 'paymenter-provider-writes-');
        $worker = tempnam(sys_get_temp_dir(), 'paymenter-provider-race-');
        file_put_contents($fixtureFile, <<<'FIXTURE'
<?php
namespace Paymenter\Extensions\Gateways\SyntheticOperations;
class SyntheticOperations extends \App\Classes\Extension\Gateway {
    public static string $writesPath;
    public static bool $interruptQueued = false;
    public function pay(\App\Models\Invoice $invoice, $total) { throw new \RuntimeException('Synthetic adapter cannot collect'); }
    public function paymentOperations(): ?\App\Services\Gateways\Operations\Adapter {
        return new class implements \App\Services\Gateways\Operations\Adapter {
            public function capabilities(): array { return ['refund'=>true,'capture'=>false,'reconcile'=>true]; }
            public function fingerprint(): string { if (SyntheticOperations::$interruptQueued && \App\Models\PaymentOperation::where('state','queued')->exists()) { throw new \RuntimeException('Synthetic interruption before execution'); } return hash('sha256','synthetic-race-merchant'); }
            public function prepare(\App\Models\Invoice $invoice, ?\App\Models\InvoiceTransaction $transaction, string $kind, string $providerReference, string $amount, string $currency): array {
                if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0) { throw new \LogicException('Preparation under locks'); }
                return ['authenticated'=>true,'merchant'=>'synthetic-race-merchant','environment'=>'synthetic','provider_object_type'=>'payment',
                    'original_reference'=>$providerReference,'original_amount'=>$transaction->amount,'amount'=>$amount,'currency'=>$currency,
                    'invoice_id'=>$invoice->id,'gateway_id'=>$transaction->gateway_id,'transaction_id'=>$transaction->id,'already_refunded'=>'0.00',
                    'attempt_id'=>null,'attempt_reference'=>null,'merchant_fingerprint'=>null];
            }
            public function execute(\App\Models\PaymentOperation $operation): \App\Services\Gateways\Operations\OperationResult {
                if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0 || $operation->fresh()->state !== 'processing') { throw new \LogicException('No durable claim'); }
                file_put_contents(SyntheticOperations::$writesPath, $operation->request_key."\n", FILE_APPEND|LOCK_EX);
                usleep(100000);
                return new \App\Services\Gateways\Operations\OperationResult('pending','synthetic-race-refund');
            }
            public function reconcile(\App\Models\PaymentOperation $operation): \App\Services\Gateways\Operations\OperationResult {
                if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0) { throw new \LogicException('Readback under locks'); }
                return new \App\Services\Gateways\Operations\OperationResult('succeeded','synthetic-race-refund', $operation->payload['provider_context']+['request_key'=>$operation->request_key,'provider_reference'=>'synthetic-race-refund']);
            }
        };
    }
}
FIXTURE
        );
        if (!class_exists('Paymenter\\Extensions\\Gateways\\SyntheticOperations\\SyntheticOperations', false)) {
            require $fixtureFile;
        }
        SyntheticOperations::$writesPath = $writes;
        file_put_contents($worker, '<?php
require $argv[1]."/vendor/autoload.php";
$app=require $argv[1]."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment("testing") || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== $argv[2] || !str_ends_with($argv[2],"_test") || config("mail.default") !== "array") { throw new RuntimeException("Unsafe provider race worker"); }
\Illuminate\Support\Facades\Http::preventStrayRequests();
require $argv[6];
\Paymenter\Extensions\Gateways\SyntheticOperations\SyntheticOperations::$writesPath=$argv[7];
$actor=\App\Models\User::findOrFail($argv[3]);
\Illuminate\Support\Facades\Auth::login($actor);
echo "ready\n"; flush();
try {
$operation=(new \App\Services\Gateways\Operations\Refunds)->submit($actor, \App\Models\InvoiceTransaction::findOrFail($argv[4]),"100.00",false,"Synthetic provider race",$argv[5]);
echo "claimed:".$operation->id."\n";
} catch (RuntimeException $e) { if (!str_contains($e->getMessage(),"refundable")) { throw $e; } echo "blocked\n"; }
');
        $key = (string) Str::uuid();
        if ($recoverQueued) {
            SyntheticOperations::$interruptQueued = true;
            try {
                (new Refunds)->submit($actor, $transaction, '100.00', false, 'Synthetic provider race', $key);
                $this->fail('Missing pre-execution interruption');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic interruption before execution', $e->getMessage());
            } finally {
                SyntheticOperations::$interruptQueued = false;
            }
            $this->assertSame('queued', PaymentOperation::sole()->state);
            $this->assertSame('', file_get_contents($writes));
        }
        $connection = config('database.connections.' . config('database.default'));
        $process = new Process([PHP_BINARY, $worker, base_path(), DB::connection()->getDatabaseName(), (string) $actor->id, (string) $transaction->id,
            $sameRequest ? $key : (string) Str::uuid(), $fixtureFile, $writes], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                'DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'],
                'DB_PORT' => (string) $connection['port'], 'DB_SOCKET' => $connection['unix_socket'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array',
            ]);
        $process->setTimeout(20);
        $started = false;
        DB::listen(function ($query) use (&$started, $process) {
            if (!$started && str_contains($query->sql, '`invoices`') && str_contains($query->sql, 'for update')) {
                $started = true;
                $process->start();
                $deadline = microtime(true) + 5;
                while (!str_contains($process->getOutput(), 'ready') && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $process->getOutput(), $process->getErrorOutput());
            }
        });
        try {
            try {
                $operation = (new Refunds)->submit($actor, $transaction, '100.00', false, 'Synthetic provider race', $key);
            } catch (\RuntimeException $e) {
                if ($sameRequest || !str_contains($e->getMessage(), 'refundable')) {
                    throw $e;
                }
                $operation = PaymentOperation::where('kind', 'provider_refund')->sole();
            }
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            if ($sameRequest) {
                $this->assertStringContainsString('claimed:' . $operation->id, $process->getOutput());
            } else {
                $this->assertMatchesRegularExpression('/claimed:' . $operation->id . '|blocked/', $process->getOutput());
            }
            $this->assertSame(1, PaymentOperation::where('kind', 'provider_refund')->count());
            $this->assertSame(1, count(file($writes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
            $this->assertSame('100.00', $transaction->fresh()->refunded_amount);
            $this->assertSame('paid', $invoice->fresh()->status);
        } finally {
            $process->stop();
            unlink($worker);
            unlink($fixtureFile);
            unlink($writes);
        }
    }

    public function test_real_factory_uses_committed_settings_after_repeatable_read_snapshot(): void
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'pending']);
        $gateway = Gateway::create(['name' => 'Synthetic config gateway', 'extension' => 'SyntheticConfigOperations', 'type' => 'gateway', 'enabled' => false]);
        $setting = $gateway->settings()->create(['key' => 'synthetic_credential_version', 'value' => 'version-one']);
        $fixture = tempnam(sys_get_temp_dir(), 'paymenter-config-fixture-');
        $worker = tempnam(sys_get_temp_dir(), 'paymenter-config-worker-');
        file_put_contents($fixture, <<<'FIXTURE'
<?php
namespace Paymenter\Extensions\Gateways\SyntheticConfigOperations;
class SyntheticConfigOperations extends \App\Classes\Extension\Gateway {
    public function pay(\App\Models\Invoice $invoice, $total) { throw new \RuntimeException('No synthetic collection'); }
    public function paymentOperations(): ?\App\Services\Gateways\Operations\Adapter {
        return new class($this->gatewayRecord->settings->firstWhere('key','synthetic_credential_version')->value) implements \App\Services\Gateways\Operations\Adapter {
            public function __construct(private string $version) {}
            public function capabilities(): array { return ['refund'=>false,'capture'=>false,'reconcile'=>false]; }
            public function fingerprint(): string { return hash('sha256',$this->version); }
            public function prepare(\App\Models\Invoice $invoice, ?\App\Models\InvoiceTransaction $transaction, string $kind, string $providerReference, string $amount, string $currency): array { throw new \RuntimeException('No provider calls'); }
            public function execute(\App\Models\PaymentOperation $operation): \App\Services\Gateways\Operations\OperationResult { throw new \RuntimeException('No provider writes'); }
            public function reconcile(\App\Models\PaymentOperation $operation): \App\Services\Gateways\Operations\OperationResult { throw new \RuntimeException('No provider reads'); }
        };
    }
}
FIXTURE
        );
        require $fixture;
        file_put_contents($worker, '<?php
require $argv[1]."/vendor/autoload.php";
$app=require $argv[1]."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment("testing") || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== $argv[2] || !str_ends_with($argv[2],"_test") || config("mail.default") !== "array") { throw new RuntimeException("Unsafe settings worker"); }
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\DB::table("settings")->where("id", $argv[3])->update(["value"=>"version-two"]);
echo "changed\n";
');
        $connection = config('database.connections.' . config('database.default'));
        $process = new Process([PHP_BINARY, $worker, base_path(), DB::connection()->getDatabaseName(), (string) $setting->id], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MAIL_MAILER' => 'array', 'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_SOCKET' => $connection['unix_socket'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array',
        ]);
        $process->setTimeout(15);
        try {
            DB::transaction(function () use ($gateway, $invoice, $process) {
                // Establish the old consistent snapshot, then let another connection commit.
                $this->assertSame('version-one', $gateway->settings()->where('key', 'synthetic_credential_version')->value('value'));
                $process->run();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertStringContainsString('changed', $process->getOutput());
                $gateway = Gateway::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
                (new InvoicePaymentDependencies)->lock([$invoice->id]);
                $adapter = (new GatewayOperations)->for($gateway);
                $this->assertSame(hash('sha256', 'version-two'), $adapter->fingerprint());
            });
        } finally {
            $process->stop();
            unlink($fixture);
            unlink($worker);
        }
    }

    #[DataProvider('changedPolicyFacts')]
    public function test_policy_uses_committed_role_flag_and_hold_after_old_snapshot(string $fact): void
    {
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $actor = User::factory()->create(['role_id' => Role::create(['name' => 'Synthetic current policy administrator', 'permissions' => ['*']])->id]);
        $this->actingAs($actor);
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'pending']);
        $gateway = Gateway::create(['name' => 'Synthetic policy gateway', 'type' => 'gateway', 'extension' => 'Wave', 'enabled' => false]);
        $setting = $gateway->settings()->create(['key' => 'admin_payment_operations_enabled', 'value' => '1']);
        $worker = tempnam(sys_get_temp_dir(), 'paymenter-policy-worker-');
        file_put_contents($worker, '<?php
require $argv[1]."/vendor/autoload.php";
$app=require $argv[1]."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment("testing") || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== $argv[2] || !str_ends_with($argv[2],"_test") || config("mail.default") !== "array") { throw new RuntimeException("Unsafe policy worker"); }
\Illuminate\Support\Facades\Http::preventStrayRequests();
if ($argv[3] === "role") { \Illuminate\Support\Facades\DB::table("roles")->where("id",$argv[4])->update(["permissions"=>"[]"]); }
elseif ($argv[3] === "flag") { \Illuminate\Support\Facades\DB::table("settings")->where("id",$argv[4])->update(["value"=>"0"]); }
else { \Illuminate\Support\Facades\DB::table("billmanager_holds")->insert(["model_type"=>\App\Models\Invoice::class,"model_id"=>$argv[4],"reason"=>"Synthetic current policy hold"]); }
echo "changed\n";
');
        $connection = config('database.connections.' . config('database.default'));
        $id = match ($fact) {
            'role' => $actor->role_id, 'flag' => $setting->id, default => $invoice->id
        };
        $process = new Process([PHP_BINARY, $worker, base_path(), DB::connection()->getDatabaseName(), $fact, (string) $id], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'MAIL_MAILER' => 'array', 'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_SOCKET' => $connection['unix_socket'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array',
        ]);
        $process->setTimeout(15);
        try {
            DB::transaction(function () use ($actor, $invoice, $gateway, $setting, $process, $fact) {
                // Ordinary discovery establishes the old snapshot without retaining write locks.
                $this->assertSame(['*'], Role::findOrFail($actor->role_id)->permissions);
                $this->assertSame('1', $gateway->settings()->whereKey($setting->id)->value('value'));
                $this->assertFalse(MigrationHold::isHeld($invoice));
                $process->run();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertStringContainsString('changed', $process->getOutput());
                $gateway = Gateway::whereKey($gateway->id)->lockForUpdate()->firstOrFail();
                $invoice = (new InvoicePaymentDependencies)->lock([$invoice->id])->firstWhere('id', $invoice->id);
                try {
                    (new OperationPolicy)->authorize($actor, 'refund', $invoice, $gateway);
                    $this->fail('Old snapshot authorized a revoked or held payment operation');
                } catch (AuthorizationException|\RuntimeException $e) {
                    $this->assertStringContainsString(match ($fact) {
                        'role' => 'dedicated', 'flag' => 'disabled', default => 'migration hold'
                    }, $e->getMessage());
                    $this->assertSame(0, PaymentOperation::count());
                }
            });
        } finally {
            $process->stop();
            unlink($worker);
        }
    }

    public static function changedPolicyFacts(): array
    {
        return [['role'], ['flag'], ['hold']];
    }

    public static function providerClaims(): array
    {
        return [[true, false], [false, false], [true, true]];
    }

    public static function claims(): array
    {
        return [[true], [false]];
    }
}
