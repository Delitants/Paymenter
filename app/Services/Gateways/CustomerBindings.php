<?php

namespace App\Services\Gateways;

use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHold;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CustomerBindings
{
    public function resolve(string $provider, string $merchantFingerprint, User $user, Closure $lookupOrCreate): string
    {
        // The claim must survive an uncertain external response or caller failure.
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider customer creation requires an autocommit connection');
        }
        MigrationHold::assertAllowed($user, 'gateway customer binding');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]{0,31}$/D', $provider) || !preg_match('/^[a-f0-9]{64}$/D', $merchantFingerprint)) {
            throw new RuntimeException('Invalid provider customer scope');
        }
        $identity = ['provider' => $provider, 'merchant_fingerprint' => $merchantFingerprint, 'user_id' => $user->id];
        $contact = hash('sha256', $user->email);
        $claimed = DB::table('gateway_customer_bindings')->insertOrIgnore($identity + [
            'contact_fingerprint' => $contact, 'state' => 'initializing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('gateway_customer_bindings')->where($identity)->first();
        if (!$row || !hash_equals($row->contact_fingerprint, $contact)) {
            throw new RuntimeException('Provider customer contact requires reconciliation');
        }
        if (!$claimed) {
            if ($row->state !== 'ready' || !$row->provider_reference) {
                throw new RuntimeException('Provider customer initialization requires reconciliation');
            }

            return Crypt::decryptString($row->provider_reference);
        }
        // No transaction or expiring lease surrounds the provider call. A competing
        // checkout sees the committed claim, and an uncertain result is never retried.
        $reference = $lookupOrCreate();
        if (!is_string($reference) || $reference === '' || strlen($reference) > 255) {
            throw new RuntimeException('Provider customer identity requires reconciliation');
        }
        try {
            $updated = DB::table('gateway_customer_bindings')->where('id', $row->id)->where('state', 'initializing')->whereNull('provider_reference')
                ->update(['state' => 'ready', 'provider_reference' => Crypt::encryptString($reference),
                    'provider_reference_fingerprint' => hash('sha256', $reference), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('Provider customer is already bound to another user; reconciliation is required');
        }
        if ($updated !== 1) {
            throw new RuntimeException('Provider customer binding changed; reconciliation is required');
        }

        return $reference;
    }
}
