import copy
import importlib
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from source_fixtures import capture_profile,raw_capture,FakeReadOps,FixtureClock
from source_schema import validate_raw_capture,validate_capture_profile,SourceError

class CollectTest(unittest.TestCase):
    def setUp(self):
        try:self.m=importlib.import_module('source_collect')
        except ModuleNotFoundError:self.fail('source_collect functionality missing')
    def run_capture(self,faults=None):
        ops=FakeReadOps(raw_capture(),faults);r=self.m.collect(capture_profile(),ops,FixtureClock());return r,ops
    def test_collector_runs_only_fixed_read_operations(self):
        r,ops=self.run_capture();validate_raw_capture(r);self.assertFalse(r['capture_errors'])
        self.assertEqual({c[1] for c in ops.calls if c[0]=='operation'},{'listeners','services','database'})
        self.assertEqual(len(r['files']),2)
    def test_changed_config_or_reused_pid_invalidates_capture(self):
        for fault in ['replace_pid_start','change_file']:
            r,_=self.run_capture({fault:True});self.assertIn('capture-changed',{e['code'] for e in r['capture_errors']})
    def test_disappearing_writer_is_not_silent_success(self):
        r,_=self.run_capture({'disappear':True});self.assertTrue(r['capture_errors'])
    def test_failed_or_oversize_operation_is_explicit(self):
        for faults in [{'fail_operation':'database'},{'oversize':'listeners'},{'unreadable':True}]:
            r,_=self.run_capture(faults);self.assertTrue(r['capture_errors']);validate_raw_capture(r)
    def test_profile_shell_and_sql_text_never_executes(self):
        for change in [{'shell_command':'fixture'},{'database_schema':"bad'; SELECT x"},{'database_schema':'fixture\n'},{'read_paths':[{'path':'/outside/x','kind':'cron'}]}]:
            bad=capture_profile()|change
            with self.assertRaises(self.m.CaptureError):self.m.validate_profile(bad)
            with self.assertRaises(SourceError):validate_capture_profile(bad)
    def test_standalone_rejects_duplicate_json_and_emits_no_raw_error(self):
        r=subprocess.run([sys.executable,str(Path(self.m.__file__))],input=b'{"secret":"fixture-private","secret":"different"}',capture_output=True)
        self.assertNotEqual(r.returncode,0);self.assertNotIn(b'fixture-private',r.stdout+r.stderr)

    def test_schema_drift_rejected_but_session_churn_is_not_schema_drift(self):
        class Drift(FakeReadOps):
            def run_operation(inner,name,*args):
                r=super(Drift,inner).run_operation(name,*args)
                if name=='database':
                    import base64
                    if sum(c==('operation','database') for c in inner.calls)>1:r['stdout_b64']=base64.b64encode(base64.b64decode(r['stdout_b64'])+b'OBJECT\tBASE TABLE\t6c617465\tInnoDB\n').decode()
                return r
        r=self.m.collect(capture_profile(),Drift(raw_capture()),FixtureClock())
        self.assertIn({'code':'capture-changed','ref':'database-manifest'},r['capture_errors'])
        class Churn(FakeReadOps):
            def run_operation(inner,name,*args):
                r=super(Churn,inner).run_operation(name,*args)
                if name=='database' and sum(c==('operation','database') for c in inner.calls)>1:
                    import base64
                    r['stdout_b64']=base64.b64encode(base64.b64decode(r['stdout_b64']).replace(b'SESSION\t3',b'SESSION\t4')).decode()
                return r
        self.assertFalse(self.m.collect(capture_profile(),Churn(raw_capture()),FixtureClock())['capture_errors'])

    def test_deleted_selected_executable_is_explicit(self):
        ops=self.m.ReadOps()
        with patch.object(self.m.os,'listdir',return_value=['123']),patch.object(self.m.os,'readlink',return_value='/fixture/core (deleted)'):
            ops.sample_processes([{'exe':'/fixture/core','module':'billmgr'}])
        self.assertIn({'code':'process-executable-deleted','ref':'123'},ops.errors)

if __name__=='__main__':unittest.main()
