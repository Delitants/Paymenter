<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Services\Accounts\OpeningEvidence;
use App\Services\BillmanagerMigration\Opening\ControlProofGraph;
use App\Services\BillmanagerMigration\Opening\InactiveOpeningAuthority;
use App\Services\BillmanagerMigration\Opening\OpeningBundle;
use App\Services\BillmanagerMigration\Opening\OpeningFenceReference;
use App\Services\BillmanagerMigration\Opening\OpeningTargetState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\Fixtures\Opening\CaptureProofFixture;
use Tests\Fixtures\Opening\ProofFactory;
use Tests\TestCase;

#[Group('opening-native')]
class OpeningReadinessTest extends TestCase
{
    use UsesCommittedDatabase { tearDown as private committedTearDown; }

    private array $argv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->argv = $_SERVER['argv'];
        $_SERVER['argv'] = [base_path('artisan'), 'billmanager:openings:apply'];
        self::assertTrue(method_exists(OpeningFenceReference::class, 'currentV2'), 'Live v2 reference selection is missing.');
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;
        ProofFactory::cleanup();
        $this->committedTearDown();
    }

    private function fixture(): array
    {
        ProofFactory::cleanup();
        $this->truncateDatabaseTables();

        return CaptureProofFixture::ready();
    }

    private function authority(array $p): InactiveOpeningAuthority
    {
        return new InactiveOpeningAuthority($p['bundle'], $p['approval_path'], $p['signature_path'], $p['process']);
    }

    private function denied(callable $call): void
    {
        $before = (new OpeningTargetState)->capture();
        try {
            $call();
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
            self::assertSame($before, (new OpeningTargetState)->capture());

            return;
        }
        self::fail('Invalid v2 live authority was accepted.');
    }

    public function test_v2_accepts_later_capture_and_ready_without_the_v1_capture_window_rule(): void
    {
        $p = $this->fixture();
        $authority = $this->authority($p);
        $authority->assertApproved($p['evidence']);
        $fence = $authority->fenceFacts();
        self::assertLessThan(strtotime($fence['issued_at']), strtotime($p['bundle']->snapshot()->capturedAt()));
        self::assertSame($p['bundle']->target(), $fence['target_identity']);
        $proof = $authority->fenceProof();
        self::assertSame(['schema_version', 'payload_b64', 'signature', 'history'], array_keys($proof));
        self::assertSame(2, $proof['schema_version']);
        self::assertSame($p['history']['history_ref'], $proof['history']);
        self::assertSame(0, DB::table('account_wallets')->count());
    }

    public function test_v2_requires_all_eight_references_and_five_lineage_fields(): void
    {
        $p = $this->fixture();
        foreach (['control_epoch', 'capture_fence', 'capture_fence_signature', 'collector_receipt', 'capture_history', 'capture_acceptance', 'capture_acceptance_signature', 'preparation_history'] as $field) {
            $data = $p['data'];
            unset($data['references'][$field]);
            $path = $p['dir'] . '/missing-' . $field . '.json';
            ProofFactory::write($path, json_encode($data));
            $this->denied(fn () => OpeningBundle::load($path));
        }
        foreach (['source_capture_id', 'control_epoch_sha256', 'capture_fence_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256'] as $field) {
            $data = $p['data'];
            unset($data[$field]);
            $path = $p['dir'] . '/missing-' . $field . '.json';
            ProofFactory::write($path, json_encode($data));
            $this->denied(fn () => OpeningBundle::load($path));
            $data = $p['data'];
            $data[$field] = $field === 'source_capture_id' ? 'changed-capture' : str_repeat('f', 64);
            $path = $p['dir'] . '/changed-' . $field . '.json';
            ProofFactory::write($path, json_encode($data));
            $q = $p;
            $q['bundle'] = OpeningBundle::load($path);
            $this->denied(fn () => $this->authority($q)->assertFenceCurrent());
        }
    }

    public function test_v2_refuses_legacy_pointer_approval_or_fixed_fallback(): void
    {
        $p = $this->fixture();
        $pointer = $p['pointer_record'];
        config(['account-opening.fence_index_path' => null]);
        $this->denied(fn () => $this->authority($p)->assertFenceCurrent());
        config(['account-opening.fence_index_path' => $p['pointer_path']]);
        $legacy = $pointer;
        unset($legacy['history']);
        $legacy['schema_version'] = 1;
        ProofFactory::write($p['pointer_path'], json_encode($legacy));
        $this->denied(fn () => $this->authority($p)->assertFenceCurrent());
        ProofFactory::write($p['pointer_path'], json_encode($pointer));
        $approval = $p['approval'];
        $approval['schema_version'] = 1;
        foreach (['source_capture_id', 'control_epoch_sha256', 'capture_fence_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256'] as $field) {
            unset($approval[$field]);
        }
        $pair = ($p['sign'])('legacy-approval-for-v2', $approval);
        $q = $p;
        $q['approval_path'] = $pair['payload']['path'];
        $q['signature_path'] = $pair['signature']['path'];
        $e = $p['evidence'];
        $q['evidence'] = new OpeningEvidence($e->sourceSystem, $e->sourceAccount, $e->ownerId, $e->currency, $e->opening, $e->limit, $e->snapshotHash, $e->policyHash, $pair['payload']['sha256'], false);
        $this->denied(fn () => $this->authority($q)->assertApproved($q['evidence']));
        $q = $p;
        $q['approval_path'] = $p['history']['records']['capture_acceptance_ref']['path'];
        $q['signature_path'] = $p['history']['records']['capture_acceptance_signature_ref']['path'];
        $this->denied(fn () => $this->authority($q)->assertApproved($p['evidence']));
    }

    public function test_current_pointer_is_a_complete_sibling_generation(): void
    {
        $p = $this->fixture();
        $pointer = $p['pointer_record'];
        foreach (['payload', 'signature', 'history'] as $field) {
            $bad = $pointer;
            $bad[$field]['path'] = $p['history']['records']['directory'] . '/missing-file';
            ProofFactory::write($p['pointer_path'], json_encode($bad));
            $this->denied(fn () => OpeningFenceReference::currentV2(new ControlProofGraph));
        }
        $bad = $pointer;
        $bad['payload'] = $p['history']['records']['leases'][1]['payload'];
        ProofFactory::write($p['pointer_path'], json_encode($bad));
        $this->denied(fn () => OpeningFenceReference::currentV2(new ControlProofGraph));
        $copy = $p['dir'] . '/different-parent-payload.json';
        copy($pointer['payload']['path'], $copy);
        chmod($copy, 0600);
        $bad = $pointer;
        $bad['payload']['path'] = $copy;
        ProofFactory::write($p['pointer_path'], json_encode($bad));
        $this->denied(fn () => OpeningFenceReference::currentV2(new ControlProofGraph));
        ProofFactory::write($p['pointer_path'], json_encode($pointer));
        self::assertSame($pointer['history'], OpeningFenceReference::currentV2(new ControlProofGraph)['history']);
    }

    public function test_live_authority_rechecks_expiry_revocation_identity_and_pointer_before_commit(): void
    {
        foreach (['revocation', 'pointer', 'approval', 'hold'] as $change) {
            $p = $this->fixture();
            $authority = $this->authority($p);
            $authority->assertApproved($p['evidence']);
            $checkedAfterSentinel = false;
            $this->denied(function () use ($p, $authority, $change, &$checkedAfterSentinel) {
                $authority->duringAccount($p['evidence'], function () use ($p, $authority, $change, &$checkedAfterSentinel) {
                    DB::table('billmanager_imports')->where('id', $p['data']['import_id'])->update(['status' => 'synthetic-rollback']);
                    $authority->assertApproved($p['evidence']);
                    $checkedAfterSentinel = true;
                    if ($change === 'revocation') {
                        $trust = json_decode(file_get_contents($p['trust_path']), true, flags: JSON_THROW_ON_ERROR);
                        $trust['revoked_keys'] = ['fixture'];
                        ProofFactory::write($p['trust_path'], json_encode($trust));
                    } elseif ($change === 'pointer') {
                        ProofFactory::write($p['pointer_path'], '{}');
                    } elseif ($change === 'approval') {
                        ProofFactory::write($p['approval_path'], file_get_contents($p['approval_path']) . ' ');
                    } else {
                        DB::table('billmanager_holds')->update(['reason' => 'changed-after-authority']);
                    }
                });
            });
            self::assertTrue($checkedAfterSentinel, 'The unrelated rollback sentinel must not cause the authority denial.');
            self::assertSame(0, DB::table('account_wallets')->count());
        }
    }

    public function test_live_expiry_cannot_be_verified_at_caller_supplied_historical_time(): void
    {
        $p = $this->fixture();
        $authority = $this->authority($p);
        $authority->assertApproved($p['evidence']);
        $expiry = strtotime($authority->fenceFacts()['expires_at']);
        $checkedAfterSentinel = false;
        $this->denied(function () use ($p, $authority, $expiry, &$checkedAfterSentinel) {
            $authority->duringAccount($p['evidence'], function () use ($p, $authority, $expiry, &$checkedAfterSentinel) {
                DB::table('billmanager_imports')->where('id', $p['data']['import_id'])->update(['status' => 'synthetic-expiry']);
                $authority->assertApproved($p['evidence']);
                $checkedAfterSentinel = true;
                while (time() <= $expiry) {
                    usleep(100000);
                }
            });
        });
        self::assertTrue($checkedAfterSentinel, 'Expiry must occur after a valid in-transaction authority check.');
    }

    public function test_pointer_regression_to_still_live_older_head_is_denied(): void
    {
        $p = $this->fixture();
        $authority = $this->authority($p);
        $authority->assertFenceCurrent();
        $old = $p['pointer_record'];
        $p = CaptureProofFixture::publish($p);
        self::assertSame(4, $authority->fenceFacts()['sequence']);
        ProofFactory::write($p['pointer_path'], json_encode($old));
        $this->denied(fn () => $authority->assertFenceCurrent());
    }

    public function test_same_sequence_substituted_pair_is_not_a_reread(): void
    {
        $p = $this->fixture();
        $authority = $this->authority($p);
        $authority->assertFenceCurrent();
        $p = CaptureProofFixture::publish($p);
        self::assertSame(4, $authority->fenceFacts()['sequence']);
        $pairs = $p['history']['records']['leases'];
        $head = $pairs[3];
        $value = json_decode(file_get_contents($head['payload']['path']), true, flags: JSON_THROW_ON_ERROR);
        $value['nonce'] = 'substituted-same-sequence';
        $pairs[3] = ($p['sign'])('substituted-four', $value);
        $history = ($p['history']['records']['write'])('substituted-index', ['schema_version' => 1, 'purpose' => 'account-opening-lease-history', 'leases' => $pairs]);
        $pointer = ['schema_version' => 2, 'purpose' => 'account-opening-fence-reference', ...$pairs[3], 'history' => $history];
        ProofFactory::write($p['pointer_path'], json_encode($pointer));
        $this->denied(fn () => $authority->assertFenceCurrent());
    }

    public function test_human_approval_lineage_and_account_scope_are_exact(): void
    {
        $p = $this->fixture();
        $e = $p['evidence'];
        foreach (['source_capture_id', 'control_epoch_sha256', 'capture_fence_sha256', 'capture_acceptance_sha256', 'preparation_history_sha256', 'accounts'] as $field) {
            $approval = $p['approval'];
            if ($field === 'accounts') {
                $approval['accounts'][0]['owner_id']++;
            } else {
                $approval[$field] = $field === 'source_capture_id' ? 'changed-capture' : str_repeat('f', 64);
            }
            $pair = ($p['sign'])('human-changed-' . $field, $approval);
            $q = $p;
            $q['approval_path'] = $pair['payload']['path'];
            $q['signature_path'] = $pair['signature']['path'];
            $evidence = new OpeningEvidence($e->sourceSystem, $e->sourceAccount, $e->ownerId, $e->currency, $e->opening, $e->limit, $e->snapshotHash, $e->policyHash, $pair['payload']['sha256'], false);
            $this->denied(fn () => $this->authority($q)->assertApproved($evidence));
        }
    }

    public function test_validly_signed_renewal_cannot_change_runtime_target_or_frozen_facts(): void
    {
        foreach (['machine_id_sha256', 'runtime_realpath', 'db_database', 'db_socket_realpath', 'db_server_uuid_or_fingerprint', 'network_namespace_inode', 'operator_uid'] as $field) {
            $p = $this->fixture();
            $authority = $this->authority($p);
            $authority->assertFenceCurrent();
            $target = $p['data']['target_identity'];
            $target[$field] = str_ends_with($field, 'sha256') || $field === 'db_server_uuid_or_fingerprint' ? str_repeat('f', 64) : match ($field) {
                'operator_uid' => 1,'network_namespace_inode' => 'net:[999]', 'db_database' => 'changed_database',default => '/fixture/changed'
            };
            CaptureProofFixture::publish($p, ['target_identity' => $target]);
            $this->denied(fn () => $authority->assertFenceCurrent());
        }
        foreach (['target_baseline_sql_sha256', 'source_population_fingerprint', 'capture_acceptance_sha256', 'preparation_history_sha256'] as $field) {
            $p = $this->fixture();
            $authority = $this->authority($p);
            $authority->assertFenceCurrent();
            CaptureProofFixture::publish($p, [$field => str_repeat('f', 64)]);
            $this->denied(fn () => $authority->assertFenceCurrent());
        }
    }
}
