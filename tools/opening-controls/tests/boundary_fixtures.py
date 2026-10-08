"""Fictional contracts; only caller-owned temporary files are written."""
from copy import deepcopy
import hashlib
from pathlib import Path
from source_schema import CONTROL_CODES, canonical, digest

P = 'source-control-boundary-'
OBSERVER = {'boot_id':'fixture-observer-boot','pid':4242,'start_ticks':'123','exe_sha256':'a'*64}
NOW = 10**12

def save_fixture_document(root: Path, name: str, value: dict) -> dict:
    assert name.replace('-', '').replace('_', '').isalnum()
    p = root / (name + '.json')
    raw = canonical(value)
    p.write_bytes(raw); p.chmod(0o600)
    return {'path':str(p), 'sha256':hashlib.sha256(raw).hexdigest()}

def evidence(kind, records=None, context=None, refs=None, errors=None):
    return {'schema_version':1,'purpose':P+'fixture-evidence','fixture_id':'fixture-evidence',
            'kind':kind,'context':context,'records':records or [],'refs':refs or [],'errors':errors or []}

def record(object_id=None, object_sha256=None, outcome='pass', reason_code=None):
    return dict(object_id=object_id, object_sha256=object_sha256, outcome=outcome, reason_code=reason_code)

def build_boundary_fixture(root: Path, observer=None, now_ns=None) -> dict:
    root = Path(root); root.chmod(0o700)
    observer = deepcopy(observer or OBSERVER); now_ns = NOW if now_ns is None else now_ns
    docs = {}; refs = {}
    def save(name, value):
        docs[name]=deepcopy(value); refs[name]=save_fixture_document(root,name,value); return refs[name]
    source={'host_identity_sha256':'b'*64,'boot_id':'fixture-source-boot'}
    target={'host_identity_sha256':'c'*64,'boot_id':'fixture-target-boot'}
    extra=record('fixture-unrelated','d'*64)
    extra_ref=save('unrelated',evidence('unrelated-scope',[extra]))
    cohort=save('cohort',evidence('cohort',[record(reason_code='fixture-cohort')]))
    baseline=save('baseline',evidence('baseline',[record(reason_code='fixture-baseline')]))
    controls=[]; objects=[]
    for i,code in enumerate(sorted(CONTROL_CODES)):
        oid='fixture-object-'+str(i); h=digest({'fixture_object':i}); cid='fixture-control-'+str(i)
        obj=record(oid,h)
        manifest=save('manifest-'+str(i),evidence('adapter-manifest',[record(reason_code='fixture-boundary-v1')]))
        pre=save('pre-'+str(i),evidence('precondition',[obj]))
        block=save('block-'+str(i),evidence('blocking-check',[obj]))
        rollback=save('rollback-'+str(i),evidence('rollback',[obj]))
        origin=save('origin-'+str(i),evidence('observation',[obj]))
        objects.append({'object_id':oid,'object_sha256':h,'evidence_refs':[origin]})
        controls.append({'control_id':cid,'requirement':code,'adapter_id':'fixture-boundary-v1',
            'adapter_manifest_ref':manifest,'members':[{'object_id':oid,'object_sha256':h,'role':'selected','evidence_refs':[pre]}],
            'unrelated_scope_refs':[extra_ref],'precondition_refs':[pre], 'blocking_check_refs':[block],
            'rollback_ref':rollback,'dependencies':[]})
    objects.append({'object_id':extra['object_id'],'object_sha256':extra['object_sha256'],'evidence_refs':[extra_ref]})
    scope=save('scope',{'schema_version':1,'purpose':P+'scope','fixture_id':'fixture-scope','source_identity':source,
        'target_identity':target,'objects':objects,'unrelated':[objects[-1]],'errors':[]})
    plan={'schema_version':1,'purpose':P+'plan','plan_id':'fixture-plan','source_identity':source,'target_identity':target,
        'observation_refs':[scope],'cohort_ref':cohort,'baseline_ref':baseline,'controls':controls,'unresolved':[],'acceptance_ref':None}
    plan_ref=save('plan',plan); witnesses=[]
    for i,c in enumerate(controls):
        member=c['members'][0]; interval={'observer_boot_id':observer['boot_id'],'start_ns':str(now_ns-100),'end_ns':str(now_ns)}
        context={'plan_sha256':plan_ref['sha256'],'control_id':c['control_id'],'control_generation':1,'session_id':'fixture-session',
            'freeze_id':'fixture-freeze','source_boot_id':source['boot_id'],'observer_identity':observer,'interval':interval,
            'adapter_manifest_sha256':c['adapter_manifest_ref']['sha256'],
            'member_manifest_sha256':digest([{k:member[k] for k in ('object_id','object_sha256','role')}])}
        gate=save('gate-'+str(i),evidence('gate-readback',[record(reason_code='fixture-gate-held')],context))
        attempt=save('attempt-'+str(i),evidence('attempt-result',[record(member['object_id'],member['object_sha256'],'blocked','fixture-writer-denied')],context))
        unrelated=save('preserved-'+str(i),evidence('unrelated-result',[extra],context))
        witness={'schema_version':1,'purpose':P+'witness','witness_id':'fixture-witness-'+str(i),**context,
            'state':'held','gate_readback_ref':gate,'attempt_result_refs':[attempt],'unrelated_result_refs':[unrelated],'failure_refs':[]}
        witnesses.append(save('witness-'+str(i),witness))
    return {'plan_ref':plan_ref,'scope_ref':scope,'witness_refs':witnesses,'documents':docs,'refs':refs}
