<?php

namespace Tests\Concerns;

use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Support\Testing\Fakes\MailFake;
use PHPUnit\Framework\Assert;
use ReflectionProperty;
use Tests\Fixtures\Opening\ProviderInvocationSpy;

trait AssertsOpeningIsolation
{
    private array $openingIsolationBaseline;

    public function captureOpeningIsolation(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake();
        ProviderInvocationSpy::$invocations = [];
        $this->openingIsolationBaseline = $this->originalOpeningState();
    }

    public function assertOpeningIsolation(): void
    {
        $bus = Bus::getFacadeRoot();
        Assert::assertInstanceOf(BusFake::class, $bus, 'Opening isolation requires captured Bus channels.');
        foreach (['commands' => 'bus.regular', 'commandsSync' => 'bus.sync', 'commandsAfterResponse' => 'bus.after_response', 'batches' => 'bus.batch'] as $property => $channel) {
            Assert::assertEmpty((new ReflectionProperty($bus, $property))->getValue($bus), 'Opening side effect: ' . $channel);
        }
        $mail = Mail::getFacadeRoot();
        Assert::assertInstanceOf(MailFake::class, $mail, 'Opening isolation requires captured Mail channels.');
        foreach (['mailables' => 'mail.sent', 'queuedMailables' => 'mail.queued'] as $property => $channel) {
            Assert::assertEmpty((new ReflectionProperty($mail, $property))->getValue($mail), 'Opening side effect: ' . $channel);
        }
        Assert::assertCount(0, Http::recorded(), 'Opening side effect: http.attempt');
        Assert::assertEmpty(ProviderInvocationSpy::$invocations, 'Opening side effect: provider.invocation');
        Assert::assertSame($this->openingIsolationBaseline, $this->originalOpeningState(), 'Opening side effect: original_database_state');
    }

    private function originalOpeningState(): array
    {
        $state = (new OpeningTargetState)->capture();
        // Only the native operator's four tables may differ. The receipt/delta
        // verifier separately proves each of their exact permitted changes.
        // All original payment/job/archive/hold rows, DDL and allocators remain
        // here, together with routines, triggers and events.
        foreach (['account_wallets', 'credits', 'account_opening_batches', 'account_opening_receipts'] as $table) {
            unset($state['tables'][$table]);
        }

        return $state;
    }
}
