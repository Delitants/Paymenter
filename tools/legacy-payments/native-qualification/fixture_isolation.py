"""Pure offline fixture preparation. Never issues a native or financial grant."""
from dataclasses import dataclass
from enum import Enum
import re
import time


class IsolationError(ValueError):
    """Bounded refusal code; input content is never echoed."""


def _require(condition, code):
    if not condition:
        raise IsolationError(code)


def _digest(value):
    return type(value) is str and re.fullmatch(r'[0-9a-f]{64}', value) is not None


@dataclass(frozen=True)
class ImagePin:
    path: str
    identity: str
    sha256: str
    ancestors_sha256: str


@dataclass(frozen=True)
class FixtureProfile:
    version: str
    run: str
    module: str
    registry_sha256: str
    images: tuple[ImagePin, ...]
    protocol: str
    expires_monotonic_ns: int


@dataclass(frozen=True)
class EvidenceRef:
    kind: str
    sha256: str
    run: str
    registry_sha256: str


class NativeGate(Enum):
    N0 = 'N0'
    N1 = 'N1'
    N2 = 'N2'
    N3 = 'N3'


@dataclass(frozen=True)
class AdmissionDecision:
    allowed: bool
    missing: tuple[str, ...]


_EVIDENCE_KINDS = frozenset(('launcher', 'config', 'filter', 'fallback', 'creation',
    'financial-read', 'notification-direct', 'notification-queue', 'retirement'))


def module_name(run: str) -> str:
    _require(_digest(run), 'fixture-run')
    return 'pmqa' + run[:28]


def validate_profile(profile: FixtureProfile, now_ns: int,
                     occupied_names: frozenset[str]) -> None:
    _require(type(profile) is FixtureProfile, 'fixture-profile')
    _require(profile.version == 'paymenter-fixture-isolation-offline-v1' and
             profile.protocol == 'authored-config-v0', 'fixture-version')
    _require(profile.module == module_name(profile.run) and
             _digest(profile.registry_sha256), 'fixture-binding')
    _require(type(now_ns) is int and now_ns >= 0 and
             type(profile.expires_monotonic_ns) is int and
             profile.expires_monotonic_ns > now_ns, 'fixture-expired')
    _require(type(occupied_names) is frozenset and
             all(type(n) is str for n in occupied_names), 'fixture-absence-input')
    _require(profile.module not in occupied_names, 'fixture-collision')
    _require(type(profile.images) is tuple and 1 <= len(profile.images) <= 2,
             'fixture-images')
    paths = set()
    for image in profile.images:
        _require(type(image) is ImagePin, 'fixture-image')
        path = image.path
        _require(type(path) is str and 1 < len(path) < 4096 and path[0] == '/' and
                 all(32 < ord(c) < 127 and c != '\\' for c in path) and
                 all(part not in ('', '.', '..') and len(part) <= 255
                     for part in path[1:].split('/')), 'fixture-path')
        _require(path not in paths, 'fixture-image-duplicate')
        paths.add(path)
        _require(type(image.identity) is str and 0 < len(image.identity) <= 512 and
                 all(32 <= ord(c) < 127 for c in image.identity), 'fixture-identity')
        _require(_digest(image.sha256) and _digest(image.ancestors_sha256),
                 'fixture-image-digest')


def validate_profile_binding(actual: FixtureProfile, expected: FixtureProfile,
                             now_ns: int, occupied_names: frozenset[str]) -> None:
    validate_profile(expected, now_ns, occupied_names)
    validate_profile(actual, now_ns, occupied_names)
    _require(actual == expected, 'fixture-replaced')


def _validate_ref(ref: EvidenceRef, run: str, registry: str) -> None:
    _require(type(ref) is EvidenceRef and type(ref.kind) is str and ref.kind in _EVIDENCE_KINDS and
             _digest(ref.sha256) and ref.run == run and
             ref.registry_sha256 == registry, 'fixture-evidence-reference')


def offline_native_gate(gate: NativeGate, profile: FixtureProfile,
                        refs: tuple[EvidenceRef, ...]) -> AdmissionDecision:
    _require(type(gate) is NativeGate, 'fixture-gate')
    validate_profile(profile, time.monotonic_ns(), frozenset())
    _require(type(refs) is tuple and len(refs) <= len(_EVIDENCE_KINDS), 'fixture-references')
    seen = set()
    for ref in refs:
        _validate_ref(ref, profile.run, profile.registry_sha256)
        _require(ref.kind not in seen, 'fixture-reference-duplicate')
        seen.add(ref.kind)
    # Even complete authored references cannot establish custody, isolation,
    # native creator/session provenance or authority to execute a source action.
    return AdmissionDecision(False, ('native-evidence-verifier-absent',))


class FixtureState(Enum):
    ABSENT = 'absent'
    INTENT_RESERVED = 'intent-reserved'
    OUTCOME_KNOWN = 'outcome-known'
    OUTCOME_UNKNOWN = 'outcome-unknown'
    READBACK_BOUND = 'readback-bound'
    ISOLATION_QUALIFIED = 'isolation-qualified'  # No producer in this module.


@dataclass(frozen=True)
class Lifecycle:
    state: FixtureState
    run: str
    original_refs: tuple[EvidenceRef, ...]


def advance(current: Lifecycle, event: str, original: EvidenceRef) -> Lifecycle:
    _require(type(current) is Lifecycle and type(current.state) is FixtureState and
             current.state is not FixtureState.ISOLATION_QUALIFIED and
             _digest(current.run), 'fixture-lifecycle')
    _require(type(current.original_refs) is tuple and len(current.original_refs) < 128,
             'fixture-originals')
    _require(type(original) is EvidenceRef and _digest(original.registry_sha256),
             'fixture-original')
    seen = set()
    for ref in current.original_refs + (original,):
        _validate_ref(ref, current.run, original.registry_sha256)
        _require(ref.sha256 not in seen, 'fixture-original-duplicate')
        seen.add(ref.sha256)
    transitions = {
        (FixtureState.ABSENT, 'reserve-intent'): (FixtureState.INTENT_RESERVED, 'creation'),
        (FixtureState.INTENT_RESERVED, 'creation-result'): (FixtureState.OUTCOME_KNOWN, 'creation'),
        (FixtureState.INTENT_RESERVED, 'creation-error'): (FixtureState.OUTCOME_UNKNOWN, 'creation'),
        (FixtureState.INTENT_RESERVED, 'response-lost'): (FixtureState.OUTCOME_UNKNOWN, 'creation'),
        (FixtureState.OUTCOME_KNOWN, 'independent-readback'): (FixtureState.READBACK_BOUND, 'financial-read'),
        (FixtureState.OUTCOME_UNKNOWN, 'independent-readback'): (FixtureState.OUTCOME_UNKNOWN, 'financial-read'),
    }
    _require(type(event) is str and (current.state, event) in transitions,
             'fixture-transition')
    _require((current.state is FixtureState.ABSENT) == (not current.original_refs),
             'fixture-history')
    target, kind = transitions[(current.state, event)]
    _require(original.kind == kind, 'fixture-event-original')
    return Lifecycle(target, current.run, current.original_refs + (original,))


@dataclass(frozen=True)
class InvocationBudget:
    cold_config: bool = False
    post_commit_reload: bool = False
    failed: bool = False


def _validate_budget(budget):
    _require(type(budget) is InvocationBudget and
             all(type(v) is bool for v in (budget.cold_config, budget.post_commit_reload, budget.failed)),
             'fixture-invocation-budget')
    _require(not budget.post_commit_reload or budget.cold_config, 'fixture-invocation-order')


def consume_slot(budget: InvocationBudget, slot: str) -> InvocationBudget:
    _validate_budget(budget)
    _require(not budget.failed, 'fixture-invocation-failed')
    if slot == 'cold-config' and not budget.cold_config:
        return InvocationBudget(True, False, False)
    if slot == 'post-commit-reload' and budget.cold_config and not budget.post_commit_reload:
        return InvocationBudget(True, True, False)
    raise IsolationError('fixture-invocation-slot')


def fail_budget(budget: InvocationBudget) -> InvocationBudget:
    _validate_budget(budget)
    return InvocationBudget(budget.cold_config, budget.post_commit_reload, True)


@dataclass(frozen=True)
class RetentionDecision:
    retain_evidence: bool
    retain_audit: bool
    retain_inert_image: bool
    may_delete_or_refund: bool


def retention_decision(paid_or_unknown: bool, module_referenced: bool) -> RetentionDecision:
    _require(type(paid_or_unknown) is bool and type(module_referenced) is bool,
             'fixture-retention-input')
    return RetentionDecision(True, paid_or_unknown, module_referenced, False)
