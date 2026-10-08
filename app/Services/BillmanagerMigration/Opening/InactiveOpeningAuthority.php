<?php

namespace App\Services\BillmanagerMigration\Opening;

use App\Models\AccountWallet;
use App\Models\User;
use App\Services\Accounts\NativeOpeningHistory;
use App\Services\Accounts\OpeningAuthority;
use App\Services\Accounts\OpeningEvidence;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InactiveOpeningAuthority implements OpeningAuthority
{
    private array $approvalFile;

    private ?string $token = null;

    private ?OpeningEvidence $current = null;

    private ?HeldOpeningPermit $permit = null;

    private ?Connection $connection = null;

    private ?\PDO $pdo = null;

    private int $level = 0;

    private bool $issuing = false;

    private ?array $lastFence = null;

    private ?array $lastFenceProof = null;

    public function __construct(private OpeningBundle $bundle, private string $approvalPath, private string $signaturePath, private OpeningProcessContext $process)
    {
        if (!$bundle->executable() || $bundle->target() !== $process->targetIdentity()) {
            throw new RuntimeException('An executable bundle for this operator target is required.');
        }
        $this->approvalFile = PrivateProofFile::read($approvalPath, 0);
        $weak = \WeakReference::create($this);
        $listener = static function ($event) use ($weak) {
            $scope = $weak->get();
            if ($scope && $scope->connection === $event->connection && $scope->level > 0 && $event->connection->transactionLevel() < $scope->level) {
                $scope->token = null;
            }
        };
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, $listener);
        DB::connection()->getEventDispatcher()->listen(TransactionRolledBack::class, $listener);
    }

    private function trust(): string
    {
        $path = config('account-opening.trust_path');
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('External opening trust is required.');
        }

        return $path;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function grantHash(): string
    {
        return $this->approvalFile['sha256'];
    }

    public function assertAcceptedRelease(): void
    {
        $this->process->assertCurrent();
        $this->bundle->assertCurrent();
        $release = SignedAttestation::verify($this->bundle->reference('release'), $this->bundle->reference('release_signature'), 'account-funding-release', $this->trust(), $this->now());
        if (hash_file('sha256', $this->bundle->reference('release')) !== $this->bundle->data()['release_sha256']) {
            throw new RuntimeException('Accepted opening release identity changed.');
        }
        ReleaseVerifier::assertCurrent($release);
    }

    public function assertFenceCurrent(): void
    {
        $this->process->assertCurrent();
        $data = $this->bundle->data();
        $initialBytes = PrivateProofFile::read($this->bundle->reference('freeze_receipt'), 0);
        $initial = StrictProofJson::decode($initialBytes['bytes']);
        $initial = SignedAttestation::verify($this->bundle->reference('freeze_receipt'), $this->bundle->reference('freeze_signature'), 'account-opening-fence', $this->trust(), ProofSchema::date($initial['issued_at']));
        $captured = new DateTimeImmutable($this->bundle->snapshot()->capturedAt());
        if ($captured < ProofSchema::date($initial['issued_at']) || $captured >= ProofSchema::date($initial['expires_at'])) {
            throw new RuntimeException('Snapshot capture is outside the original writer fence.');
        }
        $reference = OpeningFenceReference::current();
        $payload = PrivateProofFile::read($reference['payload'], 0);
        $signature = StrictProofJson::decode(PrivateProofFile::read($reference['signature'], 0)['bytes']);
        $trust = StrictProofJson::decode(PrivateProofFile::read($this->trust(), 0)['bytes']);
        $lease = SignedAttestation::verifyBytes($payload['bytes'], $signature, 'account-opening-fence', $trust, $this->now());
        foreach (['issuer', 'freeze_id', 'source_identity', 'source_population_fingerprint', 'target_identity', 'target_baseline_sql_sha256', 'controlled_writers_fingerprint', 'incoming_payment_boundary_fingerprint'] as $key) {
            if ($lease[$key] !== $initial[$key]) {
                throw new RuntimeException('Current writer fence lineage changed.');
            }
        }
        if ($initialBytes['sha256'] !== $data['freeze_receipt_sha256'] || $lease['freeze_id'] !== $data['freeze_id'] || $lease['source_identity'] !== $data['source_identity'] ||
            $lease['target_identity'] !== $this->process->targetIdentity() || $lease['target_baseline_sql_sha256'] !== $data['baseline_sql_sha256'] || $lease['sequence'] < $initial['sequence'] ||
            $lease['source_population_fingerprint'] !== hash_file('sha256', $this->bundle->reference('population_review'))) {
            throw new RuntimeException('The approved frozen population or target changed.');
        }
        if (ProofSchema::date($lease['issued_at']) < ProofSchema::date($initial['issued_at']) || ($this->lastFence !== null &&
            ($lease['sequence'] < $this->lastFence['sequence'] || ProofSchema::date($lease['issued_at']) < ProofSchema::date($this->lastFence['issued_at']) ||
                ($lease['sequence'] === $this->lastFence['sequence'] && $lease !== $this->lastFence)))) {
            throw new RuntimeException('Current writer fence regressed.');
        }
        $this->lastFence = $lease;
        $this->lastFenceProof = ['payload_b64' => base64_encode($payload['bytes']), 'signature' => $signature];
    }

    public function fenceFacts(): array
    {
        $this->assertFenceCurrent();

        return $this->lastFence;
    }

    public function fenceProof(): array
    {
        $this->assertFenceCurrent();

        return $this->lastFenceProof;
    }

    public function assertFenceNotBefore(array $prior): void
    {
        $current = $this->fenceFacts();
        if ($current['sequence'] < $prior['sequence'] || ProofSchema::date($current['issued_at']) < ProofSchema::date($prior['issued_at']) ||
            ($current['sequence'] === $prior['sequence'] && $current !== $prior)) {
            throw new RuntimeException('Current writer fence regressed.');
        }
    }

    public function assertApproved(OpeningEvidence $evidence): void
    {
        $this->assertAcceptedRelease();
        $this->assertFenceCurrent();
        $current = PrivateProofFile::read($this->approvalPath, 0);
        foreach (['sha256', 'device', 'inode'] as $key) {
            if ($current[$key] !== $this->approvalFile[$key]) {
                throw new RuntimeException('Original approval was replaced.');
            }
        }
        $approval = SignedAttestation::verify($this->approvalPath, $this->signaturePath, 'billmanager-inactive-opening-approval', $this->trust(), $this->now());
        $data = $this->bundle->data();
        if ($approval['bundle_sha256'] !== $this->bundle->digest() || $approval['capabilities'] !== ['inactive_opening'] || $approval['status'] !== 'approved' || $evidence->active ||
            $evidence->grantHash !== $current['sha256'] || $evidence->snapshotHash !== $this->bundle->snapshot()->checksum() || $evidence->policyHash !== $data['policy_sha256']) {
            throw new RuntimeException('An external exact inactive opening approval is required.');
        }
        foreach (['release_sha256', 'target_identity', 'baseline_sql_sha256', 'freeze_id', 'freeze_receipt_sha256', 'login_cutoff', 'activation_cutoff', 'policy_sha256', 'scope_fingerprint'] as $key) {
            if ($approval[$key] !== $data[$key]) {
                throw new RuntimeException('Opening approval scope or lineage changed.');
            }
        }
        if (CanonicalPolicy::bytes($approval['accounts']) !== CanonicalPolicy::bytes($this->bundle->accounts())) {
            throw new RuntimeException('Approval account scope changed.');
        }
        $report = (new OpeningPreparation)->sourceFacts($this->bundle);
        $matches = array_values(array_filter($report['accounts'], fn ($row) => $row['source_account'] === $evidence->sourceAccount && $row['currency'] === $evidence->currency));
        if (count($matches) !== 1 || $matches[0]['disposition'] !== 'candidate') {
            throw new RuntimeException('Opening account is not an approved candidate.');
        }
        $row = $matches[0];
        if ($row['source_system'] !== $evidence->sourceSystem || $row['owner_id'] !== $evidence->ownerId || $row['opening'] !== $evidence->opening || $row['limit_exact'] !== $evidence->limit ||
            $row['hold_fingerprint'] !== OpeningPreparation::holdFingerprint(DB::transactionLevel() > 0) || NativeOpeningHistory::forOpening($evidence->ownerId, $evidence->currency) !== $row['receipt_exclusions']) {
            throw new RuntimeException('Original owner, amount, holds or receipt exclusions changed.');
        }
    }

    public function duringAccount(OpeningEvidence $evidence, Closure $write): mixed
    {
        if ($this->current !== null) {
            throw new RuntimeException('Nested opening authority is denied.');
        }
        $this->assertApproved($evidence);
        $container = app();
        $bindings = $container->getBindings();
        $bound = $container->bound(OpeningAuthority::class);
        $resolved = $bound && $container->resolved(OpeningAuthority::class);
        $previous = $resolved ? $container->make(OpeningAuthority::class) : null;
        $enabled = config('account-funding.enabled');
        $container->instance(OpeningAuthority::class, $this);
        config(['account-funding.enabled' => true]);
        try {
            return DB::transaction(function () use ($evidence, $write) {
                $this->connection = DB::connection();
                $this->pdo = $this->connection->getPdo();
                $this->level = DB::transactionLevel();
                User::whereKey($evidence->ownerId)->lockForUpdate()->firstOrFail();
                if (DB::table('currencies')->where('code', $evidence->currency)->sharedLock()->first() === null) {
                    throw new RuntimeException('Opening currency identity is missing.');
                }
                NativeOpeningHistory::current($evidence->ownerId, $evidence->currency);
                $this->assertApproved($evidence);
                $this->current = $evidence;
                $this->token = bin2hex(random_bytes(32));
                $this->issuing = true;
                try {
                    $this->permit = HeldOpeningPermit::issue($this, $evidence, $this->token);
                } finally {
                    $this->issuing = false;
                }
                try {
                    $result = $write($this->permit);
                    $this->permit->assertFor($evidence);
                    if (AccountWallet::where('user_id', $evidence->ownerId)->where('currency_code', $evidence->currency)->exists()) {
                        OpeningBatchOperator::assertAccountCommitted($this, $evidence);
                    }
                    // All potentially slow source and durable-receipt reads
                    // precede the live lease check immediately before commit.
                    $this->assertFenceCurrent();

                    return $result;
                } finally {
                    $this->permit = null;
                    $this->current = null;
                    $this->token = null;
                    $this->level = 0;
                    $this->connection = null;
                    $this->pdo = null;
                }
            });
        } finally {
            config(['account-funding.enabled' => $enabled]);
            $container->offsetUnset(OpeningAuthority::class);
            if (isset($bindings[OpeningAuthority::class])) {
                $container->bind(OpeningAuthority::class, $bindings[OpeningAuthority::class]['concrete'], $bindings[OpeningAuthority::class]['shared']);
            }
            if ($resolved) {
                $container->instance(OpeningAuthority::class, $previous);
            }
        }
    }

    public function assertIssuing(OpeningEvidence $evidence, string $token): void
    {
        if (!$this->issuing || $this->permit !== null || $token !== $this->token) {
            throw new RuntimeException('Permit issuance is outside its private authority scope.');
        }
        $this->assertScope($evidence);
        $this->assertApproved($evidence);
    }

    public function assertPermit(HeldOpeningPermit $permit, OpeningEvidence $evidence, string $token): void
    {
        $this->assertPermitScope($permit, $evidence, $token);
        $this->assertApproved($evidence);
    }

    public function assertPermitScope(HeldOpeningPermit $permit, OpeningEvidence $evidence, string $token): void
    {
        if ($this->permit !== $permit || $token !== $this->token) {
            throw new RuntimeException('Held opening permit expired or belongs to another scope.');
        }
        $this->assertScope($evidence);
    }

    private function assertScope(OpeningEvidence $evidence): void
    {
        if ($this->current !== $evidence || $evidence->active || app(OpeningAuthority::class) !== $this || config('account-funding.enabled') !== true || $this->token === null ||
            $this->connection !== DB::connection() || $this->pdo !== DB::connection()->getPdo() || DB::transactionLevel() < $this->level || $this->level < 1 || !$this->pdo->inTransaction()) {
            throw new RuntimeException('Held opening authority requires its original live transaction.');
        }
    }
}
