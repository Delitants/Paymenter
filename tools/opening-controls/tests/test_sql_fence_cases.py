from pathlib import Path
import sys
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from sql_fence_schema import SqlFenceError,parse_plan,canonical,CASE_IDS
from sql_fence_fixtures import plan_record
from sql_fence_cases import CHECKS,run_case,validate_case
from sql_fence_harness import main,validate_outcome

class SqlCaseTests(unittest.TestCase):
    def test_exact_ten_cases_required_no_subset(self):
        self.assertEqual(len(CHECKS),10);self.assertEqual(CHECKS,frozenset(CASE_IDS))
        for cases in (list(CASE_IDS)[:-1],[CASE_IDS[0]]*10):
            p=plan_record();p['cases']=cases
            with self.assertRaises(SqlFenceError):parse_plan(canonical(p))
    def test_unknown_case_or_runtime_input_fails_before_sql(self):
        with patch('sql_fence_harness._launch',side_effect=AssertionError('must not launch')):
            self.assertEqual(main(['--socket','/fixture/external.sock']),1)
        with self.assertRaises(SqlFenceError):run_case('unknown',object(),object())
    def test_case_requires_its_own_expected_native_effect(self):
        with self.assertRaises(SqlFenceError):validate_case('protected_dml_ddl_denied',{'demo_counter':2})
        with self.assertRaises(SqlFenceError):validate_case('protected_dml_ddl_denied',{'denials':0,'protected_unchanged':True})
        validate_case('protected_dml_ddl_denied',{'denials':10,'protected_unchanged':True})
    def test_false_readiness_in_every_report(self):
        result={'case_id':'protected_dml_ddl_denied','passed':True,'effects':{'denials':10,'protected_unchanged':True},'real_execution_ready':True}
        with self.assertRaises(SqlFenceError):validate_outcome({'checks':{k:result for k in CASE_IDS},'retirement':{'complete':True},'error':None,'real_execution_ready':False})
    def test_partial_report_or_unacknowledged_storage_is_not_success(self):
        for r in ({'checks':{},'retirement':{'complete':True}}, {'checks':{},'retirement':{'complete':False}}):
            with self.assertRaises(SqlFenceError):validate_outcome(r)

    def test_complete_flag_cannot_replace_physical_retirement_checks(self):
        effects=[{'demo_counter':2},{'denials':10,'protected_unchanged':True},
          {'counters':[1,1,1],'myisam_preserved':True},{'cached_authorized':True,'fresh_denied':True,'drained':True},
          {'host_identity':'fx_writer@127.0.0.1','pattern_denials':2,'unsafe_rejections':3},
          {'escalation_denials':5,'definer_rejected':True,'admin_trusted':True},
          {'keeper_losses':1,'controller_losses':1,'continuity_denied':True},{'restarts':2,'unchanged':True},
          {'operation_trials':28,'boundaries':['before_intent','after_intent','before_readback','after_readback'],'competing_denied':True},
          {'raw_exact':True,'protected_unchanged':True,'unrelated_preserved':True,'repeat_exact':True}]
        checks={k:{'case_id':k,'passed':True,'effects':e,'real_execution_ready':False} for k,e in zip(CASE_IDS,effects)}
        with self.assertRaises(SqlFenceError):validate_outcome({'checks':checks,'error':None,'real_execution_ready':False,'retirement':{'complete':True,'phase_manifest':{'sha256':'a'*64}}})

    def test_controller_requests_supervised_operation_before_interruption(self):
        import io,json,tempfile
        from sql_fence_cases import _controller_checkpoint
        from sql_fence_harness import OperationJournal
        from sql_fence_projection import compile_projection
        from sql_fence_fixtures import model,preserved,requirements
        from sql_fence_driver import SqlSnapshot
        class Process:
            pid=42;returncode=-9
            def __init__(self,fresh=False):
                self.returncode=0 if fresh else -9
                self.stdin=io.BytesIO();self.stdout=io.BytesIO(b'{"phase":"request"}\n{ "count":1,"tail":"intent"}\n')
            def communicate(self,timeout):return b'{"count":1,"tail":"intent"}',b''
        with tempfile.TemporaryDirectory() as td:
            root=Path(td).resolve();root.chmod(0o700)
            with OperationJournal(root,'g') as j:
                spawned=[]
                class Supervisor:
                    def spawn(self,*a):
                        spawned.append(1);return Process(fresh=len(spawned)>1)
                    def stop_owned(self,*a,**kw):
                        if len(j.verify_prefix())!=1:raise AssertionError('interrupted before supervised operation')
                    def evidence(self,*a):pass
                p=compile_projection(model(),'fixture_source',preserved(),requirements())
                action=lambda:j.intent(p.establish[0],SqlSnapshot(model(),(),'a'*64,'b'*64,'c'*64))
                with patch('sql_fence_cases.select.select',side_effect=lambda r,w,e,t:(r,[],[])),patch('pathlib.Path.read_text',return_value='42 (controller) T'):
                    _controller_checkpoint(Supervisor(),j,action=action)
                self.assertEqual(len(j.verify_prefix()),1)
