"""Pure offline diagnostic policy. Authored references never grant native access."""
from dataclasses import dataclass
from enum import Enum
import re
import time

from fixture_isolation import ImagePin, NativeGate, AdmissionDecision, IsolationError, module_name


class DiagnosticPolicy(Enum):
    EXISTING_ADMIN_PROBLEMS_ALLOWED = 'existing-admin-problems-allowed-customer-notices-forbidden-v1'


class PolicyEvidenceKind(Enum):
    CUSTOMER_DIRECT = 'customer-direct-prevention'
    CUSTOMER_QUEUE = 'customer-queue-prevention'
    ADMIN_SCOPE = 'administrative-problem-scope'


@dataclass(frozen=True)
class PolicyProfile:
    version: str
    protocol: str
    policy: DiagnosticPolicy
    run: str
    module: str
    registry_sha256: str
    generation: str
    images: tuple[ImagePin, ...]
    expires_monotonic_ns: int


@dataclass(frozen=True)
class PolicyEvidenceRef:
    kind: PolicyEvidenceKind
    sha256: str
    run: str
    registry_sha256: str
    generation: str


def _require(condition, code):
    if not condition:
        raise IsolationError(code)


def _digest(value):
    return type(value) is str and re.fullmatch(r'[0-9a-f]{64}', value) is not None


def validate_policy_profile(profile: PolicyProfile, now_ns: int,
                            occupied_names: frozenset[str]) -> None:
    code = 'fixture-policy-profile'
    _require(type(profile) is PolicyProfile, code)
    _require(type(profile.version) is str and
             profile.version == 'paymenter-fixture-policy-offline-v2' and
             type(profile.protocol) is str and
             profile.protocol == 'authored-vendor-config-candidate-v1' and
             type(profile.policy) is DiagnosticPolicy, code)
    _require(_digest(profile.run) and _digest(profile.registry_sha256) and
             _digest(profile.generation), code)
    _require(type(profile.module) is str and profile.module == module_name(profile.run), code)
    _require(type(now_ns) is int and now_ns >= 0 and
             type(profile.expires_monotonic_ns) is int and
             profile.expires_monotonic_ns > now_ns, code)
    _require(type(occupied_names) is frozenset and
             all(type(n) is str for n in occupied_names), code)
    _require(profile.module not in occupied_names, code)
    _require(type(profile.images) is tuple and 1 <= len(profile.images) <= 2, code)
    paths = set()
    for image in profile.images:
        _require(type(image) is ImagePin, code)
        path = image.path
        _require(type(path) is str and 1 < len(path) < 4096 and path[0] == '/' and
                 all(32 < ord(c) < 127 and c != '\\' for c in path) and
                 all(part not in ('', '.', '..') and len(part) <= 255
                     for part in path[1:].split('/')), code)
        _require(path not in paths, code)
        paths.add(path)
        _require(type(image.identity) is str and 0 < len(image.identity) <= 512 and
                 all(32 <= ord(c) < 127 for c in image.identity), code)
        _require(_digest(image.sha256) and _digest(image.ancestors_sha256), code)


def offline_policy_gate(gate: NativeGate, profile: PolicyProfile,
                        refs: tuple[PolicyEvidenceRef, ...]) -> AdmissionDecision:
    _require(type(gate) is NativeGate, 'fixture-policy-gate')
    validate_policy_profile(profile, time.monotonic_ns(), frozenset())
    code = 'fixture-policy-evidence'
    _require(type(refs) is tuple and len(refs) <= 3, code)
    kinds, digests = set(), set()
    for ref in refs:
        _require(type(ref) is PolicyEvidenceRef, code)
        _require(type(ref.kind) is PolicyEvidenceKind and _digest(ref.sha256) and
                 _digest(ref.run) and ref.run == profile.run and
                 _digest(ref.registry_sha256) and ref.registry_sha256 == profile.registry_sha256 and
                 _digest(ref.generation) and ref.generation == profile.generation, code)
        _require(ref.kind not in kinds and ref.sha256 not in digests, code)
        kinds.add(ref.kind)
        digests.add(ref.sha256)
    # Even complete references cannot prove prevention, native provenance,
    # administrative audience/settings, delivery or authority to execute.
    return AdmissionDecision(False, ('native-evidence-verifier-absent',))
