"""Pure cgroup-v1 census comparison. Does not read /proc or write cgroups.

Every census is an untrusted planning input. Even internally consistent FROZEN
claims keep native qualification blocked; no physical readback is produced.
"""
from dataclasses import asdict
from pathlib import PurePosixPath
from boundary_refs import entry_count
from containment_schema import (Admission, ContainmentError, ControlContext,
    MAX_ENTRIES, canonical, digest, document, integer, keys, require, sha, text)


def _path(value):
    require(type(value) is str and 1 < len(value) <= 4096 and '\0' not in value)
    path = PurePosixPath(value)
    require(path.is_absolute() and '..' not in path.parts and str(path) == value
            and not value.startswith('//'), 'process-cgroup-path')


def _inside(path, domain):
    return path == domain or path.startswith(domain+'/')


def _parse(raw, context):
    value = document(raw)
    keys(value, 'schema_version purpose context_sha256 boot_id kernel_sha256 '
                'controller domain groups tasks unresolved')
    integer(value['schema_version'], 1, 1)
    require(value['purpose'] == 'source-containment-process-preflight')
    sha(value['context_sha256']); sha(value['kernel_sha256']); text(value['boot_id'])
    require(value['context_sha256'] == digest(canonical(asdict(context))), 'process-context-drift')
    require(value['controller'] == 'cgroup-v1-freezer', 'process-controller')
    _path(value['domain'])
    for field in ('groups', 'tasks', 'unresolved'):
        require(type(value[field]) is list and len(value[field]) <= MAX_ENTRIES)
    groups = {}
    for row in value['groups']:
        keys(row, 'path state'); _path(row['path'])
        require(_inside(row['path'], value['domain']), 'process-group-scope')
        require(row['state'] in ('FROZEN', 'FREEZING', 'THAWED'))
        require(row['path'] not in groups, 'process-duplicate-group')
        groups[row['path']] = row['state']
    tasks = {}; tids = set()
    for row in value['tasks']:
        keys(row, 'pid tid start_ticks leader_start_ticks executable_sha256 uid gid '
                  'parent_pid parent_start_ticks cgroup role')
        for field in ('pid', 'tid', 'start_ticks', 'leader_start_ticks'):
            integer(row[field], 1)
        for field in ('uid', 'gid', 'parent_pid', 'parent_start_ticks'):
            integer(row[field])
        sha(row['executable_sha256']); _path(row['cgroup'])
        require(row['role'] in ('selected', 'unrelated', 'unknown'))
        identity = (row['pid'], row['tid'])
        require(identity not in tasks and row['tid'] not in tids, 'process-duplicate-task')
        tasks[identity] = row; tids.add(row['tid'])
    for code in value['unresolved']: text(code)
    return value, groups, tasks


def _audit(value, groups, tasks):
    blockers = set(value['unresolved'])
    domain = value['domain']
    selected = {key: row for key, row in tasks.items() if row['role'] == 'selected'}
    if not selected: blockers.add('process-empty-domain')
    if domain not in groups or any(row['cgroup'] not in groups for row in selected.values()):
        blockers.add('process-group-coverage')
    # Include empty/nested groups too: an unobserved subtree cannot be ignored.
    if any(state != 'FROZEN' for state in groups.values()):
        blockers.add('process-effective-freeze-unproved')
    for row in tasks.values():
        leader = tasks.get((row['pid'], row['pid']))
        if leader is None:
            blockers.add('process-thread-leader-missing')
        elif row['leader_start_ticks'] != leader['start_ticks']:
            blockers.add('process-thread-identity-unproved')
        elif (row['role'] != leader['role'] or row['cgroup'] != leader['cgroup'] or
              row['executable_sha256'] != leader['executable_sha256'] or
              row['uid'] != leader['uid'] or row['gid'] != leader['gid']):
            blockers.add('process-shared-thread-domain')
        if row['role'] == 'unknown': blockers.add('process-unattributed-task')
        if row['role'] == 'selected' and not _inside(row['cgroup'], domain):
            blockers.add('process-escaped-task')
        if row['role'] != 'selected' and _inside(row['cgroup'], domain):
            blockers.add('process-collateral-task')
        if row['parent_pid']:
            parent = tasks.get((row['parent_pid'], row['parent_pid']))
            if parent is None or parent['start_ticks'] != row['parent_start_ticks']:
                blockers.add('process-ancestry-unproved')
            elif parent['role'] == 'selected' and row['role'] != 'selected':
                blockers.add('process-descendant-scope')
    # Detect ancestry cycles without recursive traversal or an unbounded walk.
    done = set()
    for pid, tid in tasks:
        if pid != tid or pid in done: continue
        path = set(); node = pid
        while node and node not in done:
            if node in path:
                blockers.add('process-ancestry-unproved'); break
            path.add(node)
            leader = tasks.get((node, node))
            if leader is None: break
            node = leader['parent_pid']
        done.update(path)
    return blockers, selected


def inspect_process_domain(before_raw: bytes, after_raw: bytes,
                           context: ControlContext) -> Admission:
    """Compare claimed identities; never substitute for an independent observer."""
    require(type(context) is ControlContext)
    before, before_groups, old_tasks = _parse(before_raw, context)
    after, after_groups, new_tasks = _parse(after_raw, context)
    require(entry_count(before)+entry_count(after) <= MAX_ENTRIES, 'reference-budget')
    blockers, old_selected = _audit(before, before_groups, old_tasks)
    extra, new_selected = _audit(after, after_groups, new_tasks)
    blockers.update(extra); blockers.add('process-native-runtime-unqualified')
    if before['boot_id'] != context.source_boot_id or after['boot_id'] != context.source_boot_id:
        blockers.add('process-boot-mismatch')
    if before['kernel_sha256'] != after['kernel_sha256']:
        blockers.add('process-kernel-drift')
    if before['domain'] != after['domain']: blockers.add('process-domain-drift')
    if set(before_groups) != set(after_groups): blockers.add('process-group-coverage')
    if set(old_selected) != set(new_selected): blockers.add('process-membership-changed')
    if any(old_selected[key] != new_selected[key] for key in set(old_selected) & set(new_selected)):
        blockers.add('process-identity-changed')
    return Admission(tuple(sorted(blockers)), ())


def establish_process(ctx):
    raise ContainmentError('process-native-runtime-unqualified')


def read_process(ctx):
    raise ContainmentError('process-native-runtime-unqualified')


def recover_process(ctx):
    raise ContainmentError('process-native-runtime-unqualified')


def release_process(ctx, retirement):
    raise ContainmentError('process-native-runtime-unqualified')
