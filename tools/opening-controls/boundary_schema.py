"""Closed fictional boundary contracts. Never an enforcement or signing authority."""
from pathlib import PurePosixPath
import re
from custody import strict_json
from source_schema import canonical, digest, MAX_PART, MAX_CAPTURE, MAX_OBJECTS, MAX_AGE_NS, CONTROL_CODES

PREFIX = 'source-control-boundary-'
EVIDENCE_KINDS = frozenset('observation adapter-manifest cohort baseline precondition blocking-check rollback containment unrelated-scope acceptance gate-readback attempt-result unrelated-result failure'.split())
CONTEXT_KINDS = frozenset('gate-readback attempt-result unrelated-result failure'.split())
CONTEXT_KEYS = 'plan_sha256 control_id control_generation session_id freeze_id source_boot_id observer_identity interval adapter_manifest_sha256 member_manifest_sha256'

class BoundaryError(RuntimeError):
    def __init__(self, code):
        self.code=code
        super().__init__(code)

def require(ok, code='document-form'):
    if not ok:raise BoundaryError(code)

def keys(value, names):
    require(type(value) is dict and set(value)==set(names.split()), 'unknown-field')

def identifier(value, code='identity-form'):
    require(type(value) is str and re.fullmatch(r'[A-Za-z0-9_.-]{1,128}',value) is not None,code)

def text(value, code='identity-form'):
    require(type(value) is str and 0<len(value)<=256 and '\0' not in value,code)

def sha(value, code='identity-form'):
    require(type(value) is str and re.fullmatch('[a-f0-9]{64}',value) is not None,code)

def decimal(value, positive=False, code='identity-form'):
    require(type(value) is str and len(value)<=32 and re.fullmatch('0|[1-9][0-9]*',value) is not None,code)
    require(not positive or int(value)>0,code)

def rows(value):require(type(value) is list and len(value)<=MAX_OBJECTS,'reference-budget')

def ref(value):
    keys(value,'path sha256');sha(value['sha256'])
    p=value['path'];require(type(p) is str and len(p)<=4096 and '\0' not in p,'reference-path')
    q=PurePosixPath(p)
    require(q.is_absolute() and not p.startswith('//') and str(q)==p and '..' not in q.parts,'reference-path')

def refs(value):
    rows(value)
    for r in value:ref(r)

def identity(value):
    keys(value,'host_identity_sha256 boot_id');sha(value['host_identity_sha256']);text(value['boot_id'])

def observer(value):
    keys(value,'boot_id pid start_ticks exe_sha256');text(value['boot_id']);sha(value['exe_sha256'])
    require(type(value['pid']) is int and 0<value['pid']<2**31,'identity-form');decimal(value['start_ticks'],True)

def interval(value):
    keys(value,'observer_boot_id start_ns end_ns');text(value['observer_boot_id'],'interval-form')
    for k in ('start_ns','end_ns'):decimal(value[k],code='interval-form')
    require(int(value['start_ns'])<=int(value['end_ns']),'interval-form')

def context(value):
    keys(value,CONTEXT_KEYS)
    for k in ('plan_sha256','adapter_manifest_sha256','member_manifest_sha256'):sha(value[k])
    for k in ('control_id','session_id','freeze_id'):identifier(value[k])
    text(value['source_boot_id']);observer(value['observer_identity']);interval(value['interval'])
    require(type(value['control_generation']) is int and 0<value['control_generation']<2**63,'identity-form')

def error_rows(value):
    rows(value)
    for r in value:
        keys(r,'object_id reason_code');identifier(r['reason_code'])
        if r['object_id'] is not None:identifier(r['object_id'])

def object_row(value):
    keys(value,'object_id object_sha256 evidence_refs');identifier(value['object_id']);sha(value['object_sha256']);refs(value['evidence_refs'])

def base(value,purpose,names):
    keys(value,'schema_version purpose '+names)
    require(type(value['schema_version']) is int and value['schema_version']==1,'document-purpose')
    require(value['purpose']==PREFIX+purpose,'document-purpose')

def validate_boundary_plan(value):
    base(value,'plan','plan_id source_identity target_identity observation_refs cohort_ref baseline_ref controls unresolved acceptance_ref')
    identifier(value['plan_id']);identity(value['source_identity']);identity(value['target_identity']);refs(value['observation_refs'])
    ref(value['cohort_ref']);ref(value['baseline_ref'])
    if value['acceptance_ref'] is not None:ref(value['acceptance_ref'])
    rows(value['controls']);rows(value['unresolved']);deps={}
    for c in value['controls']:
        keys(c,'control_id requirement adapter_id adapter_manifest_ref members unrelated_scope_refs precondition_refs blocking_check_refs rollback_ref dependencies')
        identifier(c['control_id']);identifier(c['adapter_id']);require(type(c['requirement']) is str and c['requirement'] in CONTROL_CODES,'document-purpose')
        ref(c['adapter_manifest_ref']);ref(c['rollback_ref']);rows(c['members']);rows(c['dependencies'])
        for k in ('unrelated_scope_refs','precondition_refs','blocking_check_refs'):refs(c[k])
        for m in c['members']:
            keys(m,'object_id object_sha256 role evidence_refs');identifier(m['object_id']);sha(m['object_sha256']);refs(m['evidence_refs'])
            require(type(m['role']) is str and m['role'] in {'selected','contained-unknown'},'document-purpose')
        for dep in c['dependencies']:identifier(dep)
        require(c['control_id'] not in deps,'dependency-cycle');deps[c['control_id']]=c['dependencies']
    # Iterative DFS, avoiding recursion over attacker-supplied dependency depth.
    done=set()
    for cid in deps:
        stack=[(cid,False)];active=set()
        while stack:
            node,exit_node=stack.pop()
            if exit_node:active.remove(node);done.add(node);continue
            require(node not in active,'dependency-cycle')
            if node in done:continue
            if node not in deps:continue  # Semantic missing-dependency blocker, not a parser interpretation.
            active.add(node);stack.append((node,True));stack.extend((d,False) for d in deps[node])
    for u in value['unresolved']:
        keys(u,'object_id reason_code evidence_refs');identifier(u['object_id']);identifier(u['reason_code']);refs(u['evidence_refs'])
    return value

def validate_boundary_witness(value):
    base(value,'witness','witness_id '+CONTEXT_KEYS+' state gate_readback_ref attempt_result_refs unrelated_result_refs failure_refs')
    identifier(value['witness_id']);context({k:value[k] for k in CONTEXT_KEYS.split()})
    require(type(value['state']) is str and value['state'] in {'unproved','held','lost'},'document-purpose')
    if value['gate_readback_ref'] is not None:ref(value['gate_readback_ref'])
    for k in ('attempt_result_refs','unrelated_result_refs','failure_refs'):refs(value[k])
    return value

def validate_boundary_scope(value):
    base(value,'scope','fixture_id source_identity target_identity objects unrelated errors')
    identifier(value['fixture_id']);identity(value['source_identity']);identity(value['target_identity'])
    for k in ('objects','unrelated'):
        rows(value[k])
        for r in value[k]:object_row(r)
    error_rows(value['errors']);return value

def validate_fixture_evidence(value):
    base(value,'fixture-evidence','fixture_id kind context records refs errors');identifier(value['fixture_id'])
    require(type(value['kind']) is str and value['kind'] in EVIDENCE_KINDS,'document-purpose')
    if value['kind'] in CONTEXT_KINDS:context(value['context'])
    else:require(value['context'] is None,'document-form')
    rows(value['records']);refs(value['refs']);error_rows(value['errors'])
    for r in value['records']:
        keys(r,'object_id object_sha256 outcome reason_code')
        if r['object_id'] is not None:identifier(r['object_id'])
        if r['object_sha256'] is not None:sha(r['object_sha256'])
        require((r['object_id'] is None)==(r['object_sha256'] is None),'identity-form')
        require(type(r['outcome']) is str and r['outcome'] in {'pass','blocked','unknown'},'document-purpose')
        if r['reason_code'] is not None:identifier(r['reason_code'])
    return value

def validate_boundary_report(value):
    base(value,'report','plan_sha256 scope_sha256 witness_sha256s structurally_valid coverage_complete fictional_witnesses_complete plan_accepted blockers enforcement_complete real_execution_ready')
    sha(value['plan_sha256'])
    if value['scope_sha256'] is not None:sha(value['scope_sha256'])
    rows(value['witness_sha256s'])
    for h in value['witness_sha256s']:sha(h)
    for k in ('structurally_valid','coverage_complete','fictional_witnesses_complete','plan_accepted','enforcement_complete','real_execution_ready'):require(type(value[k]) is bool,'document-form')
    for k in ('plan_accepted','enforcement_complete','real_execution_ready'):require(value[k] is False,'readiness-forbidden')
    rows(value['blockers'])
    for b in value['blockers']:
        keys(b,'code control_id object_id evidence_sha256');identifier(b['code'])
        for k in ('control_id','object_id'):
            if b[k] is not None:identifier(b[k])
        if b['evidence_sha256'] is not None:sha(b['evidence_sha256'])
    return value

VALIDATORS={PREFIX+'plan':validate_boundary_plan,PREFIX+'witness':validate_boundary_witness,PREFIX+'scope':validate_boundary_scope,
            PREFIX+'fixture-evidence':validate_fixture_evidence,PREFIX+'report':validate_boundary_report}

def parse_boundary_document(raw, purpose):
    require(type(raw) is bytes and len(raw)<=MAX_PART,'document-size')
    require(purpose in VALIDATORS,'document-purpose')
    try:value=strict_json(raw)
    except (ValueError,UnicodeError,RecursionError) as exc:
        msg=str(exc)
        code='duplicate-key' if 'Duplicate JSON' in msg else 'number-form' if 'Nonfinite JSON' in msg else 'document-form'
        raise BoundaryError(code) from None
    stack=[value]
    while stack:
        node=stack.pop();require(type(node) is not float,'number-form')
        if type(node) is dict:stack.extend(node.values())
        elif type(node) is list:stack.extend(node)
    return VALIDATORS[purpose](value)

def member_manifest_sha256(members):
    return digest(sorted([{k:m[k] for k in ('object_id','object_sha256','role')} for m in members],key=lambda m:m['object_id']))
