"""Pure proposal completeness, deliberately separate from native admission."""
from dataclasses import dataclass
from datetime import datetime, timezone
import hashlib
import re

from _schema import AdmissionError, CASES, EvidenceRef, digest, document, keys, reference, require, text, utc

TABLES = ('paymenter_qa_business_v1', 'paymenter_qa_journal_v1')
COMMON = 'schema_version purpose run_id runtime_id boot_id expires_at '
SLOTS = ('runtime', 'build', 'registry', 'authority')
FIELDS = {
    'runtime': 'observed_at platform engine header_namespace',
    'build': 'runtime_sha256 module_sha256 registration install unload access',
    'registry': 'retired tables tables_initially_absent objects used_ids',
    'authority': 'mode intent registry_sha256 actor actor_permission manual_lookup manual_calls retirement isolation',
}


@dataclass(frozen=True)
class Profile:
    schema_version: int
    mode: str
    run_id: str
    runtime: EvidenceRef
    build: EvidenceRef
    registry: EvidenceRef
    authority: EvidenceRef
    expires_at: str

    def __post_init__(self):
        require(type(self.schema_version) is int and self.schema_version == 1, 'profile-version')
        require(type(self.mode) is str and self.mode in ('framework', 'manual'), 'profile-mode')
        digest(self.run_id)
        utc(self.expires_at)
        require(all(type(getattr(self, slot)) is EvidenceRef for slot in SLOTS), 'reference')
        for slot in SLOTS:
            getattr(self, slot).__post_init__()
        require(len({getattr(self, slot).path for slot in SLOTS}) == 4, 'reference-alias')


@dataclass(frozen=True)
class Decision:
    ready: bool
    blockers: tuple[str, ...]
    framework_atomicity: bool = False
    manual_atomicity: bool = False
    provider_validation: bool = False
    financial_posting_ready: bool = False

    def __post_init__(self):
        require(type(self.ready) is bool and type(self.blockers) is tuple and
                self.blockers == tuple(sorted(set(self.blockers))) and
                self.ready == (not self.blockers), 'decision')
        require(all(type(v) is bool and not v for v in
                    (self.framework_atomicity, self.manual_atomicity,
                     self.provider_validation, self.financial_posting_ready)), 'proposal-not-acceptance')


class AdmittedRun:
    """Reserved opaque capability. No native issuer is implemented in this increment."""
    __slots__ = ()

    def __new__(cls, *args, **kwargs):
        raise AdmissionError('native-issuer-unavailable')


@dataclass(frozen=True)
class Operation:
    kind: str
    case_id: str | None
    resource: EvidenceRef

    def __post_init__(self):
        require(type(self.kind) is str and self.kind in ('verify_identity', 'compile_owned_module', 'install_owned_module',
                              'create_qa_tables', 'run_case', 'observe_case', 'retire_owned_resources'),
                'operation-kind')
        require((self.case_id in CASES if self.kind in ('run_case', 'observe_case')
                 else self.case_id is None), 'operation-case')
        require(type(self.resource) is EvidenceRef, 'operation-resource')
        self.resource.__post_init__()


def _sequence(profile):
    sequence = [Operation('verify_identity', None, profile.runtime),
                Operation('compile_owned_module', None, profile.build),
                Operation('install_owned_module', None, profile.build),
                Operation('create_qa_tables', None, profile.registry)]
    for case_id in CASES[:5] if profile.mode == 'framework' else CASES:
        sequence.extend((Operation('run_case', case_id, profile.authority),
                         Operation('observe_case', case_id, profile.registry)))
    sequence.append(Operation('retire_owned_resources', None, profile.registry))
    return tuple(sequence)


@dataclass(frozen=True)
class OperationPlan:
    profile: Profile
    operations: tuple[Operation, ...]

    def __post_init__(self):
        require(type(self.profile) is Profile and type(self.operations) is tuple and
                all(type(op) is Operation for op in self.operations) and
                self.operations == _sequence(self.profile), 'operation-order')


def parse_profile(raw: bytes) -> Profile:
    value = document(raw)
    keys(value, 'schema_version mode run_id runtime build registry authority expires_at')
    return Profile(value['schema_version'], value['mode'], value['run_id'],
                   *(reference(value[slot]) for slot in SLOTS), value['expires_at'])


def _review(value):
    require(type(value) is str and value in ('reviewed', 'unqualified'), 'evidence-value')


def _originals(profile, evidence):
    require(type(evidence) is dict and set(evidence) ==
            {getattr(profile, slot).path for slot in SLOTS}, 'evidence-set')
    documents = {}
    for slot in SLOTS:
        ref = getattr(profile, slot)
        raw = evidence[ref.path]
        require(type(raw) is bytes and len(raw) == ref.bytes, 'evidence-bytes')
        require(hashlib.sha256(raw).hexdigest() == ref.sha256, 'evidence-hash')
        value = document(raw)
        keys(value, COMMON + FIELDS[slot])
        require(type(value['schema_version']) is int and value['schema_version'] == 1 and
                value['purpose'] == 'source-payment-qa-' + slot, 'evidence-purpose')
        digest(value['run_id'])
        text(value['runtime_id'], 128)
        text(value['boot_id'], 128)
        utc(value['expires_at'])
        documents[slot] = value
    runtime, build, registry, authority = (documents[slot] for slot in SLOTS)
    utc(runtime['observed_at'])
    for field in ('platform', 'engine', 'header_namespace'):
        text(runtime[field], 128)
    digest(build['runtime_sha256'])
    digest(build['module_sha256'])
    for field in ('registration', 'install', 'unload', 'access'):
        _review(build[field])
    require(type(registry['retired']) is bool and type(registry['tables_initially_absent']) is bool,
            'registry-type')
    require(type(registry['tables']) is list and all(type(v) is str for v in registry['tables']),
            'registry-type')
    require(type(registry['objects']) is list and type(registry['used_ids']) is list,
            'registry-type')
    for key in registry['used_ids']:
        text(key, 128)
    for row in registry['objects']:
        keys(row, 'kind id parent created_by_run migration_selected services currency initial_balance')
        require(row['kind'] in ('account', 'subaccount', 'payment'), 'fixture-kind')
        require(type(row['id']) is str and re.fullmatch(r'[1-9][0-9]{0,9}', row['id']) and
                int(row['id']) <= 2147483647, 'fixture-id')
        require(row['parent'] is None or type(row['parent']) is str, 'fixture-parent')
        digest(row['created_by_run'])
        require(type(row['migration_selected']) is bool and type(row['services']) is int and
                row['services'] >= 0, 'fixture-type')
        text(row['currency'], 8)
        text(row['initial_balance'], 32)
    require(authority['mode'] in ('framework', 'manual') and authority['intent'] == 'prepare-only',
            'authority-scope')
    digest(authority['registry_sha256'])
    text(authority['actor'], 128)
    for field in ('actor_permission', 'manual_lookup', 'manual_calls', 'retirement'):
        _review(authority[field])
    keys(authority['isolation'], 'mail queue provider')
    for value in authority['isolation'].values():
        _review(value)
    return documents


def assess(profile: Profile, evidence: dict) -> Decision:
    """Check hash-bound proposal documents only. This issues no admitted run."""
    require(type(profile) is Profile, 'profile-type')
    # Revalidate constructor invariants even if a caller bypassed frozen assignment.
    profile.__post_init__()
    blockers = set()
    now = datetime.now(timezone.utc)
    expiry = utc(profile.expires_at)
    if expiry <= now:
        blockers.add('profile-expired')
    elif (expiry - now).total_seconds() > 600:
        blockers.add('profile-lifetime')
    try:
        documents = _originals(profile, evidence)
    except AdmissionError as exc:
        blockers.add(str(exc))
        return Decision(False, tuple(sorted(blockers)))
    runtime, build, registry, authority = (documents[slot] for slot in SLOTS)
    for slot, value in documents.items():
        if (value['run_id'] != profile.run_id or value['runtime_id'] != runtime['runtime_id'] or
                value['boot_id'] != runtime['boot_id']):
            blockers.add('runtime-binding')
        if utc(value['expires_at']) <= now:
            blockers.add(slot + '-expired')
    age = (now - utc(runtime['observed_at'])).total_seconds()
    if age < 0:
        blockers.add('runtime-future')
    elif age > 600:
        blockers.add('runtime-stale')
    if runtime['platform'] != 'linux':
        blockers.add('runtime-platform')
    if runtime['engine'] != 'InnoDB':
        blockers.add('database-engine')
    if runtime['header_namespace'] != 'billmgr/payment':
        blockers.add('sdk-namespace')
    if build['runtime_sha256'] != profile.runtime.sha256:
        blockers.add('build-runtime-binding')
    for field in ('registration', 'install', 'unload', 'access'):
        if build[field] != 'reviewed':
            blockers.add('build-' + field)
    if tuple(registry['tables']) != TABLES:
        blockers.add('registry-tables')
    if not registry['tables_initially_absent']:
        blockers.add('table-collision')
    if registry['retired']:
        blockers.add('registry-retired')
    rows = registry['objects']
    identities = [row['kind'] + ':' + row['id'] for row in rows]
    if len(set(identities)) != len(identities):
        blockers.add('fixture-duplicate')
    if set(identities) & set(registry['used_ids']):
        blockers.add('fixture-reused')
    for row in rows:
        for condition, code in [
            (row['created_by_run'] == profile.run_id, 'fixture-owner'),
            (not row['migration_selected'], 'fixture-cohort'),
            (row['services'] == 0, 'fixture-services'),
            (row['currency'] == 'USD', 'fixture-currency'),
            (row['initial_balance'] == '0.0000', 'fixture-balance'),
        ]:
            if not condition:
                blockers.add(code)
    if authority['mode'] != profile.mode or authority['registry_sha256'] != profile.registry.sha256:
        blockers.add('authority-binding')
    if authority['actor_permission'] != 'reviewed':
        blockers.add('authority-actor')
    if profile.mode == 'framework':
        if rows:
            blockers.add('framework-native-objects')
    else:
        groups = {kind: [row for row in rows if row['kind'] == kind]
                  for kind in ('account', 'subaccount', 'payment')}
        if tuple(len(groups[k]) for k in groups) != (1, 1, 4):
            blockers.add('fixture-population')
        else:
            account, subaccount = groups['account'][0], groups['subaccount'][0]
            if (account['parent'] is not None or subaccount['parent'] != account['id'] or
                    any(row['parent'] != subaccount['id'] for row in groups['payment'])):
                blockers.add('fixture-parent')
        for field in ('actor_permission', 'manual_lookup', 'manual_calls', 'retirement'):
            if authority[field] != 'reviewed':
                blockers.add('manual-' + field)
        for field, value in authority['isolation'].items():
            if value != 'reviewed':
                blockers.add('manual-isolation-' + field)
    return Decision(not blockers, tuple(sorted(blockers)))


def build_operation_plan(profile: Profile, evidence: dict) -> OperationPlan:
    decision = assess(profile, evidence)
    require(decision.ready, 'proposal-blocked')
    return OperationPlan(profile, _sequence(profile))
