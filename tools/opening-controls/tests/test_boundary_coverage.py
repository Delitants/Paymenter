from copy import deepcopy
import importlib
import os
from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from boundary_fixtures import build_boundary_fixture, save_fixture_document, evidence, record, OBSERVER, NOW

class BoundaryCoverageTest(unittest.TestCase):
    def setUp(self):
        try:self.c=importlib.import_module('boundary_coverage')
        except ModuleNotFoundError:self.fail('boundary_coverage functionality missing')
        from boundary_refs import ReferenceSet
        self.r=ReferenceSet;self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name)
        self.fixture=build_boundary_fixture(self.root);self.observers={OBSERVER['pid']:OBSERVER};self.boot=OBSERVER['boot_id'];self.now=NOW
    def report(self,plan=None,scope=None,witnesses=None):
        f=self.fixture
        if scope is not None:
            ref=save_fixture_document(self.root,'scope-variant',scope)
            plan=deepcopy(plan or f['documents']['plan']);plan['observation_refs']=[ref]
        plan_ref=f['plan_ref'] if plan is None else save_fixture_document(self.root,'plan',plan)
        with self.r(self.root) as rs:
            b=rs.load_plan(plan_ref, f['witness_refs'] if witnesses is None else witnesses)
            return self.c.evaluate_boundary(b,self.observers,self.boot,self.now)
    def codes(self,r):return {b['code'] for b in r['blockers']}
    def assertBlocked(self,code,**changes):
        r=self.report(**changes);self.assertIn(code,self.codes(r));self.assertFalse(r['real_execution_ready']);return r
    def test_exact_once_coverage(self):
        self.assertTrue(self.report()['coverage_complete'])
        for code,edit in [('object-missing',lambda p:p['controls'][0]['members'].clear()),
            ('object-duplicate',lambda p:p['controls'][1]['members'].append(deepcopy(p['controls'][0]['members'][0]))),
            ('object-added',lambda p:p['controls'][0]['members'][0].update(object_id='added')),
            ('object-hash-changed',lambda p:p['controls'][0]['members'][0].update(object_sha256='f'*64))]:
            p=deepcopy(self.fixture['documents']['plan']);edit(p);r=self.assertBlocked(code,plan=p,witnesses=[]);self.assertFalse(r['coverage_complete'])
        scope=deepcopy(self.fixture['documents']['scope']);scope['unrelated'][0]['evidence_refs']=[];self.assertBlocked('unrelated-unproved',scope=scope,witnesses=[])
    def test_unknown_containment_requires_complete_scope(self):
        p=deepcopy(self.fixture['documents']['plan']);p['controls'][0]['members'][0]['role']='contained-unknown';self.assertBlocked('containment-unproved',plan=p,witnesses=[])
        for code in ['capture-invalid','capture-incomplete','mixed-generation','inventory-unsupported']:
            s=deepcopy(self.fixture['documents']['scope']);s['errors']=[{'object_id':None,'reason_code':code}];self.assertBlocked('capture-unacceptable',scope=s,witnesses=[])
        m=p['controls'][0]['members'][0];ref=save_fixture_document(self.root,'containment',evidence('containment',[record(m['object_id'],m['object_sha256'],'unknown','shared-scope-unapproved')]))
        m['evidence_refs'].append(ref);self.assertBlocked('shared-scope-unapproved',plan=p,witnesses=[])
        p=deepcopy(self.fixture['documents']['plan']);p['unresolved']=[{'object_id':m['object_id'],'reason_code':'dynamic-include','evidence_refs':[]}];self.assertBlocked('unresolved-member',plan=p,witnesses=[])
    def test_complete_fixture_never_accepts_production(self):
        r=self.report();self.assertTrue(r['coverage_complete'])
        for k in ['plan_accepted','enforcement_complete','real_execution_ready']:self.assertFalse(r[k])
        self.assertIn('fixture-adapter-not-production',self.codes(r));self.assertIn('native-signing-unavailable',self.codes(r))
    def test_adapter_and_acceptance_inputs_cannot_escalate(self):
        p=deepcopy(self.fixture['documents']['plan']);p['controls'][0]['adapter_id']='production-adapter';self.assertBlocked('adapter-unavailable',plan=p,witnesses=[])
        p=deepcopy(self.fixture['documents']['plan']);p['controls'][0]['adapter_manifest_ref']=save_fixture_document(self.root,'manifest-new',evidence('adapter-manifest',[record(reason_code='another-adapter')]))
        self.assertBlocked('adapter-manifest-mismatch',plan=p,witnesses=[])
        p=deepcopy(self.fixture['documents']['plan']);p['controls'].pop();self.assertBlocked('requirement-missing',plan=p,witnesses=[])
        p=deepcopy(self.fixture['documents']['plan']);p['acceptance_ref']=save_fixture_document(self.root,'accepted',evidence('acceptance',[record(reason_code='approved')]))
        r=self.report(plan=p,witnesses=[]);self.assertFalse(r['plan_accepted'])
    def test_identity_dependencies_and_adverse_preconditions(self):
        p=deepcopy(self.fixture['documents']['plan']);p['source_identity']['boot_id']='changed';self.assertBlocked('scope-identity',plan=p,witnesses=[])
        p=deepcopy(self.fixture['documents']['plan']);p['target_identity']['host_identity_sha256']='e'*64;self.assertBlocked('scope-identity',plan=p,witnesses=[])
        p=deepcopy(self.fixture['documents']['plan']);p['controls'][0]['dependencies']=['missing'];self.assertBlocked('dependency-missing',plan=p,witnesses=[])

    def witness_refs(self,edit):
        w=deepcopy(self.fixture['documents']['witness-0']);edit(w)
        return [save_fixture_document(self.root,'witness-variant',w),*self.fixture['witness_refs'][1:]]
    def test_witness_plan_members_manifest_session_and_generation(self):
        self.assertTrue(self.report()['fictional_witnesses_complete'])
        for key,value in [('plan_sha256','f'*64),('member_manifest_sha256','f'*64),('adapter_manifest_sha256','f'*64),
                          ('source_boot_id','changed'),('session_id','changed'),('freeze_id','changed'),('control_generation',2)]:
            refs=self.witness_refs(lambda w:w.update({key:value}));self.assertBlocked('witness-binding',witnesses=refs)
        self.assertBlocked('witness-duplicate',witnesses=self.fixture['witness_refs']+[self.fixture['witness_refs'][0]])
        self.assertBlocked('witness-missing',witnesses=self.fixture['witness_refs'][1:])
    def test_observer_boot_process_and_monotonic_freshness(self):
        self.now=NOW+60*10**9;self.assertTrue(self.report()['fictional_witnesses_complete'])
        self.now+=1;self.assertBlocked('witness-stale')
        self.now=NOW-1;self.assertBlocked('witness-time');self.now=NOW
        self.boot='changed';self.assertBlocked('witness-observer');self.boot=OBSERVER['boot_id']
        for key,value in [('boot_id','changed'),('pid',4444),('start_ticks','999'),('exe_sha256','f'*64)]:
            self.observers={OBSERVER['pid']:OBSERVER|{key:value}};self.assertBlocked('witness-observer')
        self.observers={};self.assertBlocked('witness-observer')
    def test_held_requires_actual_fixture_attempts(self):
        for key,value in [('gate_readback_ref',None),('attempt_result_refs',[]),('unrelated_result_refs',[])]:
            refs=self.witness_refs(lambda w:w.update({key:value}));self.assertBlocked('witness-evidence-missing',witnesses=refs)
        for reason in ['timeout','transport-success','idle-absence']:
            d=deepcopy(self.fixture['documents']['attempt-0']);d['records'][0]['reason_code']=reason
            ref=save_fixture_document(self.root,'attempt-variant',d)
            refs=self.witness_refs(lambda w:w.update(attempt_result_refs=[ref]));self.assertBlocked('witness-attempt-unproved',witnesses=refs)
        for state in ['unproved','lost']:
            refs=self.witness_refs(lambda w:w.update(state=state));self.assertBlocked('witness-state',witnesses=refs)
    def test_adverse_physical_claim_matrix_remains_blocked(self):
        from boundary_schema import CONTEXT_KEYS
        reasons=['escaped-child','restart-outside-domain','existing-request','direct-socket','alternate-ingress','internal-error-ingress',
            'privileged-writer','ddl-writer','keeper-death','keeper-transaction','schema-added','custody-disk-full','callback-unauthenticated',
            'callback-conflict','verifier-lost','controller-killed','source-reboot','partial-establishment','stale-generation',
            'unsafe-operator-release','target-changed','cohort-changed']
        for reason in reasons:
            with self.subTest(reason=reason):
                w=self.fixture['documents']['witness-0'];ctx={k:w[k] for k in CONTEXT_KEYS.split()}
                ref=save_fixture_document(self.root,'failure-variant',evidence('failure',[record(outcome='blocked',reason_code=reason)],ctx))
                refs=self.witness_refs(lambda w:w.update(failure_refs=[ref]));r=self.assertBlocked('witness-failure',witnesses=refs)
                self.assertFalse(r['fictional_witnesses_complete'])
                self.assertTrue(any(b['code']=='witness-failure' and b['evidence_sha256']==ref['sha256'] for b in r['blockers']))

    def test_conflicting_scope_records_are_never_hidden_by_a_pass(self):
        p=deepcopy(self.fixture['documents']['plan']);c=p['controls'][0];member=c['members'][0]
        d=deepcopy(self.fixture['documents']['pre-0']);d['records'].append(record(member['object_id'],member['object_sha256'],'blocked','privileged-writer'))
        c['precondition_refs']=[save_fixture_document(self.root,'adverse-pre',d)]
        with self.subTest(case='precondition'):
            r=self.assertBlocked('scope-evidence-unproved',plan=p,witnesses=[]);self.assertFalse(r['coverage_complete'])
        p=deepcopy(self.fixture['documents']['plan']);s=deepcopy(self.fixture['documents']['scope']);obj=s['unrelated'][0]
        d=deepcopy(self.fixture['documents']['unrelated']);d['records'].append(record(obj['object_id'],obj['object_sha256'],'unknown','shared-scope-unapproved'))
        ref=save_fixture_document(self.root,'adverse-unrelated',d)
        for c in p['controls']:c['unrelated_scope_refs']=[ref]
        s['unrelated'][0]['evidence_refs']=[ref]
        with self.subTest(case='unrelated'):
            r=self.assertBlocked('shared-scope-unapproved',plan=p,scope=s,witnesses=[]);self.assertFalse(r['coverage_complete'])
        nested=save_fixture_document(self.root,'nested-adverse',evidence('precondition',[record(member['object_id'],member['object_sha256'],'unknown','privileged-writer')]))
        p=deepcopy(self.fixture['documents']['plan']);d=deepcopy(self.fixture['documents']['pre-0']);d['refs']=[nested]
        p['controls'][0]['precondition_refs']=[save_fixture_document(self.root,'nested-parent',d)]
        with self.subTest(case='nested'):
            self.assertBlocked('scope-evidence-unproved',plan=p,witnesses=[])
    def test_gate_readback_requires_gate_semantics_and_exact_member_facts(self):
        cases=[[record('not-a-member','f'*64,'pass','timeout')],[],[record(reason_code='transport-success')],
            [record(self.fixture['documents']['plan']['controls'][0]['members'][0]['object_id'],'f'*64,'pass','fixture-gate-held')]]
        for rows in cases:
            with self.subTest(records=rows):
                d=deepcopy(self.fixture['documents']['gate-0']);d['records']=rows
                ref=save_fixture_document(self.root,'gate-variant',d)
                refs=self.witness_refs(lambda w:w.update(gate_readback_ref=ref));r=self.assertBlocked('witness-gate-unproved',witnesses=refs)
                self.assertFalse(r['fictional_witnesses_complete'])
        # Permitted control-wide fixture gate fact remains valid, but never production enforcement.
        self.assertTrue(self.report()['fictional_witnesses_complete'])

if __name__=='__main__':unittest.main()
