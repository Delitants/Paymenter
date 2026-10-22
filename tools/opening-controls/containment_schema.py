"""Closed planning/owned-qualification values. Never financial authority."""
from dataclasses import asdict, dataclass
import hashlib
import json
from pathlib import PurePosixPath
import re
from custody import strict_json

MAX_PART = 4 * 1024**2
MAX_GRAPH = 32 * 1024**2
MAX_ENTRIES = 10000
MAX_DEPTH = 8
MAX_AGE_NS = 60 * 10**9
CONTROL_IDS = frozenset(('source-http', 'source-direct-manager', 'source-cron',
    'source-process', 'source-sql', 'source-poller', 'source-notifications',
    'target-writers', 'legacy-billing-ownership', 'secondary-lock-keeper'))


class ContainmentError(RuntimeError):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def require(condition, code='containment-form'):
    if not condition:
        raise ContainmentError(code)


def keys(value, names):
    require(type(value) is dict and set(value) == set(names.split()))


def integer(value, low=0, high=2**63-1):
    require(type(value) is int and low <= value <= high)


def text(value):
    require(type(value) is str and re.fullmatch(r'[A-Za-z0-9_.:-]{1,128}', value))


def sha(value):
    require(type(value) is str and re.fullmatch('[a-f0-9]{64}', value))


def canonical(value):
    try:
        return json.dumps(value, sort_keys=True, separators=(',', ':'),
                          allow_nan=False).encode('utf-8')
    except (TypeError, ValueError, RecursionError):
        raise ContainmentError('containment-json') from None


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def document(raw):
    require(type(raw) is bytes and len(raw) <= MAX_PART, 'document-size')
    try:
        value = strict_json(raw)
    except (ValueError, UnicodeError, RecursionError):
        raise ContainmentError('containment-json') from None
    stack = [(value, 0)]
    count = 0
    while stack:
        v, depth = stack.pop()
        require(depth <= 64, 'document-depth')
        require(type(v) is not float, 'containment-json')
        if type(v) is list:
            count += len(v)
            stack.extend((x, depth+1) for x in v)
        elif type(v) is dict:
            stack.extend((x, depth+1) for x in v.values())
        require(count <= MAX_ENTRIES, 'reference-budget')
    return value


@dataclass(frozen=True)
class EvidenceRef:
    path: str
    sha256: str
    size: int

    def __post_init__(self):
        require(type(self.path) is str and '\0' not in self.path and
                len(self.path) <= 4096, 'reference-path')
        p = PurePosixPath(self.path)
        require(p.is_absolute() and '..' not in p.parts and str(p) == self.path
                and self.path != '/', 'reference-path')
        sha(self.sha256)
        integer(self.size, 0, 256 * 1024**2)


def evidence_ref(value):
    keys(value, 'path sha256 size')
    return EvidenceRef(**value)


@dataclass(frozen=True)
class ControlContext:
    session_id: str
    freeze_id: str
    generation: int
    source_boot_id: str
    profile_sha256: str
    adapter_manifest_sha256: str
    authority_ref: EvidenceRef
    budget_ref: EvidenceRef

    def __post_init__(self):
        for v in (self.session_id, self.freeze_id, self.source_boot_id):
            text(v)
        integer(self.generation, 1)
        sha(self.profile_sha256)
        sha(self.adapter_manifest_sha256)
        require(type(self.authority_ref) is EvidenceRef and
                type(self.budget_ref) is EvidenceRef)


def control_context(value):
    keys(value, 'session_id freeze_id generation source_boot_id profile_sha256 '
                'adapter_manifest_sha256 authority_ref budget_ref')
    return ControlContext(**{**value,
        'authority_ref': evidence_ref(value['authority_ref']),
        'budget_ref': evidence_ref(value['budget_ref'])})


@dataclass(frozen=True)
class Admission:
    blockers: tuple[str, ...]
    observation_refs: tuple[EvidenceRef, ...]
    real_execution_ready: bool = False

    def __post_init__(self):
        require(type(self.blockers) is tuple and len(self.blockers) <= MAX_ENTRIES)
        require(tuple(sorted(set(self.blockers))) == self.blockers)
        for v in self.blockers:
            text(v)
        require(type(self.observation_refs) is tuple and
                len(self.observation_refs) <= MAX_ENTRIES)
        require(all(type(x) is EvidenceRef for x in self.observation_refs))
        require(self.real_execution_ready is False, 'readiness-forbidden')


@dataclass(frozen=True)
class AdapterReadback:
    control_id: str
    state: str
    generation: int
    context_sha256: str
    observer_ref: EvidenceRef
    observer_boot_id: str
    start_ns: int
    end_ns: int
    originals: tuple[EvidenceRef, ...]
    attempts: tuple[EvidenceRef, ...]
    failures: tuple[str, ...]

    def __post_init__(self):
        require(self.control_id in CONTROL_IDS)
        require(self.state in ('unproved', 'held', 'lost', 'recovery-required'))
        integer(self.generation, 1)
        sha(self.context_sha256)
        text(self.observer_boot_id)
        require(type(self.observer_ref) is EvidenceRef)
        integer(self.start_ns)
        integer(self.end_ns, self.start_ns)
        for rows in (self.originals, self.attempts, self.failures):
            require(type(rows) is tuple and len(rows) <= MAX_ENTRIES)
        require(all(type(r) is EvidenceRef for r in self.originals+self.attempts))
        for code in self.failures:
            text(code)
        if self.state == 'held':
            require(self.originals and self.attempts and not self.failures and
                    self.end_ns > self.start_ns, 'readback-unproved')


def admission_document(context, admission):
    require(type(context) is ControlContext and type(admission) is Admission)
    return canonical({'schema_version': 1, 'purpose': 'source-containment-admission',
        'context': asdict(context), **asdict(admission)})


def parse_admission(raw):
    v = document(raw)
    keys(v, 'schema_version purpose context observation_refs blockers real_execution_ready')
    integer(v['schema_version'], 1, 1)
    require(v['purpose'] == 'source-containment-admission')
    control_context(v['context'])
    require(type(v['blockers']) is list and type(v['observation_refs']) is list)
    return Admission(tuple(v['blockers']),
                     tuple(evidence_ref(x) for x in v['observation_refs']),
                     v['real_execution_ready'])


def readback_document(readback):
    require(type(readback) is AdapterReadback)
    value = asdict(readback)
    value['start_ns'], value['end_ns'] = str(readback.start_ns), str(readback.end_ns)
    return canonical({'schema_version': 1, 'purpose': 'source-containment-readback',
                      'real_execution_ready': False, **value})
