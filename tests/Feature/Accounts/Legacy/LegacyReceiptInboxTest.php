<?php

namespace Tests\Feature\Accounts\Legacy;

use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Models\LegacyReceiptBudget;
use App\Models\LegacyReceiptConflict;
use App\Services\Accounts\Legacy\LegacyRetentionResult;
use DomainException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\LegacyReceipts\GoldenVectors;
use Tests\Fixtures\LegacyReceipts\LegacyReceiptFixture as Fixture;
use Tests\TestCase;

#[Group('opening-native')]
final class LegacyReceiptInboxTest extends TestCase
{
    use UsesCommittedDatabase;

    public function test_disabled_missing_and_unprotected_trust_refuse_without_mutation(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $original = config('legacy-payments');
            foreach ([['receipt_ingress_enabled' => false], ['trust_path' => null], ['trust_reader_gid' => null], ['trust_path' => '/missing/fictional-trust']] as $change) {
                config(['legacy-payments' => [...$original, ...$change]]);
                $this->denied(fn () => $inbox->retain($payload, $signature));
            }
            self::assertSame(0, LegacyReceiptBudget::count());
            self::assertSame(0, LegacyEffect::count());
        });
    }

    public function test_ambient_transaction_is_refused_before_any_retention(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            DB::transaction(function () use ($inbox, $payload, $signature) {
                $this->denied(fn () => $inbox->retain($payload, $signature));
                self::assertSame(1, DB::transactionLevel());
                self::assertSame(0, LegacyReceiptBudget::count());
            });
            self::assertSame(0, LegacyObservation::count());
        });
    }

    public function test_exact_bytes_replay_original_pending_receipt_with_zero_charge(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $a = $inbox->retain($payload, $signature);
            self::assertInstanceOf(LegacyRetentionResult::class, $a);
            self::assertFalse($a->replay);
            self::assertSame('pending_source_qualification', $a->disposition);
            self::assertSame(0, DB::transactionLevel());
            $budget = LegacyReceiptBudget::firstOrFail()->getAttributes();
            $b = $inbox->retain($payload, $signature);
            self::assertTrue($b->replay);
            self::assertSame($a->observationId, $b->observationId);
            self::assertSame($a->observationKey, $b->observationKey);
            self::assertSame($a->effectKey, $b->effectKey);
            self::assertSame($budget, LegacyReceiptBudget::firstOrFail()->getAttributes());
            self::assertSame(1, LegacyEffect::count());
            self::assertSame(1, LegacyObservation::count());
            $effect = LegacyEffect::firstOrFail();
            self::assertSame(strlen($payload) + strlen($signature) + strlen($effect->identity_json) + strlen($effect->economic_json), (int) $budget['retained_bytes']);
            self::assertSame($payload, LegacyObservation::firstOrFail()->payload_bytes);
            self::assertSame($signature, LegacyObservation::firstOrFail()->signature_bytes);
            self::assertSame(['effectKey', 'observationKey', 'observationId', 'disposition', 'replay'], array_keys(get_object_vars($a)));
        });
    }

    public function test_new_observation_keeps_one_effect_and_counts_only_unique_text(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $a = $inbox->retain($payload, $signature);
            $original = LegacyEffect::firstOrFail()->getAttributes();
            $base = LegacyReceiptBudget::firstOrFail()->retained_bytes;
            [$other, $sig] = Fixture::delivery(['schema_version' => 2, 'nonce' => 'delivery-two']);
            $b = $inbox->retain($other, $sig);
            self::assertFalse($b->replay);
            self::assertSame($a->effectKey, $b->effectKey);
            self::assertNotSame($a->observationId, $b->observationId);
            self::assertSame('pending_source_qualification', $b->disposition);
            self::assertSame($original, LegacyEffect::firstOrFail()->getAttributes());
            self::assertSame(2, LegacyObservation::count());
            self::assertSame($base + strlen($other) + strlen($sig), LegacyReceiptBudget::firstOrFail()->retained_bytes);
            self::assertSame(2, LegacyReceiptBudget::firstOrFail()->retained_observations);
            self::assertSame(0, LegacyReceiptConflict::count());
        });
    }

    public function test_altered_economics_append_immutable_conflict_and_preserve_first_facts(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $inbox->retain($payload, $signature);
            $original = LegacyEffect::firstOrFail()->getAttributes();
            $economic = GoldenVectors::economic();
            $economic['allocation_evidence_sha256'] = str_repeat('d', 64);
            [$other, $sig] = Fixture::delivery(['nonce' => 'conflicting-delivery', 'economic' => $economic]);
            $b = $inbox->retain($other, $sig);
            self::assertSame('economic_conflict', $b->disposition);
            self::assertSame($original, LegacyEffect::firstOrFail()->getAttributes());
            self::assertSame(1, LegacyReceiptConflict::count());
            $conflict = LegacyReceiptConflict::firstOrFail();
            self::assertSame($original['economic_sha256'], $conflict->expected_economic_sha256);
            self::assertSame(LegacyObservation::findOrFail($b->observationId)->economic_sha256, $conflict->observed_economic_sha256);
            self::assertTrue($inbox->retain($other, $sig)->replay);
            self::assertSame(1, LegacyReceiptConflict::count());
        });
    }

    public function test_byte_and_count_quota_refusals_leave_no_partial_rows(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            Fixture::replaceTrust(['retained_byte_limit' => strlen($payload) + strlen($signature)]);
            $this->denied(fn () => $inbox->retain($payload, $signature));
            self::assertSame(0, LegacyReceiptBudget::count());
            self::assertSame(0, LegacyEffect::count());
            Fixture::replaceTrust(['retained_observation_limit' => 1]);
            $inbox->retain($payload, $signature);
            $before = $this->receipts();
            [$other, $sig] = Fixture::delivery(['nonce' => 'over-count-limit']);
            $this->denied(fn () => $inbox->retain($other, $sig));
            self::assertSame($before, $this->receipts());
        });
    }

    public function test_lowered_quota_permits_exact_replay_but_never_new_evidence_or_new_budget(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $a = $inbox->retain($payload, $signature);
            $before = $this->receipts();
            Fixture::replaceTrust(['retained_byte_limit' => 1, 'retained_observation_limit' => 1]);
            $b = $inbox->retain($payload, $signature);
            self::assertTrue($b->replay);
            self::assertSame($a->observationId, $b->observationId);
            [$other, $sig] = Fixture::delivery(['nonce' => 'new-after-reduction']);
            $this->denied(fn () => $inbox->retain($other, $sig));
            self::assertSame($before, $this->receipts());
            self::assertSame(1, LegacyReceiptBudget::count());
        });
    }

    public function test_exact_byte_capacity_accepts_once_and_conflict_overflow_changes_nothing(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $vector = GoldenVectors::vectors()[0];
            $charge = strlen($payload) + strlen($signature) + strlen($vector['identity_bytes']) + strlen($vector['economic_bytes']);
            Fixture::replaceTrust(['retained_byte_limit' => $charge]);
            $inbox->retain($payload, $signature);
            self::assertSame($charge, LegacyReceiptBudget::firstOrFail()->retained_bytes);
            $before = $this->receipts();
            $economic = [...GoldenVectors::economic(), 'allocation_evidence_sha256' => str_repeat('d', 64)];
            [$other, $sig] = Fixture::delivery(['economic' => $economic]);
            $this->denied(fn () => $inbox->retain($other, $sig));
            self::assertSame($before, $this->receipts());
            self::assertSame(0, LegacyReceiptConflict::count());
        });
    }

    public function test_invalid_signature_is_rejected_before_transaction_or_budget(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $this->denied(fn () => $inbox->retain($payload . ' ', $signature));
            self::assertSame(0, LegacyReceiptBudget::count());
            self::assertSame(0, LegacyObservation::count());
            self::assertSame(0, DB::transactionLevel());
        });
    }

    public function test_change_after_guard_savepoint_is_rejected_before_outer_commit(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $connection = DB::connection();
            $dispatcher = $connection->getEventDispatcher();
            $connection->setEventDispatcher(clone $dispatcher);
            $connection->getEventDispatcher()->listen(TransactionCommitted::class, function () {
                if (DB::transactionLevel() === 1) {
                    Fixture::replaceTrust(['revoked_keys' => ['fixture-key']]);
                }
            });
            try {
                $this->denied(fn () => $inbox->retain($payload, $signature));
                self::assertSame(0, LegacyEffect::count());
                self::assertSame(0, LegacyReceiptBudget::count());
            } finally {
                $connection->setEventDispatcher($dispatcher);
            }
        });
    }

    public function test_missing_budget_cannot_reset_retained_usage(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $inbox->retain($payload, $signature);
            DB::table('legacy_receipt_budgets')->delete(); // privileged damage, never an inbox path
            [$other, $sig] = Fixture::delivery(['nonce' => 'after-damage']);
            $before = $this->receipts();
            $this->denied(fn () => $inbox->retain($other, $sig));
            self::assertSame($before, $this->receipts());
        });
    }

    #[DataProvider('changes')]
    public function test_current_policy_configuration_custody_and_time_are_rechecked_before_commit(string $change): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) use ($change) {
            $before = $this->receipts();
            LegacyObservation::created(function () use ($change) {
                match ($change) {
                    'revoked-key' => Fixture::replaceTrust(['revoked_keys' => ['fixture-key']]),
                    'revoked-nonce' => Fixture::replaceTrust(['revoked_nonces' => ['delivery-one']]),
                    'policy-replaced' => Fixture::replaceTrust(['retained_byte_limit' => 2000000]),
                    'lower-capacity' => Fixture::replaceTrust(['retained_byte_limit' => 1]),
                    'disabled' => config(['legacy-payments.receipt_ingress_enabled' => false]),
                    'path-changed' => config(['legacy-payments.trust_path' => '/missing/replacement']),
                    'custody' => chmod(config('legacy-payments.trust_path'), 0660),
                    'expired' => Fixture::advanceClock('2026-01-02T00:00:00Z'),
                };
            });
            try {
                $this->denied(fn () => $inbox->retain($payload, $signature));
                self::assertSame($before, $this->receipts());
                self::assertSame(0, DB::transactionLevel());
            } finally {
                LegacyObservation::flushEventListeners();
            }
        });
    }

    public static function changes(): array
    {
        return array_map(fn ($value) => [$value], ['revoked-key', 'revoked-nonce', 'policy-replaced', 'lower-capacity', 'disabled', 'path-changed', 'custody', 'expired']);
    }

    public function test_reused_inbox_reauthenticates_even_exact_replay(): void
    {
        Fixture::withProtectedIngress(function ($inbox, $payload, $signature) {
            $inbox->retain($payload, $signature);
            $before = $this->receipts();
            Fixture::replaceTrust(['revoked_keys' => ['fixture-key']]);
            $this->denied(fn () => $inbox->retain($payload, $signature));
            self::assertSame($before, $this->receipts());
        });
    }

    public function test_signed_commit_exclusion_and_manual_claims_have_no_money_or_external_authority(): void
    {
        Fixture::withProtectedIngress(function ($inbox) {
            Mail::fake();
            Bus::fake();
            Queue::fake();
            Http::preventStrayRequests();
            Http::fake();
            $tables = ['account_wallets', 'account_movements', 'credits', 'account_opening_receipts', 'account_opening_batches', 'invoice_paid_processings', 'billmanager_holds', 'invoices', 'invoice_transactions', 'payment_operations', 'jobs', 'services', 'email_logs'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->get()->toJson();
            }
            $dispatcher = DB::connection()->getEventDispatcher();
            DB::connection()->setEventDispatcher(clone $dispatcher);
            $businessWrites = [];
            DB::listen(function ($query) use (&$businessWrites) {
                if (preg_match('/^(insert|update|delete|replace)\b/i', $query->sql) && !str_contains($query->sql, 'legacy_')) {
                    $businessWrites[] = $query->sql;
                }
            });
            try {
                $identity = [...GoldenVectors::identity(), 'effect_kind' => 'manual_settlement'];
                $observation = json_decode(Fixture::payload(), true, flags: JSON_THROW_ON_ERROR)['observation'];
                $observation['validation_ref'] = 'claims-native-commit-and-opening-exclusion';
                $observation['commit_binding_ref'] = 'claims-manual-authority';
                [$payload, $signature] = Fixture::delivery(compact('identity', 'observation'));
                $result = $inbox->retain($payload, $signature);
                self::assertSame('pending_source_qualification', $result->disposition);
                foreach ($tables as $table) {
                    self::assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
                }
                self::assertSame([], $businessWrites);
                Mail::assertNothingSent();
                Mail::assertNothingQueued();
                Bus::assertNothingDispatched();
                Queue::assertNothingPushed();
                Http::assertNothingSent();
            } finally {
                DB::connection()->setEventDispatcher($dispatcher);
            }
        });
    }

    private function receipts(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['legacy_effects', 'legacy_observations', 'legacy_receipt_conflicts', 'legacy_receipt_budgets']);
    }

    private function denied(\Closure $call): void
    {
        $denied = false;
        try {
            $call();
        } catch (DomainException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Expected explicit retention refusal, not SQL/bootstrap failure.');
    }
}
