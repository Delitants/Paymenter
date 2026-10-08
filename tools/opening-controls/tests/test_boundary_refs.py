import hashlib
import importlib
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from boundary_fixtures import build_boundary_fixture, save_fixture_document, evidence, record, P
from source_schema import canonical, MAX_PART, MAX_CAPTURE, MAX_OBJECTS

class BoundaryRefsTest(unittest.TestCase):
    def setUp(self):
        try:self.r=importlib.import_module('boundary_refs')
        except ModuleNotFoundError:self.fail('boundary_refs functionality missing')
        self.assertEqual(os.geteuid(),0,'native root Linux acceptance required')
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name)
        self.fixture=build_boundary_fixture(self.root)
    def load(self):
        with self.r.ReferenceSet(self.root) as rs:return rs.load_plan(self.fixture['plan_ref'],self.fixture['witness_refs'])
    def reject(self,code,call):
        with self.assertRaises(self.r.BoundaryError) as caught:call()
        self.assertEqual(caught.exception.code,code)
    def test_original_bytes_not_canonical_hash(self):
        p=Path(self.fixture['plan_ref']['path']);raw=p.read_bytes()+b' ';p.write_bytes(raw)
        self.reject('reference-hash',self.load)
        self.fixture['plan_ref']['sha256']=hashlib.sha256(raw).hexdigest();bundle=self.load()
        self.assertEqual(bundle.plan.sha256,self.fixture['plan_ref']['sha256'])
    def test_root_owner_modes_and_link_rejection(self):
        p=Path(self.fixture['refs']['cohort']['path'])
        for mode in [0o644,0o400]:
            p.chmod(mode);self.reject('private-reference',self.load);p.chmod(0o600)
        os.chown(p,12345,12345);self.reject('private-reference',self.load);os.chown(p,0,0)
        q=self.root/'link';os.link(p,q);self.reject('private-reference',self.load);q.unlink()
        raw=p.read_bytes();p.unlink();p.symlink_to(self.root/'baseline.json');self.reject('private-reference',self.load);p.unlink();p.write_bytes(raw);p.chmod(0o600)
        self.root.chmod(0o755);self.reject('private-reference',self.load);self.root.chmod(0o700)
    def test_stream_escape_and_same_byte_substitution(self):
        original=self.fixture['plan_ref'].copy()
        self.fixture['plan_ref']['path']='php://private';self.reject('reference-path',self.load)
        self.fixture['plan_ref']['path']='/etc/passwd';self.reject('reference-path',self.load);self.fixture['plan_ref']=original
        with self.r.ReferenceSet(self.root) as rs:
            rs.load_plan(original,[]);p=Path(self.fixture['refs']['cohort']['path']);raw=p.read_bytes()
            q=self.root/'replacement';q.write_bytes(raw);q.chmod(0o600);q.replace(p)
            self.reject('reference-substitution',rs.verify_unchanged)
    def test_ancestor_and_open_read_races(self):
        original=self.fixture['plan_ref']; real_open=os.open;switched=[];p=Path(original['path'])
        def race(path,flags,*args,**kwargs):
            if path==p.name and flags & os.O_NOFOLLOW and not switched:
                q=self.root/'new-plan';q.write_bytes(p.read_bytes());q.chmod(0o600);q.replace(p);switched.append(True)
            return real_open(path,flags,*args,**kwargs)
        with patch('os.open',side_effect=race):self.reject('reference-substitution',self.load)
        self.assertTrue(switched)
        with self.r.ReferenceSet(self.root) as rs:
            rs.load_plan(original,[]);moved=self.root.with_name(self.root.name+'-moved');self.root.rename(moved);self.root.mkdir(mode=0o700)
            try:self.reject('reference-substitution',rs.verify_unchanged)
            finally:self.root.rmdir();moved.rename(self.root)
    def test_cycle_depth_and_combined_budgets(self):
        # Traverse through legal typed fixture refs; depth is measured from plan = one.
        leaf=save_fixture_document(self.root,'depth-0',evidence('cohort',[record()]))
        for i in range(1,7):leaf=save_fixture_document(self.root,'depth-'+str(i),evidence('cohort',[record()],refs=[leaf]))
        plan=self.fixture['documents']['plan'].copy();plan['cohort_ref']=leaf
        self.fixture['plan_ref']=save_fixture_document(self.root,'plan',plan);self.load() # plan + seven evidence levels = eight
        leaf=save_fixture_document(self.root,'depth-7',evidence('cohort',[record()],refs=[leaf]));plan['cohort_ref']=leaf
        self.fixture['plan_ref']=save_fixture_document(self.root,'plan',plan);self.reject('reference-depth',self.load)
        # Hash-consistent cycles are cryptographically circular; detect repeated active path BEFORE hashing a changed child.
        p=self.root/'cycle.json';ref={'path':str(p),'sha256':'0'*64};v=evidence('cohort',[record()],refs=[ref]);ref=save_fixture_document(self.root,'cycle',v)
        v['refs']=[ref];save_fixture_document(self.root,'cycle',v);plan['cohort_ref']=save_fixture_document(self.root,'cycle',v)
        self.fixture['plan_ref']=save_fixture_document(self.root,'plan',plan);self.reject('reference-cycle',self.load)
        self.fixture=build_boundary_fixture(self.root)
        with patch.object(self.r,'MAX_CAPTURE',1):self.reject('reference-budget',self.load)
        with patch.object(self.r,'MAX_OBJECTS',1):self.reject('reference-budget',self.load)
        self.assertEqual(self.r.MAX_CAPTURE,32*1024**2);self.assertEqual(self.r.MAX_OBJECTS,10000)
    def test_slot_kind_mismatch(self):
        d=self.fixture['documents']['plan'].copy();d['cohort_ref']=self.fixture['refs']['baseline'];self.fixture['plan_ref']=save_fixture_document(self.root,'plan',d)
        self.reject('reference-kind',self.load)

    def test_cached_subtree_cannot_bypass_reference_depth(self):
        leaf=save_fixture_document(self.root,'shared-0',evidence('observation',[record()]))
        for i in range(1,7):leaf=save_fixture_document(self.root,'shared-'+str(i),evidence('observation',[record()],refs=[leaf]))
        wrapper=save_fixture_document(self.root,'shared-wrapper',evidence('observation',[record()],refs=[leaf]))
        plan=self.fixture['documents']['plan'].copy();plan['observation_refs']=[self.fixture['scope_ref'],leaf,wrapper]
        self.fixture['plan_ref']=save_fixture_document(self.root,'plan',plan)
        self.reject('reference-depth',self.load)

    def test_actual_combined_entry_boundary_and_supplied_witness_list(self):
        with self.r.ReferenceSet(self.root) as rs:
            rs.load_plan(self.fixture['plan_ref'],[]);base=rs.entries
        plan=self.fixture['documents']['plan'].copy()
        for excess in [0,1]:
            value=evidence('cohort',[record()]*(MAX_OBJECTS-base+1+excess))
            plan['cohort_ref']=save_fixture_document(self.root,'cohort',value)
            self.fixture['plan_ref']=save_fixture_document(self.root,'plan',plan)
            if excess:self.reject('reference-budget',lambda: self.r_load_without_witnesses())
            else:
                with self.r.ReferenceSet(self.root) as rs:
                    rs.load_plan(self.fixture['plan_ref'],[]);self.assertEqual(rs.entries,MAX_OBJECTS)
        self.fixture=build_boundary_fixture(self.root)
        with self.r.ReferenceSet(self.root) as rs:
            self.reject('reference-budget',lambda:rs.load_plan(self.fixture['plan_ref'],[self.fixture['witness_refs'][0]]*(MAX_OBJECTS+1)))
    def r_load_without_witnesses(self):
        with self.r.ReferenceSet(self.root) as rs:return rs.load_plan(self.fixture['plan_ref'],[])
    def test_actual_combined_original_byte_boundary(self):
        plan=self.fixture['documents']['plan'].copy();children=[]
        for i in range(8):
            value=evidence('cohort',[record()]);value['fixture_id']='padding-'+str(i)
            child=save_fixture_document(self.root,'padding-'+str(i),value);p=Path(child['path']);raw=p.read_bytes()
            if i<7:raw+=b' '*(MAX_PART-len(raw));p.write_bytes(raw);child['sha256']=hashlib.sha256(raw).hexdigest()
            children.append(child)
        def update():
            plan['cohort_ref']=save_fixture_document(self.root,'cohort-root',evidence('cohort',[record()],refs=children))
            self.fixture['plan_ref']=save_fixture_document(self.root,'plan',plan)
        update()
        with self.r.ReferenceSet(self.root) as rs:rs.load_plan(self.fixture['plan_ref'],[]);base=rs.bytes
        leaf=Path(children[-1]['path']);raw=leaf.read_bytes()+b' '*(MAX_CAPTURE-base)
        self.assertLessEqual(len(raw),MAX_PART);leaf.write_bytes(raw);children[-1]['sha256']=hashlib.sha256(raw).hexdigest();update()
        with self.r.ReferenceSet(self.root) as rs:rs.load_plan(self.fixture['plan_ref'],[]);self.assertEqual(rs.bytes,MAX_CAPTURE)
        raw+=b' ';leaf.write_bytes(raw);children[-1]['sha256']=hashlib.sha256(raw).hexdigest();update()
        self.reject('reference-budget',self.r_load_without_witnesses)

if __name__=='__main__':unittest.main()
