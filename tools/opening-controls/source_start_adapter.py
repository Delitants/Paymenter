"""Pure installed-runtime preflight. No vendor/service/start guard implementation.

There is deliberately no runtime registration or execution path. Caller-supplied
hashes describe a candidate; they cannot establish vendor side effects, persistent
denial, guard-before-dispatch order, or qualification of an owned environment.
"""
from dataclasses import asdict
from containment_schema import (Admission, ContainmentError, ControlContext,
    MAX_ENTRIES, canonical, digest, document, integer, keys, require, sha, text)

ARTIFACT_ROLES = frozenset(('manager', 'wrapper', 'configuration', 'library',
    'kernel', 'scheduler', 'service-manager'))
LAUNCH_KINDS = frozenset(('service', 'alias', 'direct-cli', 'manual', 'timer',
    'boot', 'socket'))


def inspect_panel_runtime(raw: bytes, context: ControlContext) -> Admission:
    """Syntax and missing-obligation inventory only; never physical readback."""
    require(type(context) is ControlContext)
    value = document(raw)
    keys(value, 'schema_version purpose context_sha256 runtime_id artifacts launchers unresolved')
    integer(value['schema_version'], 1, 1)
    require(value['purpose'] == 'source-containment-panel-preflight')
    sha(value['context_sha256']); text(value['runtime_id'])
    require(value['context_sha256'] == digest(canonical(asdict(context))), 'panel-context-drift')
    for name in ('artifacts', 'launchers', 'unresolved'):
        require(type(value[name]) is list and len(value[name]) <= MAX_ENTRIES)
    blockers = {'panel-native-runtime-unqualified', 'panel-installed-behavior-unaccepted',
                'panel-boot-order-unaccepted'}
    ids = set(); roles = set()
    for row in value['artifacts']:
        keys(row, 'object_id role sha256')
        text(row['object_id']); sha(row['sha256'])
        require(type(row['role']) is str and row['role'] in ARTIFACT_ROLES)
        require(row['object_id'] not in ids, 'panel-duplicate-object')
        ids.add(row['object_id']); roles.add(row['role'])
    if roles != ARTIFACT_ROLES: blockers.add('panel-artifact-coverage')
    kinds = set()
    for row in value['launchers']:
        keys(row, 'object_id kind role guard_sha256')
        text(row['object_id'])
        require(row['object_id'] not in ids, 'panel-duplicate-object')
        ids.add(row['object_id'])
        require(type(row['kind']) is str and row['kind'] in LAUNCH_KINDS)
        require(row['role'] in ('selected', 'shared', 'unrelated'))
        if row['guard_sha256'] is not None: sha(row['guard_sha256'])
        if row['role'] == 'selected':
            kinds.add(row['kind'])
            if row['guard_sha256'] is None: blockers.add('panel-start-guard-unproved')
        elif row['role'] == 'shared': blockers.add('panel-shared-launcher')
        elif row['guard_sha256'] is not None: blockers.add('panel-unrelated-control')
    if kinds != LAUNCH_KINDS: blockers.add('panel-launch-coverage')
    for code in value['unresolved']:
        text(code); blockers.add(code)
    return Admission(tuple(sorted(blockers)), ())


def qualify_panel_runtime(ctx, runtime):
    raise ContainmentError('panel-native-runtime-unqualified')


def establish_start(ctx):
    raise ContainmentError('panel-native-runtime-unqualified')


def read_start(ctx):
    raise ContainmentError('panel-native-runtime-unqualified')


def release_start(ctx, retirement):
    raise ContainmentError('panel-native-runtime-unqualified')
