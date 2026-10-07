<?php

namespace App\Services\BillmanagerMigration\Opening;

use App\Services\BillmanagerMigration\ImportContext;
use App\Services\BillmanagerMigration\Snapshot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class OpeningBundle
{
    private function __construct(private array $data, private string $hash, private string $path, private int $device, private int $inode) {}

    public static function load(string $bundlePath): self
    {
        $file = PrivateProofFile::read($bundlePath, 0);
        $data = StrictProofJson::decode($file['bytes']);
        $full = ($data['purpose'] ?? '') === 'billmanager-inactive-opening-bundle';
        if (!$full && ($data['purpose'] ?? '') !== 'billmanager-inactive-opening-input') {
            throw new RuntimeException('Unknown opening input purpose.');
        }
        ProofSchema::keys($data, ['schema_version', 'purpose', 'source_identity', 'login_cutoff', 'activation_cutoff', 'source_timezone', 'import_id', 'scope', 'references',
            ...($full ? ['target_identity', 'baseline_sql_sha256', 'release_sha256', 'freeze_id', 'freeze_receipt_sha256', 'policy_sha256', 'scope_fingerprint'] : [])]);
        if ($data['schema_version'] !== 1 || !is_string($data['source_identity']) || $data['source_identity'] === '' || strlen($data['source_identity']) > 45 ||
            !is_int($data['import_id']) || $data['import_id'] < 1 || !is_array($data['scope']) || !array_is_list($data['scope']) || !is_array($data['references'])) {
            throw new RuntimeException('Invalid opening input identity.');
        }
        foreach (['login_cutoff', 'activation_cutoff'] as $key) {
            $date = is_string($data[$key]) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $data[$key]) : false;
            if (!$date || $date->format('Y-m-d H:i:s') !== $data[$key]) {
                throw new RuntimeException('Invalid source-local opening cutoff.');
            }
        }
        if ($data['activation_cutoff'] < $data['login_cutoff']) {
            throw new RuntimeException('Activation cannot expand archive retention.');
        }
        $required = ['snapshot', 'snapshot_checksum', 'policy', 'population_review'];
        if ($full) {
            array_push($required, 'preparation_input', 'preparation', 'target_inventory', 'target_sql', 'backup_acceptance', 'backup_signature', 'release', 'release_signature', 'freeze_receipt', 'freeze_signature');
            foreach (['baseline_sql_sha256', 'release_sha256', 'freeze_receipt_sha256', 'policy_sha256', 'scope_fingerprint'] as $key) {
                ProofSchema::hash($data[$key]);
            }
            if (!is_array($data['target_identity']) || !$data['target_identity'] || !is_string($data['freeze_id']) || $data['freeze_id'] === '') {
                throw new RuntimeException('Executable opening target and freeze identity are missing.');
            }
        }
        ProofSchema::keys($data['references'], $required);
        $bundle = new self($data, $file['sha256'], $bundlePath, $file['device'], $file['inode']);
        foreach ($required as $name) {
            $bundle->reference($name);
        }
        $snapshot = $bundle->snapshot();
        if ($snapshot->sourceTimezone() !== $data['source_timezone'] || !is_string($data['source_timezone'])) {
            throw new RuntimeException('Source timezone is not bound.');
        }
        try {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/D', $snapshot->capturedAt())) {
                throw new RuntimeException('Invalid source capture UTC.');
            }
            $captured = new \DateTimeImmutable($snapshot->capturedAt());
            $cutoff = $captured->setTimezone(new \DateTimeZone($data['source_timezone']))->modify('-2 years')->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid source capture clock.', previous: $e);
        }
        if ($cutoff !== $data['activation_cutoff']) {
            throw new RuntimeException('Activation must use the frozen rolling two-year cutoff.');
        }
        $bundle->context();
        $bundle->policy();

        return $bundle;
    }

    public function digest(): string
    {
        $this->assertCurrent();

        return $this->hash;
    }

    public function data(): array
    {
        $this->assertCurrent();

        return $this->data;
    }

    public function executable(): bool
    {
        return $this->data['purpose'] === 'billmanager-inactive-opening-bundle';
    }

    public function accounts(): array
    {
        $this->assertCurrent();

        return $this->data['scope'];
    }

    public function target(): array
    {
        $this->assertCurrent();

        return $this->data['target_identity'] ?? [];
    }

    public function assertCurrent(): void
    {
        $current = PrivateProofFile::read($this->path, 0);
        if ($current['sha256'] !== $this->hash || $current['device'] !== $this->device || $current['inode'] !== $this->inode) {
            throw new RuntimeException('Loaded opening bundle bytes or identity changed.');
        }
    }

    public function reference(string $name): string
    {
        $this->assertCurrent();
        $ref = $this->data['references'][$name] ?? throw new RuntimeException('Missing opening evidence reference.');
        if (!is_array($ref)) {
            throw new RuntimeException('Invalid opening reference.');
        }
        ProofSchema::keys($ref, ['path', 'sha256']);
        if (!is_string($ref['path'])) {
            throw new RuntimeException('Invalid opening reference path.');
        }
        $file = PrivateProofFile::read($ref['path'], 0);
        if (!hash_equals(ProofSchema::hash($ref['sha256']), $file['sha256'])) {
            throw new RuntimeException('Opening evidence bytes changed.');
        }

        return $ref['path'];
    }

    public function snapshot(): Snapshot
    {
        $path = $this->reference('snapshot');
        if ($this->reference('snapshot_checksum') !== $path . '.sha256') {
            throw new RuntimeException('Snapshot checksum path conflicts.');
        }
        StrictProofJson::decode(PrivateProofFile::read($path, 0)['bytes']);

        return Snapshot::load($path, $this->data['source_identity'], $this->data['login_cutoff']);
    }

    public function context(): ImportContext
    {
        $row = DB::table('billmanager_imports')->where(['source_host' => $this->data['source_identity'], 'snapshot_sha256' => $this->snapshot()->checksum()])->sole();
        if ($row->id !== $this->data['import_id']) {
            throw new RuntimeException('The exact selected financial import changed.');
        }

        return new ImportContext($row->id, $row->source_host);
    }

    public function policy(): array
    {
        $policy = StrictProofJson::decode(PrivateProofFile::read($this->reference('policy'), 0)['bytes']);
        ProofSchema::keys($policy, ['schema_version', 'purpose', 'inactive_only', 'credit_limit_policy', 'rounding', 'shared_access', 'currencies', 'reviewed_reasons']);
        if ($policy['schema_version'] !== 1 || $policy['purpose'] !== 'inactive-opening-policy' || $policy['inactive_only'] !== true || $policy['credit_limit_policy'] !== 'preserve' ||
            $policy['rounding'] !== 'positive_half_up_cents_debt_exact_four' || $policy['shared_access'] !== 'owner_spend_members_read_only' ||
            !is_array($policy['currencies']) || !array_is_list($policy['currencies']) || !$policy['currencies'] || !is_array($policy['reviewed_reasons']) || !array_is_list($policy['reviewed_reasons']) ||
            array_diff($policy['reviewed_reasons'], ['debt', 'credit_limit_activation', 'shared_account', 'below_native_precision'])) {
            throw new RuntimeException('Unsupported inactive opening policy.');
        }
        foreach ($policy['currencies'] as $code) {
            if (!is_string($code) || !preg_match('/^[A-Z]{3}$/D', $code)) {
                throw new RuntimeException('Invalid policy currency.');
            }
        }
        if ($this->executable() && $this->data['policy_sha256'] !== hash('sha256', CanonicalPolicy::bytes($policy))) {
            throw new RuntimeException('Canonical policy changed.');
        }

        return $policy;
    }
}
