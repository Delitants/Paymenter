<?php

namespace App\Services\BillmanagerMigration\Opening;

use RuntimeException;

/** A root-controlled pointer selects an already complete immutable signed lease. */
final class OpeningFenceReference
{
    public static function current(): array
    {
        $index = config('account-opening.fence_index_path');
        if ($index === null) {
            $payload = config('account-opening.fence_path');
            $signature = config('account-opening.fence_signature_path');
            if (!is_string($payload) || !is_string($signature) || $payload === '' || $signature === '') {
                throw new RuntimeException('A live external writer lease is required.');
            }

            return ['payload' => $payload, 'signature' => $signature];
        }
        if (!is_string($index) || $index === '') {
            throw new RuntimeException('An ordinary current lease reference is required.');
        }
        $record = StrictProofJson::decode(PrivateProofFile::read($index, 0)['bytes']);
        ProofSchema::keys($record, ['schema_version', 'purpose', 'payload', 'signature']);
        if ($record['schema_version'] !== 1 || $record['purpose'] !== 'account-opening-fence-reference') {
            throw new RuntimeException('Unknown current lease reference.');
        }
        $paths = [];
        foreach (['payload', 'signature'] as $name) {
            if (!is_array($record[$name])) {
                throw new RuntimeException('Incomplete current lease generation.');
            }
            ProofSchema::keys($record[$name], ['path', 'sha256']);
            $path = $record[$name]['path'];
            if (!is_string($path) || dirname($path) !== dirname($index) || $path === $index) {
                throw new RuntimeException('Lease generation must be an ordinary sibling proof.');
            }
            $file = PrivateProofFile::read($path, 0);
            if ($file['sha256'] !== ProofSchema::hash($record[$name]['sha256'])) {
                throw new RuntimeException('Current signed lease generation changed.');
            }
            $paths[$name] = $path;
        }

        return $paths;
    }
}
