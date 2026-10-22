import copy
import importlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from boundary_fixtures import build_boundary_fixture, P
from source_schema import canonical, MAX_PART

class BoundarySchemaTest(unittest.TestCase):
    def setUp(self):
        try:self.s=importlib.import_module('boundary_schema')
        except ModuleNotFoundError:self.fail('boundary_schema functionality missing')
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.fixture=build_boundary_fixture(Path(self.temp.name));self.plan=self.fixture['documents']['plan']
        self.witness=self.fixture['documents']['witness-0']

    def reject(self,code,value,purpose):
        with self.assertRaises(self.s.BoundaryError) as caught:self.s.parse_boundary_document(value if isinstance(value,bytes) else canonical(value),purpose)
        self.assertEqual(caught.exception.code,code)

    def test_closed_plan_and_witness(self):
        for doc in self.fixture['documents'].values():self.assertEqual(doc,self.s.parse_boundary_document(canonical(doc),doc['purpose']))
        self.assertEqual(self.plan,self.s.validate_boundary_plan(self.plan))
        self.assertEqual(self.witness,self.s.validate_boundary_witness(self.witness))

    def test_decoded_duplicate_unknown_and_float_rejected(self):
        self.reject('duplicate-key',b'{"purpose":1,"purpo\\u0073e":2}',P+'plan')
        for v in [1.25,float('nan'),float('inf')]:
            raw=json.dumps(self.plan|{'extra':v}).encode();self.reject('number-form',raw,P+'plan')
        for original,slot in [(self.plan,None),(self.plan,'source_identity'),(self.witness,'interval'),(self.witness,'observer_identity')]:
            d=copy.deepcopy(original);dest=d if slot is None else d[slot];dest['shell']='private-command';self.reject('unknown-field',d,d['purpose'])
        d=copy.deepcopy(self.plan);d['controls'][0]['members'][0]['sql']='private-sql';self.reject('unknown-field',d,P+'plan')
        d=copy.deepcopy(self.plan);d.pop('baseline_ref');self.reject('unknown-field',d,P+'plan')

    def test_identity_interval_and_generation_types(self):
        for field,value in [('pid',True),('start_ticks','01'),('exe_sha256','bad'),('boot_id','')]:
            d=copy.deepcopy(self.witness);d['observer_identity'][field]=value;self.reject('identity-form',d,P+'witness')
        for value in [True,0,-1]:self.reject('identity-form',self.witness|{'control_generation':value},P+'witness')
        for key,value in [('start_ns','01'),('end_ns','-1'),('end_ns',str(int(self.witness['interval']['start_ns'])-1))]:
            d=copy.deepcopy(self.witness);d['interval'][key]=value;self.reject('interval-form',d,P+'witness')

    def test_dependency_cycle_and_bounds(self):
        d=copy.deepcopy(self.plan);d['controls'][0]['dependencies']=[d['controls'][0]['control_id']];self.reject('dependency-cycle',d,P+'plan')
        d=copy.deepcopy(self.plan);a,b=d['controls'][:2];a['dependencies']=[b['control_id']];b['dependencies']=[a['control_id']];self.reject('dependency-cycle',d,P+'plan')
        raw=canonical(self.plan);self.assertEqual(self.plan,self.s.parse_boundary_document(raw+b' '*(MAX_PART-len(raw)),P+'plan'))
        self.reject('document-size',raw+b' '*(MAX_PART-len(raw)+1),P+'plan')

    def test_support_slot_kinds_and_report_flags(self):
        self.reject('document-purpose',self.plan,P+'unregistered')
        e=copy.deepcopy(self.fixture['documents']['pre-0']);e['kind']='execute';self.reject('document-purpose',e,P+'fixture-evidence')
        report={'schema_version':1,'purpose':P+'report','plan_sha256':'a'*64,'scope_sha256':'b'*64,'witness_sha256s':[],
            'structurally_valid':True,'coverage_complete':False,'fictional_witnesses_complete':False,'plan_accepted':False,
            'blockers':[],'enforcement_complete':False,'real_execution_ready':False}
        self.s.validate_boundary_report(report)
        for field in ['plan_accepted','enforcement_complete','real_execution_ready']:self.reject('readiness-forbidden',report|{field:True},P+'report')

    def test_member_manifest_is_role_and_content_bound(self):
        members=self.plan['controls'][0]['members']+self.plan['controls'][1]['members']
        h=self.s.member_manifest_sha256(members);self.assertEqual(h,self.s.member_manifest_sha256(list(reversed(members))))
        changed=copy.deepcopy(members);changed[0]['role']='contained-unknown';self.assertNotEqual(h,self.s.member_manifest_sha256(changed))

    def test_malformed_enum_shapes_have_sanitized_schema_errors(self):
        cases=[]
        d=copy.deepcopy(self.plan);d['controls'][0]['requirement']=[];cases.append(d)
        d=copy.deepcopy(self.plan);d['controls'][0]['members'][0]['role']=[];cases.append(d)
        cases.append(self.witness|{'state':{}})
        d=copy.deepcopy(self.fixture['documents']['pre-0']);d['records'][0]['outcome']=[];cases.append(d)
        for d in cases:
            with self.subTest(purpose=d['purpose']):
                try:self.s.parse_boundary_document(canonical(d),d['purpose'])
                except Exception as exc:
                    self.assertIsInstance(exc,self.s.BoundaryError);self.assertEqual(exc.code,'document-purpose')
                else:self.fail('Malformed enum accepted')

if __name__=='__main__':unittest.main()
