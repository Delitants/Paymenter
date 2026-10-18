<?php

namespace Tests\Feature\Accounts\Legacy;

use App\Models\LegacyEffect;
use App\Models\LegacyObservation;
use App\Models\LegacyReceiptBudget;
use App\Models\LegacyReceiptConflict;
use App\Services\Accounts\Legacy\AuthenticatedLegacyObservation;
use App\Services\Accounts\Legacy\LegacyClock;
use App\Services\Accounts\Legacy\LegacyReceiptWriteGuard;
use App\Services\Accounts\Legacy\LegacyRetentionContext;
use App\Services\Accounts\Legacy\LegacyTransportTrust;
use App\Services\Accounts\Legacy\LegacyTrustReader;
use App\Services\BillmanagerMigration\Opening\CanonicalPolicy;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\LegacyReceipts\LegacyReceiptFixture as Fixture;
use Tests\TestCase;

#[Group('opening-native')]
final class LegacyReceiptSchemaTest extends TestCase
{
    use UsesCommittedDatabase;

    public function test_additive_schema_and_boot_create_no_evidence_or_authority(): void
    {
        foreach (['legacy_effects', 'legacy_observations', 'legacy_receipt_conflicts', 'legacy_receipt_budgets'] as $table) {
            self::assertTrue(Schema::hasTable($table));
            self::assertSame(0, DB::table($table)->count());
        }
        self::assertFalse(config('legacy-payments.receipt_ingress_enabled'));
        self::assertNull(config('legacy-payments.trust_path'));
        self::assertNull(config('legacy-payments.trust_reader_gid'));
        foreach (['account_wallets', 'account_movements', 'credits', 'invoices', 'payment_operations', 'jobs'] as $table) {
            self::assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_unguarded_and_quiet_instance_creates_fail_before_sql(): void
    {
        foreach ([LegacyEffect::class, LegacyObservation::class, LegacyReceiptConflict::class, LegacyReceiptBudget::class] as $model) {
            $this->domainDenied(fn () => $model::create([]));
            $this->domainDenied(fn () => (new $model)->saveQuietly());
            self::assertSame(0, $model::count());
        }
    }

    public function test_immutable_eloquent_bulk_paths_refuse_before_sql(): void
    {
        foreach ([LegacyEffect::class, LegacyObservation::class, LegacyReceiptConflict::class] as $model) {
            foreach ([fn () => $model::query()->insert([]), fn () => $model::query()->insertOrIgnore([]),
                fn () => $model::query()->upsert([], ['id']), fn () => $model::query()->update([]),
                fn () => $model::query()->delete(), fn () => $model::query()->forceDelete(),
                fn () => $model::query()->increment('id'), fn () => $model::query()->decrement('id'),
                fn () => $model::query()->truncate(), fn () => $model::query()->insertGetId([]),
                fn () => $model::query()->updateOrInsert(['id' => 1], []),
            ] as $write) {
                $this->domainDenied($write);
            }
        }
    }

    public function test_exact_context_can_save_receipt_and_mutable_budget_but_no_rewrites(): void
    {
        $this->context(function ($context, $auth) {
            DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, fn () => $this->insertReceipt($auth)));
            self::assertSame(1, LegacyEffect::count());
            self::assertSame(1, LegacyObservation::count());
            self::assertSame(1, LegacyReceiptBudget::firstOrFail()->retained_observations);
            $effect = LegacyEffect::firstOrFail();
            $this->domainDenied(fn () => $effect->updateQuietly(['economic_sha256' => str_repeat('d', 64)]));
            $this->domainDenied(fn () => $effect->deleteQuietly());
            $this->domainDenied(fn () => LegacyReceiptBudget::firstOrFail()->increment('retained_observations'));
            $this->domainDenied(fn () => LegacyReceiptBudget::firstOrFail()->update(['retained_bytes' => 0]));
            self::assertSame($auth->economic->fingerprint(), $effect->fresh()->economic_sha256);
        });
    }

    public function test_context_cannot_change_original_facts_or_commit_partial_evidence(): void
    {
        $this->context(function ($context, $auth) {
            $this->domainDenied(fn () => DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, function () use ($auth) {
                LegacyEffect::create([...$this->effectAttributes($auth), 'economic_json' => '{}']);
            })));
            $this->domainDenied(fn () => DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, fn () => LegacyEffect::create($this->effectAttributes($auth)))));
            self::assertSame(0, LegacyEffect::count());
            self::assertSame(0, LegacyReceiptBudget::count());
        });
    }

    public function test_nested_scope_and_exception_do_not_leak_write_permission(): void
    {
        $this->context(function ($context, $auth) {
            $this->domainDenied(fn () => DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, fn () => LegacyReceiptWriteGuard::during($context, fn () => null))));
            try {
                DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, function () {
                    LegacyReceiptBudget::create(['store_key' => 'legacy-receipt-store-v1', 'retained_bytes' => 0, 'retained_observations' => 0]);
                    throw new \RuntimeException('fixture failure');
                }));
                self::fail('Expected fixture exception');
            } catch (\RuntimeException $exception) {
                self::assertSame('fixture failure', $exception->getMessage());
            }
            self::assertSame(0, LegacyReceiptBudget::count());
            $this->domainDenied(fn () => LegacyEffect::create($this->effectAttributes($auth)));
            DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, fn () => $this->insertReceipt($auth)));
            self::assertSame(1, LegacyObservation::count());
        });
    }

    public function test_caller_invented_trust_and_missing_transaction_cannot_authorize_writes(): void
    {
        $this->context(function ($context, $auth, $trust, $keys) {
            $this->domainDenied(fn () => LegacyReceiptWriteGuard::during($context, fn () => null));
            $invented = LegacyTransportTrust::fromBytes(CanonicalPolicy::bytes(Fixture::trust($keys, ['retained_byte_limit' => 500000])));
            $other = AuthenticatedLegacyObservation::verify($auth->payloadBytes, $auth->signatureBytes, $invented, new DateTimeImmutable('2026-01-01T00:01:00Z'));
            $context = LegacyRetentionContext::fromObservation($other, $invented);
            $this->domainDenied(fn () => DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, fn () => $this->insertReceipt($other))));
            self::assertSame(0, LegacyEffect::count());
        });
    }

    public function test_private_evidence_is_hidden_from_model_serialization(): void
    {
        self::assertArrayNotHasKey('identity_json', (new LegacyEffect(['identity_json' => 'private']))->toArray());
        self::assertArrayNotHasKey('economic_json', (new LegacyEffect(['economic_json' => 'private']))->toArray());
        $value = (new LegacyObservation(['payload_bytes' => 'private', 'signature_bytes' => 'private']))->toArray();
        self::assertArrayNotHasKey('payload_bytes', $value);
        self::assertArrayNotHasKey('signature_bytes', $value);
    }

    public function test_physical_uniqueness_and_restrict_delete_on_independent_connection(): void
    {
        $this->context(function ($context, $auth) {
            DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, fn () => $this->insertReceipt($auth)));
            $config = DB::connection()->getConfig();
            $pdo = new \PDO('mysql:unix_socket=' . $config['unix_socket'] . ';dbname=paymenter_test', $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            try {
                $pdo->exec('INSERT INTO legacy_effects (effect_key,economic_sha256,identity_json,economic_json,created_at) SELECT effect_key,economic_sha256,identity_json,economic_json,created_at FROM legacy_effects');
                self::fail('Unique effect constraint missing');
            } catch (\PDOException $exception) {
                self::assertSame('23000', $exception->getCode());
            }
            try {
                $pdo->exec('DELETE FROM legacy_effects');
                self::fail('Restrict-delete constraint missing');
            } catch (\PDOException $exception) {
                self::assertSame('23000', $exception->getCode());
            }
            foreach ([
                'legacy_observations' => 'effect_id,observation_key,payload_bytes,signature_bytes,payload_sha256,signature_sha256,economic_sha256,policy_sha256,disposition,created_at',
                'legacy_receipt_budgets' => 'store_key,retained_bytes,retained_observations',
            ] as $table => $columns) {
                try {
                    $pdo->exec('INSERT INTO ' . $table . ' (' . $columns . ') SELECT ' . $columns . ' FROM ' . $table);
                    self::fail('Physical unique constraint missing: ' . $table);
                } catch (\PDOException $exception) {
                    self::assertSame('23000', $exception->getCode());
                }
            }
            $rules = $pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('legacy_observations','legacy_receipt_conflicts')")->fetchAll(\PDO::FETCH_COLUMN);
            self::assertSame(['RESTRICT', 'RESTRICT', 'RESTRICT'], $rules);
            self::assertSame(1, LegacyEffect::count());
            self::assertSame(1, LegacyObservation::count());
        });
    }

    public function test_schema_rollback_refuses_evidence_but_empty_schema_can_reverse(): void
    {
        $migration = require base_path('database/migrations/2026_10_18_000001_create_legacy_receipt_inbox.php');
        $migration->down();
        self::assertFalse(Schema::hasTable('legacy_effects'));
        $migration->up();
        $this->context(function ($context, $auth) use ($migration) {
            DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, fn () => $this->insertReceipt($auth)));
            $this->domainDenied(fn () => $migration->down());
            self::assertSame(1, LegacyEffect::count());
            self::assertSame(1, LegacyObservation::count());
        });
    }

    public function test_creating_observer_cannot_change_evidence_before_sql(): void
    {
        $this->context(function ($context, $auth) {
            $connection = DB::connection();
            $dispatcher = $connection->getEventDispatcher();
            $connection->setEventDispatcher(clone $dispatcher);
            $effectInsert = false;
            $connection->listen(function ($query) use (&$effectInsert) {
                if (preg_match('/^insert into [`"]?legacy_effects/i', $query->sql)) {
                    $effectInsert = true;
                }
            });
            LegacyEffect::creating(fn ($model) => $model->economic_json = '{}');
            try {
                $this->domainDenied(fn () => DB::transaction(fn () => LegacyReceiptWriteGuard::during($context, fn () => $this->insertReceipt($auth))));
                self::assertFalse($effectInsert, 'Changed observer facts must be denied before evidence SQL.');
                self::assertSame(0, LegacyEffect::count());
                self::assertSame(0, LegacyReceiptBudget::count());
            } finally {
                LegacyEffect::flushEventListeners();
                $connection->setEventDispatcher($dispatcher);
            }
        });
    }

    public function test_nonzero_usage_alone_prevents_schema_rollback(): void
    {
        // Owned fixture SQL exercises the migration boundary independently of model guards.
        DB::table('legacy_receipt_budgets')->insert(['store_key' => 'legacy-receipt-store-v1', 'retained_bytes' => 1, 'retained_observations' => 0]);
        $migration = require base_path('database/migrations/2026_10_18_000001_create_legacy_receipt_inbox.php');
        $this->domainDenied(fn () => $migration->down());
        self::assertSame(1, LegacyReceiptBudget::firstOrFail()->retained_bytes);
        self::assertTrue(Schema::hasTable('legacy_observations'));
    }

    private function context(\Closure $test): mixed
    {
        $keys = Fixture::keyPair();

        return Fixture::withProtectedTrust(Fixture::trust($keys), function ($path, $gid) use ($test, $keys) {
            config(['legacy-payments.receipt_ingress_enabled' => true, 'legacy-payments.trust_path' => $path, 'legacy-payments.trust_reader_gid' => $gid]);
            app()->instance(LegacyClock::class, new class implements LegacyClock
            {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-01-01T00:01:00Z');
                }
            });
            $trust = LegacyTrustReader::read($path, $gid);
            $payload = Fixture::payload();
            $auth = AuthenticatedLegacyObservation::verify($payload, Fixture::signature($payload, $keys), $trust, app(LegacyClock::class)->now());

            return $test(LegacyRetentionContext::fromObservation($auth, $trust), $auth, $trust, $keys);
        });
    }

    private function effectAttributes($auth): array
    {
        return ['effect_key' => $auth->identity->key(), 'economic_sha256' => $auth->economic->fingerprint(), 'identity_json' => CanonicalPolicy::bytes($auth->identity->data()), 'economic_json' => CanonicalPolicy::bytes($auth->economic->data()), 'created_at' => '2026-01-01 00:01:00'];
    }

    private function insertReceipt($auth): void
    {
        $budget = LegacyReceiptBudget::create(['store_key' => 'legacy-receipt-store-v1', 'retained_bytes' => 0, 'retained_observations' => 0]);
        $attributes = $this->effectAttributes($auth);
        $effect = LegacyEffect::create($attributes);
        LegacyObservation::create(['effect_id' => $effect->id, 'observation_key' => hash('sha256', "legacy-observation-v1\0" . $auth->payloadSha256 . $auth->signatureSha256),
            'payload_bytes' => $auth->payloadBytes, 'signature_bytes' => $auth->signatureBytes, 'payload_sha256' => $auth->payloadSha256, 'signature_sha256' => $auth->signatureSha256,
            'economic_sha256' => $auth->economic->fingerprint(), 'policy_sha256' => $auth->policySha256, 'disposition' => 'pending_source_qualification', 'created_at' => '2026-01-01 00:01:00']);
        $budget->retained_bytes = strlen($attributes['identity_json']) + strlen($attributes['economic_json']) + strlen($auth->payloadBytes) + strlen($auth->signatureBytes);
        $budget->retained_observations = 1;
        $budget->save();
    }

    private function domainDenied(\Closure $write): void
    {
        $denied = false;
        try {
            $write();
        } catch (DomainException) {
            $denied = true;
        }
        self::assertTrue($denied, 'Expected model guard refusal before SQL.');
    }
}
