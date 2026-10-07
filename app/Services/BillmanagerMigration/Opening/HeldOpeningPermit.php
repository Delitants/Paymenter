<?php

namespace App\Services\BillmanagerMigration\Opening;

use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\User;
use App\Services\Accounts\OpeningEvidence;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final readonly class HeldOpeningPermit
{
    private function __construct(private InactiveOpeningAuthority $authority, private OpeningEvidence $evidence, private string $token) {}

    /** The unpredictable issuance token exists only inside the live authority scope. */
    public static function issue(InactiveOpeningAuthority $authority, OpeningEvidence $evidence, string $token): self
    {
        $authority->assertIssuing($evidence, $token);

        return new self($authority, $evidence, $token);
    }

    public function assertFor(OpeningEvidence $evidence): void
    {
        if ($evidence !== $this->evidence || $evidence->active) {
            throw new RuntimeException('Held opening permit requires its exact inactive evidence.');
        }
        $this->authority->assertPermit($this, $evidence, $this->token);
    }

    public function assertIdentityFor(OpeningEvidence $evidence): void
    {
        if ($evidence !== $this->evidence || $evidence->active) {
            throw new RuntimeException('Held opening permit requires its exact inactive evidence.');
        }
        $this->authority->assertPermitScope($this, $evidence, $this->token);
    }

    public function assertHold(Model $model, string $operation): void
    {
        $this->assertFor($this->evidence);
        $allowed = match (true) {
            $model instanceof User => $model->getKey() === $this->evidence->ownerId && in_array($operation, ['initialize wallet', 'write account records'], true),
            $model instanceof AccountWallet => $model->user_id === $this->evidence->ownerId && $model->currency_code === $this->evidence->currency &&
                in_array($operation, ['create account opening', 'replay opening', 'write cash projection'], true) &&
                (!$model->exists || $model->opening_identity === hash('sha256', json_encode([$this->evidence->sourceSystem, $this->evidence->sourceAccount, $this->evidence->currency], JSON_THROW_ON_ERROR))),
            $model instanceof Credit => $model->user_id === $this->evidence->ownerId && $model->currency_code === $this->evidence->currency && in_array($operation, ['write cash', 'write original cash'], true),
            default => false,
        };
        if (!$allowed) {
            throw new RuntimeException('Held permit cannot authorize this model, identity or operation.');
        }
        OpeningBatchOperator::assertAccountScope($this->authority, $this->evidence);
    }

    private function __clone() {}

    public function __serialize(): array
    {
        throw new RuntimeException('Held opening permits cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new RuntimeException('Held opening permits cannot be rehydrated.');
    }
}
