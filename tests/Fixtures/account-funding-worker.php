<?php

use App\Models\AccountFundingAllocation;
use App\Models\Gateway;
use App\Models\GatewayPaymentAttempt;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\Accounts\AccountFundingReversals;
use App\Services\Accounts\InsufficientAccountFunding;
use App\Services\Accounts\InvoiceFunding;
use App\Services\Accounts\OpeningAuthority;
use App\Services\Gateways\Operations\Adapter;
use App\Services\Gateways\Operations\GatewayOperations;
use App\Services\Gateways\Operations\ProviderOperations;
use App\Services\Gateways\Operations\Refunds;
use App\Services\Gateways\PaymentAttempts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\Accounts\RaceRefundAdapter;
use Tests\Fixtures\Accounts\SyntheticOpeningAuthority;
use Tests\Fixtures\FeeGateway;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!app()->environment('testing') || DB::connection()->getDatabaseName() !== $argv[1] || !str_ends_with($argv[1], '_test') || config('mail.default') !== 'array' || config('database.connections.' . config('database.default') . '.unix_socket') !== getenv('ACCOUNT_FUNDING_TEST_SOCKET')) {
    throw new RuntimeException('Unsafe account race worker identity.');
}
config(['account-funding.enabled' => true]);
app()->instance(OpeningAuthority::class, new SyntheticOpeningAuthority);
Bus::fake();
Mail::fake();
Http::preventStrayRequests();
$request = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
class_exists(FeeGateway::class);
Auth::login(User::findOrFail($request['actor']));
$adapter = null;
if (in_array($request['action'], ['refund', 'resume', 'reconcile'], true)) {
    $adapter = new RaceRefundAdapter;
    $adapter->mode = $request['mode'] ?? 'pending';
    $adapter->crashBoundary = $request['crash'] ?? null;
    app()->instance(GatewayOperations::class, new class($adapter) extends GatewayOperations
    {
        public function __construct(private Adapter $adapter) {}

        public function for(Gateway $gateway): Adapter
        {
            return $this->adapter;
        }
    });
}
echo 'ready:' . DB::selectOne('SELECT CONNECTION_ID() AS id')->id . "\n";
flush();
try {
    if ($request['action'] === 'fund') {
        $result = (new InvoiceFunding)->fund(Auth::user(), Invoice::findOrFail($request['record']), $request['amount'], $request['key']);
    } elseif ($request['action'] === 'reverse') {
        $result = (new AccountFundingReversals)->reverse(Auth::user(), AccountFundingAllocation::findOrFail($request['record']), $request['amount'], 'Synthetic concurrent correction', $request['key']);
    } elseif ($request['action'] === 'settle') {
        $attempt = GatewayPaymentAttempt::findOrFail($request['record']);
        (new PaymentAttempts)->settle($attempt->gateway, $attempt->reference, $attempt->merchant_fingerprint, $attempt->amount, $attempt->currency_code, 'synthetic-concurrent-deposit');
        $result = $attempt->invoice->transactions()->where('gateway_id', $attempt->gateway_id)->where('transaction_id', 'gateway:' . $attempt->gateway_id . ':synthetic-concurrent-deposit')->sole();
    } elseif ($request['action'] === 'refund') {
        $result = (new Refunds)->submit(Auth::user(), InvoiceTransaction::findOrFail($request['record']), $request['amount'], false, 'Synthetic concurrent refund', $request['key']);
    } elseif ($request['action'] === 'resume') {
        $result = (new ProviderOperations)->resume(Auth::user(), PaymentOperation::findOrFail($request['record']));
    } elseif ($request['action'] === 'reconcile') {
        $result = (new ProviderOperations)->reconcile(Auth::user(), PaymentOperation::findOrFail($request['record']));
    } else {
        throw new LogicException('Unknown synthetic account race action.');
    }
    if (($request['crash'] ?? null) === 'verified') {
        echo 'boundary:verified:' . $result->id . "\n";
        flush();
        while (true) {
            usleep(10000);
        }
    }
    echo json_encode(['result' => 'written', 'id' => $result->id] + ($adapter ? ['writes' => $adapter->writes, 'reads' => $adapter->reads] : []), JSON_THROW_ON_ERROR) . "\n";
} catch (InsufficientAccountFunding $e) {
    echo json_encode(['result' => 'blocked', 'kind' => 'capacity']) . "\n";
} catch (QueryException $e) {
    if (($e->errorInfo[1] ?? null) !== 1213) {
        throw $e;
    }
    echo json_encode(['result' => 'blocked', 'kind' => 'deadlock']) . "\n";
} catch (AuthorizationException $e) {
    echo json_encode(['result' => 'blocked', 'kind' => 'permission']) . "\n";
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    if ($message === 'Internal reversal exceeds the remaining original allocation.') {
        $kind = 'capacity';
    } elseif (str_contains($message, 'hold')) {
        $kind = 'hold';
    } elseif (str_contains($message, 'reconciliation')) {
        $kind = 'reconciliation';
    } elseif ($message === 'Account payment dependency graph expanded; restart before locking a wallet.') {
        $kind = 'graph';
    } else {
        throw $e;
    }
    echo json_encode(['result' => 'blocked', 'kind' => $kind]) . "\n";
}
Http::assertNothingSent();
Mail::assertNothingSent();
