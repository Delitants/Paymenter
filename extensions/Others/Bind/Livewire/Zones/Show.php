<?php

namespace Paymenter\Extensions\Others\Bind\Livewire\Zones;

use App\Livewire\Component;
use App\Models\Extension;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Others\Bind\BindAccess;
use Paymenter\Extensions\Others\Bind\Models\DnsOperation;
use Paymenter\Extensions\Others\Bind\Models\ZoneBinding;
use Paymenter\Extensions\Others\Bind\ZoneManager;

class Show extends Component
{
    #[Locked]
    public string $bindingId;

    #[Locked]
    public array $snapshot = [];

    #[Locked]
    public array $outcome = [];

    #[Locked]
    public string $requestId = '';

    #[Locked]
    public string $lastRequestId = '';

    #[Locked]
    public bool $recordsUnavailable = false;

    public string $owner = '';

    public string $type = 'A';

    public int $ttl = 300;

    public string $recordsText = '';

    private function binding(): ZoneBinding
    {
        return ZoneBinding::findOrFail($this->bindingId);
    }

    public function mount(ZoneBinding $binding): void
    {
        $this->bindingId = $binding->id;
        $this->refreshRecords();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return !$this->recordsUnavailable && isset($this->snapshot['revision']) && Gate::allows('update', $this->binding()) && !in_array($this->outcome['state'] ?? '', ['uncertain', 'pending_replication'], true);
    }

    private function recoverPending(): void
    {
        $op = DnsOperation::where('binding_id', $this->bindingId)->whereIn('state', ['prepared', 'uncertain', 'pending_replication'])->latest()->first();
        if ($op) {
            $this->lastRequestId = $op->id;
            $this->outcome = ['state' => $op->state === 'prepared' ? 'uncertain' : $op->state, 'code' => $op->code ?? 'OUTCOME_UNCONFIRMED'];
        }
        unset($this->canEdit);
    }

    public function refreshRecords(): void
    {
        $b = $this->binding();
        BindAccess::assertAllowed(Auth::user(), $b, 'inspect');
        $this->recoverPending();
        try {
            $this->snapshot = app(ZoneManager::class)->inspect(Auth::user(), $b)['data'];
            $this->recordsUnavailable = false;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\RuntimeException|\InvalidArgumentException) {
            $this->snapshot = [];
            $this->recordsUnavailable = true;
        }
        unset($this->canEdit);
        if (!in_array($this->outcome['state'] ?? '', ['uncertain', 'pending_replication'], true)) {
            $this->requestId = (string) Str::uuid();
        }
    }

    public function edit(int $index): void
    {
        BindAccess::assertAllowed(Auth::user(), $this->binding(), 'replace_records');
        abort_unless($this->canEdit && isset($this->snapshot['records'][$index]), 403);
        $r = $this->snapshot['records'][$index];
        $this->owner = $r['owner'];
        $this->type = $r['type'];
        $this->ttl = $r['ttl'];
        $this->recordsText = implode("\n", $r['records']);
    }

    public function save(): void
    {
        $b = $this->binding();
        BindAccess::assertAllowed(Auth::user(), $b, 'replace_records');
        $this->recoverPending();
        abort_unless($this->canEdit, 403);
        $this->validate(['owner' => 'required|string|max:253', 'type' => 'required|in:A,AAAA,CNAME,MX,TXT,CAA,SRV', 'ttl' => 'required|integer|min:1|max:2147483647', 'recordsText' => 'present|string|max:24000']);
        $values = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $this->recordsText)), fn ($v) => $v !== ''));
        try {
            $this->lastRequestId = $this->requestId;
            $this->outcome = app(ZoneManager::class)->replace(Auth::user(), $b, ['request_id' => $this->requestId, 'expected_serial' => $this->snapshot['serial'], 'expected_revision' => $this->snapshot['revision'], 'changes' => [['owner' => $this->owner, 'type' => $this->type, 'ttl' => $this->ttl, 'records' => $values]]]);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            if ($e instanceof AuthorizationException) {
                throw $e;
            }$this->recoverPending();
            $this->addError('recordsText', __('bind::bind.refresh_required'));

            return;
        }
        unset($this->canEdit);
        if (in_array($this->outcome['state'] ?? '', ['committed', 'rejected'], true)) {
            $this->refreshRecords();
        }
    }

    public function reconcile(): void
    {
        abort_unless($this->lastRequestId !== '' && in_array($this->outcome['state'] ?? '', ['uncertain', 'pending_replication'], true), 403);
        $b = $this->binding();
        BindAccess::assertAllowed(Auth::user(), $b, 'inspect_request');
        $this->outcome = app(ZoneManager::class)->reconcile(Auth::user(), $b, ['request_id' => $this->lastRequestId]);
        unset($this->canEdit);
        if (in_array($this->outcome['state'] ?? '', ['committed', 'rejected'], true)) {
            $this->refreshRecords();
        }
    }

    public function render()
    {
        $b = $this->binding();
        BindAccess::assertAllowed(Auth::user(), $b, 'inspect');
        $c = Extension::where('type', 'other')->where('extension', 'Bind')->first()?->settings->pluck('value', 'key')->all() ?? [];
        $nameservers = preg_split('/\s+/', trim((string) ($c['authoritative_nameservers'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);

        return view('bind::zones.show', ['zone' => $b->zone, 'nameservers' => $nameservers])->layoutData(['title' => 'DNS records', 'sidebar' => true]);
    }
}
