"""Read-only admission against complete pinned originals; no transport or SQL."""
from dataclasses import asdict
from pathlib import Path
import time
from boundary_refs import ReferenceSet, entry_count
from boundary_schema import BoundaryError
from containment_schema import (Admission, ContainmentError, ControlContext,
    EvidenceRef, CONTROL_IDS, MAX_GRAPH, MAX_ENTRIES, MAX_DEPTH, MAX_AGE_NS,
    document, evidence_ref, integer, keys, require, sha, text)


def boot_id():
    try:
        return Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    except OSError:
        raise ContainmentError('observer-unavailable') from None


def validate_evidence(v):
    require(type(v) is dict)
    integer(v.get('schema_version'), 1, 1)
    p = v.get('purpose')
    if p == 'source-containment-authority':
        keys(v, 'schema_version purpose source_boot_id automated_actors manual_actors exclusive_maintenance refs')
        text(v['source_boot_id'])
        for slot in ('automated_actors', 'manual_actors'):
            require(type(v[slot]) is list and len(set(v[slot])) == len(v[slot]))
            for actor in v[slot]: text(actor)
        require(not set(v['automated_actors']) & set(v['manual_actors']))
        require(type(v['exclusive_maintenance']) is bool)
    elif p == 'source-containment-budget':
        keys(v, 'schema_version purpose root_reserve ram_reserve staging_limit memory_limit '
                'evidence_limit pids_limit swap_limit full_cohort_accepted refs')
        integer(v['root_reserve'], 1207959552)
        integer(v['ram_reserve'], 536870912)
        for field, cap in [('staging_limit', 268435456), ('memory_limit', 536870912),
                           ('evidence_limit', 16777216), ('pids_limit', 64)]:
            integer(v[field], 1, cap)
        integer(v['swap_limit'], 0, 0)
        require(type(v['full_cohort_accepted']) is bool)
    elif p == 'source-containment-profile':
        keys(v, 'schema_version purpose source_boot_id assignments refs')
        text(v['source_boot_id'])
        require(type(v['assignments']) is list)
        for row in v['assignments']:
            keys(row, 'object_id object_sha256 control_id role')
            text(row['object_id']); sha(row['object_sha256'])
            require(row['control_id'] in CONTROL_IDS)
            require(row['role'] in ('selected', 'unrelated', 'contained-unknown'))
    elif p == 'source-containment-observation':
        keys(v, 'schema_version purpose source_boot_id observer_boot_id end_ns objects unresolved original_refs refs')
        text(v['source_boot_id']); text(v['observer_boot_id'])
        require(type(v['end_ns']) is str and v['end_ns'].isdigit())
        integer(int(v['end_ns']))
        require(str(int(v['end_ns'])) == v['end_ns'])
        require(type(v['objects']) is list and type(v['unresolved']) is list)
        for row in v['objects']:
            keys(row, 'object_id object_sha256 dependency_ids writer_id')
            text(row['object_id']); sha(row['object_sha256'])
            require(type(row['dependency_ids']) is list)
            for obj in row['dependency_ids']: text(obj)
            if row['writer_id'] is not None: text(row['writer_id'])
        for code in v['unresolved']: text(code)
        require(type(v['original_refs']) is list)
        for r in v['original_refs']: evidence_ref(r)
    elif p == 'source-containment-owned-runtime':
        keys(v, 'schema_version purpose kind source_boot_id adapter_manifest_sha256 refs')
        require(v['kind'] == 'sql', 'runtime-unqualified')
        text(v['source_boot_id']); sha(v['adapter_manifest_sha256'])
    elif p == 'source-containment-evidence':
        keys(v, 'schema_version purpose refs')
    else:
        raise ContainmentError('evidence-purpose')
    require(type(v['refs']) is list)
    for r in v['refs']: evidence_ref(r)
    return v


class EvidenceGraph:
    """New closed graph, reusing the existing anchored ordinary-file reader."""
    def __init__(self, root):
        self.reader = ReferenceSet(root)
        self.cache = {}
        self.raw_cache = {}
        self.heights = {}
        self.total = 0
        self.entries = 0

    def __enter__(self): return self
    def __exit__(self, *args): self.reader.close()

    def original(self, ref, depth):
        require(type(ref) is EvidenceRef and depth <= MAX_DEPTH, 'reference-depth')
        if ref.path in self.raw_cache:
            previous, raw = self.raw_cache[ref.path]
            require(previous == ref, 'reference-hash')
            return raw
        raw = self.reader._read({'path': ref.path, 'sha256': ref.sha256})
        require(len(raw) == ref.size, 'reference-size')
        self.total += len(raw)
        require(self.total <= MAX_GRAPH, 'reference-budget')
        self.raw_cache[ref.path] = (ref, raw)
        return raw

    def visit(self, ref, depth=1, active=frozenset()):
        require(type(ref) is EvidenceRef)
        require(depth <= MAX_DEPTH, 'reference-depth')
        require(ref.path not in active, 'reference-cycle')
        if ref.path in self.cache:
            previous, value = self.cache[ref.path]
            require(previous == ref, 'reference-hash')
            require(depth+self.heights[ref.path]-1 <= MAX_DEPTH, 'reference-depth')
            return value
        raw = self.original(ref, depth)
        value = document(raw)
        self.entries += entry_count(value)
        require(self.entries <= MAX_ENTRIES, 'reference-budget')
        value = validate_evidence(value)
        children = tuple(evidence_ref(r) for r in value['refs'])
        for child in children:
            self.visit(child, depth+1, active | {ref.path})
        originals = tuple(evidence_ref(r) for r in value.get('original_refs', []))
        for original in originals:
            self.original(original, depth+1)
        self.heights[ref.path] = 1+max(
            [self.heights[r.path] for r in children]+([1] if originals else [0]))
        self.cache[ref.path] = (ref, value)
        return value

    def verify(self): self.reader.verify_unchanged()


def evaluate_admission(context, observations, accepted):
    require(type(context) is ControlContext)
    for rows in (observations, accepted):
        require(type(rows) is tuple and len(rows) <= MAX_ENTRIES)
        require(all(type(r) is EvidenceRef for r in rows))
    require(observations, 'observation-missing')
    blockers = {'physical-control-unaccepted'}
    try:
        with EvidenceGraph(Path(context.authority_ref.path).parent) as graph:
            authority = graph.visit(context.authority_ref)
            budget = graph.visit(context.budget_ref)
            require(authority['purpose'] == 'source-containment-authority' and
                    budget['purpose'] == 'source-containment-budget', 'reference-kind')
            profiles = [graph.visit(r) for r in accepted]
            require(len(profiles) == 1 and profiles[0]['purpose'] ==
                    'source-containment-profile', 'profile-missing')
            profile = profiles[0]
            require(accepted[0].sha256 == context.profile_sha256, 'profile-hash')
            if any(v['source_boot_id'] != context.source_boot_id for v in (authority, profile)):
                blockers.add('source-boot')
            if not authority['exclusive_maintenance']:
                blockers.add('maintenance-unaccepted')
            if not budget['full_cohort_accepted']:
                blockers.add('full-cohort-unbudgeted')
            objects = {}
            now, observer_boot = time.monotonic_ns(), boot_id()
            for ref in observations:
                obs = graph.visit(ref)
                require(obs['purpose'] == 'source-containment-observation', 'reference-kind')
                if obs['source_boot_id'] != context.source_boot_id: blockers.add('source-boot')
                if obs['observer_boot_id'] != observer_boot: blockers.add('observer-boot')
                age = now-int(obs['end_ns'])
                if not obs['original_refs']: blockers.add('observation-original-unaccepted')
                if age < 0: blockers.add('observation-future')
                elif age > MAX_AGE_NS: blockers.add('observation-stale')
                blockers.update(obs['unresolved'])
                for obj in obs['objects']:
                    if obj['object_id'] in objects: blockers.add('duplicate-object')
                    objects[obj['object_id']] = obj
            assignments = profile['assignments']
            ids = [r['object_id'] for r in assignments]
            if len(ids) != len(set(ids)) or set(ids) != set(objects):
                blockers.add('profile-coverage')
            for row in assignments:
                obj = objects.get(row['object_id'])
                if obj is not None and obj['object_sha256'] != row['object_sha256']:
                    blockers.add('object-drift')
                if row['role'] == 'contained-unknown': blockers.add('containment-unaccepted')
            known = set(authority['automated_actors']) | set(authority['manual_actors'])
            for obj in objects.values():
                if not set(obj['dependency_ids']) <= set(objects): blockers.add('missing-dependency')
                if obj['writer_id'] is not None and obj['writer_id'] not in known:
                    blockers.add('unknown-writer')
            graph.verify()
    except BoundaryError as exc:
        raise ContainmentError(str(exc)) from None
    return Admission(tuple(sorted(blockers)), observations)
