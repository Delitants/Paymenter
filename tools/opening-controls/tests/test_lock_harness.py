import importlib
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from source_schema import SourceError

class HarnessTest(unittest.TestCase):
    def setUp(self):
        try:self.m=importlib.import_module('lock_harness')
        except ModuleNotFoundError:self.fail('lock_harness functionality missing')
    def test_namespace_version_and_reserve_checks_precede_sql(self):
        with patch.object(self.m.shutil,'disk_usage',return_value=(10,9,1)):
            with self.assertRaises(SourceError):self.m.check_reserve(0)
        with self.assertRaises(SourceError):self.m.verify_isolation({'parent_net_ns':'fixture','parent_pid_ns':'fixture'},'fixture','fixture')
    def test_wrong_sql_error_or_dead_writer_is_not_successful_blocking(self):
        with self.assertRaises(SourceError):self.m.validate_query(1,'','ERROR 1064',1,'ERROR 1290')
        class Dead:
            def poll(self):return 1
        with self.assertRaises(SourceError):self.m.wait_for_lock(Dead(),lambda:'Waiting for table level lock')
    def test_failed_shutdown_and_reused_pid_preserve_failure_evidence(self):
        class Child:
            pid=123
            def poll(self):return None
            def terminate(self):raise AssertionError('Reused PID must not be signalled')
        with patch.object(self.m,'process_identity',return_value={'pid':123,'start_ticks':'new','boot_id':'fixture'}):
            result=self.m.retire_owned([{'popen':Child(),'identity':{'pid':123,'start_ticks':'old','boot_id':'fixture'}}],None,None)
        self.assertFalse(result['all_owned_processes_retired'])
    def test_worker_cannot_target_external_runtime(self):
        spec={'runtime':{'root':'/fixture/external'},'evidence':'/fixture/evidence','owned_parent':'/dev/shm/opening-lock-fixture','owned_parent_identity':[1,2],'parent_net_ns':'parent','parent_pid_ns':'parent'}
        with patch.object(self.m.os,'readlink',return_value='child'),patch.object(self.m,'Experiment',side_effect=AssertionError('External runtime must be rejected before initialization')):
            with self.assertRaises(SourceError):self.m._worker(spec)

    def test_legacy_message_directory_is_above_language_directory(self):
        self.assertEqual(self.m.message_directory('usr/share/mysql/english/errmsg.sys'),'/usr/share/mysql')

    def test_cleanup_failure_preserves_owned_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'owned';root.mkdir();(root/'retain').write_bytes(b'evidence')
            self.assertFalse(self.m.remove_retired_runtime(root,{'all_owned_processes_retired':False}))
            self.assertTrue((root/'retain').exists())

    def test_replaced_owned_root_is_never_removed(self):
        import os
        with tempfile.TemporaryDirectory(prefix='opening-lock-',dir='/dev/shm' if sys.platform.startswith('linux') else None) as tmp:
            root=Path(tmp);root.chmod(0o700);(root/'preserve').write_bytes(b'replacement')
            expected=[root.stat().st_dev,root.stat().st_ino+1]
            with patch.object(self.m,'owned_parent_path',return_value=True,create=True):
                try:self.m.remove_retired_runtime(root,{'all_owned_processes_retired':True},expected)
                except SourceError:pass
                except TypeError:self.fail('Original directory identity is not part of cleanup')
            self.assertTrue((root/'preserve').exists())

if __name__=='__main__':unittest.main()
