<?php

namespace Paymenter\Extensions\Others\Bind;

use App\Models\Extension;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\Bind\Models\DnsOperation;
use Paymenter\Extensions\Others\Bind\Models\ZoneBinding;

final class ZoneManager
{
    public function __construct(private readonly ?SshTransport $primary = null, private readonly ?SshTransport $secondary = null, private readonly ?\Closure $beforeDispatch = null) {}

    private function transport(string $role): SshTransport
    {
        if ($role === 'primary' && $this->primary) {
            return $this->primary;
        }if ($role === 'secondary' && $this->secondary) {
            return $this->secondary;
        }
        $e = Extension::where('type', 'other')->where('extension', 'Bind')->where('enabled', true)->firstOrFail();
        $c = $e->settings->pluck('value', 'key')->all();

        return new SshTransport(['host' => $c[$role . '_host'] ?? null, 'port' => filter_var($c[$role . '_port'] ?? null, FILTER_VALIDATE_INT), 'username' => $c[$role . '_username'] ?? null, 'private_key' => $c[$role . '_private_key'] ?? null, 'host_key' => $c[$role . '_host_key'] ?? null]);
    }

    private function dispatch(User $u, ZoneBinding $b, string $operation, array $payload, string $id, string $role = 'primary'): array
    {
        ($this->beforeDispatch)?->__invoke();
        BindAccess::assertAllowed($u, $b, $operation);

        return $this->transport($role)->request($b->id, $operation, $payload, $id);
    }

    private function validMetadata(array $result, bool $records = false): array
    {
        $data = $result['data'] ?? [];
        Protocol::need(is_int($data['serial'] ?? null) && $data['serial'] >= 0 && $data['serial'] <= 4294967295 && is_string($data['revision'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $data['revision']) === 1);
        if ($records) {
            Protocol::need(($result['state'] ?? null) === 'observed' && is_array($data['records'] ?? null) && array_is_list($data['records']) && count($data['records']) <= 500);
            $total = 0;
            foreach ($data['records'] as $r) {
                Protocol::need(is_array($r) && is_string($r['owner'] ?? null) && is_int($r['ttl'] ?? null) && $r['ttl'] > 0 && in_array($r['type'] ?? null, ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'SRV'], true) && is_array($r['records'] ?? null) && array_is_list($r['records']));
                foreach ($r['records'] as $value) {
                    Protocol::need(is_string($value));
                }$total += count($r['records']);
            }Protocol::need($total <= 500);
        }

        return $data;
    }

    public function inspect(User $u, ZoneBinding $b, array $input = []): array
    {
        $result = $this->dispatch($u, $b, 'inspect', [], (string) Str::uuid());
        $this->validMetadata($result, true);

        return $result;
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }if (!array_is_list($value)) {
            ksort($value);
        }foreach ($value as $key => $child) {
            $value[$key] = $this->canonical($child);
        }

        return $value;
    }

    private function claim(User $u, ZoneBinding $b, string $kind, string $id, array $payload): array
    {
        Protocol::need(Protocol::uuid($id));
        $hash = hash('sha256', json_encode($this->canonical([$kind, $payload]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($u, $b, $kind, $id, $hash) {
            ZoneBinding::whereKey($b->id)->lockForUpdate()->firstOrFail();
            $old = DnsOperation::whereKey($id)->lockForUpdate()->first();
            if ($old) {
                Protocol::need($old->binding_id === $b->id && (int) $old->actor_user_id === (int) $u->id && $old->kind === $kind && hash_equals($old->request_hash, $hash));

                return [$old, false];
            }if (DnsOperation::where('binding_id', $b->id)->whereIn('state', ['prepared', 'uncertain', 'pending_replication'])->exists()) {
                throw new \RuntimeException('Reconcile the earlier DNS operation before making another change');
            }

            return [DnsOperation::forceCreate(['id' => $id, 'binding_id' => $b->id, 'actor_user_id' => $u->id, 'kind' => $kind, 'request_hash' => $hash, 'state' => 'prepared']), true];
        });
    }

    private function store(DnsOperation $op, array $result): array
    {
        $data = [];
        foreach (['serial', 'revision'] as $key) {
            if (isset($result['data'][$key])) {
                $data[$key] = $result['data'][$key];
            }
        }
        $safe = ['protocol' => Protocol::VERSION, 'request_id' => $op->id, 'binding_id' => $op->binding_id, 'state' => $result['state'], 'code' => $result['code'], 'data' => $data];
        $op->forceFill(['state' => $safe['state'], 'code' => $safe['code'], 'serial' => $data['serial'] ?? null, 'revision' => $data['revision'] ?? null, 'response' => $safe])->save();

        return $safe;
    }

    private function uncertain(DnsOperation $op): array
    {
        return $this->store($op, ['state' => 'uncertain', 'code' => 'OUTCOME_UNCONFIRMED', 'data' => []]);
    }

    private function replicated(User $u, ZoneBinding $b, array $primary): array
    {
        $data = $this->validMetadata($primary);
        $state = 'pending_replication';
        try {
            $secondary = $this->dispatch($u, $b, 'inspect', [], (string) Str::uuid(), 'secondary');
            $remote = $this->validMetadata($secondary, true);
            if ($data['serial'] === $remote['serial'] && $data['revision'] === $remote['revision']) {
                $state = 'committed';
            }
        } catch (\Throwable) {
        }

        return ['state' => $state, 'code' => $state === 'committed' ? 'SAVED' : 'REPLICATION_PENDING', 'data' => $data];
    }

    public function replace(User $u, ZoneBinding $b, array $input): array
    {
        BindAccess::assertAllowed($u, $b, 'replace_records');
        $id = $input['request_id'] ?? '';
        $payload = $input;
        unset($payload['request_id']);
        Protocol::encode($id, $b->id, 'replace_records', $payload);
        [$op,$fresh] = $this->claim($u, $b, 'replace_records', $id, $payload);
        if (!$fresh) {
            return $op->response ?? ['state' => 'uncertain', 'code' => 'OUTCOME_UNCONFIRMED', 'data' => []];
        }
        try {
            $result = $this->dispatch($u, $b, 'replace_records', $payload, $id);
            if ($result['state'] === 'committed') {
                $data = $this->validMetadata($result);
                Protocol::need($data['serial'] === (($payload['expected_serial'] + 1) & 0xFFFFFFFF));
                $result = $this->replicated($u, $b, $result);
            } elseif (!in_array($result['state'], ['rejected', 'uncertain'], true)) {
                return $this->uncertain($op);
            }

            return $this->store($op, $result);
        } catch (AuthorizationException $e) {
            $this->store($op, ['state' => 'rejected', 'code' => 'AUTHORIZATION_REVOKED', 'data' => []]);
            throw $e;
        } catch (\Throwable) {
            return $this->uncertain($op);
        }
    }

    public function reconcile(User $u, ZoneBinding $b, array $input): array
    {
        BindAccess::assertAllowed($u, $b, 'inspect_request');
        $id = $input['request_id'] ?? '';
        Protocol::need(Protocol::uuid($id));
        $op = DnsOperation::whereKey($id)->where('binding_id', $b->id)->firstOrFail();
        try {
            $result = $this->dispatch($u, $b, 'inspect_request', ['request_id' => $id], (string) Str::uuid());
            if ($result['code'] === 'REQUEST_NOT_FOUND') {
                return $this->uncertain($op);
            }if (($op->kind === 'prepare' && in_array($result['state'], ['committed', 'pending_replication'], true)) || $result['state'] === 'committed') {
                $expected = $this->validMetadata($result);
                $current = $this->dispatch($u, $b, 'inspect', [], (string) Str::uuid());
                $observed = $this->validMetadata($current, true);
                Protocol::need($observed['serial'] === $expected['serial'] && $observed['revision'] === $expected['revision']);
                $result = $this->replicated($u, $b, $current);
            }if (!in_array($result['state'], ['committed', 'pending_replication', 'rejected', 'uncertain'], true)) {
                return $this->uncertain($op);
            }

            return $this->store($op, $result);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable) {
            return $this->uncertain($op);
        }
    }

    public function prepare(User $u, ZoneBinding $b): array
    {
        BindAccess::assertAllowed($u, $b, 'prepare_zone');
        $id = (string) Str::uuid();
        [$op] = $this->claim($u, $b, 'prepare', $id, []);
        try {
            $secondary = $this->dispatch($u, $b, 'prepare_secondary', [], $id, 'secondary');
            if (!in_array($secondary['state'], ['committed', 'pending_replication'], true)) {
                return $this->store($op, $secondary);
            }$primary = $this->dispatch($u, $b, 'prepare_zone', [], $id);
            if (!in_array($primary['state'], ['committed', 'pending_replication'], true)) {
                return $this->store($op, ['state' => 'uncertain', 'code' => 'PARTIAL_PREPARE', 'data' => []]);
            }$snapshot = $this->dispatch($u, $b, 'inspect', [], (string) Str::uuid());

            return $this->store($op, $this->replicated($u, $b, $snapshot));
        } catch (\Throwable) {
            return $this->uncertain($op);
        }
    }
}
