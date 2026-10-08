from contextlib import redirect_stdout, redirect_stderr
import errno
import importlib
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from boundary_fixtures import build_boundary_fixture

class BoundaryCliTest(unittest.TestCase):
    def setUp(self):
        try:self.c=importlib.import_module('boundary_cli')
        except ModuleNotFoundError:self.fail('boundary_cli functionality missing')
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name)
        self.inputs=self.root/'inputs';self.inputs.mkdir(mode=0o700);self.output=self.root/'reports';self.output.mkdir(mode=0o700)
        obs=self.c.current_observer_identity(os.getpid());self.fixture=build_boundary_fixture(self.inputs,obs,time.monotonic_ns())
    def args(self,operation='evaluate'):
        f=self.fixture;args=[operation,'--plan',f['plan_ref']['path'],'--plan-sha256',f['plan_ref']['sha256'],'--reference-root',str(self.inputs),'--evidence',str(self.output)]
        if operation=='evaluate':
            for w in f['witness_refs']:args+=['--witness',w['path'],w['sha256']]
        return args
    def call(self,args):
        out=io.StringIO();err=io.StringIO()
        with redirect_stdout(out),redirect_stderr(err):rc=self.c.main(args)
        return rc,out.getvalue(),err.getvalue()
    def reports(self):return [json.loads(p.read_text()) for p in self.output.iterdir() if p.suffix=='.json']
    def test_validate_is_syntax_only_and_evaluate_exits_not_ready(self):
        rc,out,err=self.call(self.args('validate'));self.assertEqual(rc,0);self.assertEqual(err,'')
        result=json.loads(out);self.assertFalse(result['real_execution_ready']);self.assertEqual(result['purpose'],'source-control-boundary-cli-result')
        r=self.reports()[-1];self.assertFalse(r['coverage_complete']);self.assertFalse(r['fictional_witnesses_complete']);self.assertEqual(r['blockers'][0]['code'],'syntax-only')
        rc,out,err=self.call(self.args());self.assertEqual(rc,3);self.assertEqual(err,'');self.assertFalse(json.loads(out)['real_execution_ready'])
        self.assertTrue(any(r['fictional_witnesses_complete'] for r in self.reports()))
    def test_errors_never_echo_private_arguments(self):
        for args in [self.args()+['--secret','PRIVATE_SENTINEL'],self.args('validate')+['--plan-sha256','PRIVATE_SENTINEL'],['freeze','PRIVATE_SENTINEL']]:
            rc,out,err=self.call(args);self.assertEqual(rc,2);self.assertEqual(out,'');self.assertNotIn('PRIVATE_SENTINEL',err);self.assertRegex(err,r'^boundary: [a-z-]+\n$')
    def test_no_external_or_application_operation(self):
        import builtins
        real_import=builtins.__import__
        def guarded(name,*args,**kwargs):
            self.assertFalse(name.startswith(('app','laravel')));return real_import(name,*args,**kwargs)
        with patch('socket.socket',side_effect=AssertionError('network operation')),patch('subprocess.Popen',side_effect=AssertionError('subprocess operation')),patch('builtins.__import__',side_effect=guarded):
            self.assertEqual(self.call(self.args())[0],3)
        self.assertEqual({p.name for p in self.root.iterdir()},{'inputs','reports'})
    def test_exclusive_fsynced_report_and_failure_cleanup(self):
        rc,out,_=self.call(self.args());self.assertEqual(rc,3);h=json.loads(out)['report_sha256']
        import hashlib
        files=list(self.output.iterdir());self.assertEqual(len(files),1);p=files[0];s=p.lstat()
        self.assertEqual(stat.S_IMODE(s.st_mode),0o600);self.assertEqual(s.st_nlink,1);self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),h)
        self.assertEqual(self.call(self.args())[0],3);self.assertEqual(len(list(self.output.iterdir())),2)
        for operation in ['write','fsync']:
            with patch('os.'+operation,side_effect=OSError(errno.ENOSPC,'PRIVATE_STORAGE_SECRET')):
                rc,out,err=self.call(self.args());self.assertEqual(rc,2);self.assertEqual(out,'');self.assertEqual(err,'boundary: report-storage\n')
        self.output.chmod(0o755);self.assertEqual(self.call(self.args())[0],2);self.output.chmod(0o700)
    def test_reference_substitution_before_publication(self):
        original=self.c.evaluate_boundary
        def changed(*args):
            report=original(*args);p=Path(self.fixture['refs']['cohort']['path']);q=self.inputs/'replacement';q.write_bytes(p.read_bytes());q.chmod(0o600);q.replace(p);return report
        with patch.object(self.c,'evaluate_boundary',side_effect=changed):rc,out,err=self.call(self.args())
        self.assertEqual(rc,2);self.assertEqual(out,'');self.assertEqual(err,'boundary: reference-substitution\n');self.assertEqual(list(self.output.iterdir()),[])
    def test_live_observer_identity_not_cli_pid(self):
        child=subprocess.Popen([sys.executable,'-c','import time; time.sleep(30)'])
        self.addCleanup(lambda:child.poll() is None and child.kill());self.addCleanup(lambda:child.poll() is None and child.wait(timeout=35))
        obs=self.c.current_observer_identity(child.pid);self.assertEqual(obs['pid'],child.pid);self.assertNotEqual(obs['pid'],os.getpid())
        self.fixture=build_boundary_fixture(self.inputs,obs,time.monotonic_ns());self.assertEqual(self.call(self.args())[0],3)
        self.assertTrue(self.reports()[-1]['fictional_witnesses_complete'])
        child.terminate();child.wait(timeout=5);rc,out,err=self.call(self.args());self.assertEqual(rc,3);self.assertEqual(err,'')
        r=max(self.output.iterdir(),key=lambda p:p.stat().st_mtime_ns);self.assertIn('witness-observer',{b['code'] for b in json.loads(r.read_text())['blockers']})

    def test_final_reference_read_replacement_withholds_receipt(self):
        from boundary_refs import ReferenceSet
        original_verify=ReferenceSet.verify_unchanged;original_raw=ReferenceSet._raw
        count=[0];changed=[False];target=Path(self.fixture['refs']['cohort']['path']);ino=target.stat().st_ino
        def verify(rs):
            if rs.root==self.inputs:count[0]+=1
            return original_verify(rs)
        def raw(rs,fd):
            if rs.root==self.inputs and count[0]==3 and os.fstat(fd).st_ino==ino and not changed[0]:
                q=self.inputs/'last-replacement';q.write_bytes(target.read_bytes());q.chmod(0o600);q.replace(target);changed[0]=True
            return original_raw(rs,fd)
        with patch.object(ReferenceSet,'verify_unchanged',verify),patch.object(ReferenceSet,'_raw',raw):rc,out,err=self.call(self.args())
        self.assertTrue(changed[0]);self.assertEqual(rc,2);self.assertEqual(out,'');self.assertEqual(err,'boundary: reference-substitution\n')
    def test_final_reference_read_ancestor_replacement_withholds_receipt(self):
        from boundary_refs import ReferenceSet
        original_verify=ReferenceSet.verify_unchanged;original_raw=ReferenceSet._raw
        count=[0];changed=[False];moved=self.root/'moved-inputs'
        def verify(rs):
            if rs.root==self.inputs:count[0]+=1
            return original_verify(rs)
        def raw(rs,fd):
            if rs.root==self.inputs and count[0]==3 and not changed[0]:
                self.inputs.rename(moved);self.inputs.mkdir(mode=0o700);changed[0]=True
            return original_raw(rs,fd)
        try:
            with patch.object(ReferenceSet,'verify_unchanged',verify),patch.object(ReferenceSet,'_raw',raw):rc,out,err=self.call(self.args())
            self.assertTrue(changed[0]);self.assertEqual(rc,2);self.assertEqual(out,'');self.assertEqual(err,'boundary: reference-substitution\n')
        finally:
            if changed[0]:self.inputs.rmdir();moved.rename(self.inputs)

    def test_report_read_substitution_withholds_receipt(self):
        from boundary_refs import ReferenceSet
        original=ReferenceSet._raw;changed=[False]
        def raw(rs,fd):
            if rs.root==self.output and not changed[0]:
                target=Path(os.readlink('/proc/self/fd/'+str(fd)));q=self.output/'report-replacement';q.write_bytes(target.read_bytes());q.chmod(0o600);q.replace(target);changed[0]=True
            return original(rs,fd)
        with patch.object(ReferenceSet,'_raw',raw):rc,out,err=self.call(self.args())
        self.assertTrue(changed[0]);self.assertEqual(rc,2);self.assertEqual(out,'');self.assertEqual(err,'boundary: reference-substitution\n')
    def test_changed_observer_before_receipt_is_rejected(self):
        original=self.c.current_observer_identity;calls=[0]
        def changed(pid):
            calls[0]+=1;v=original(pid)
            return v if calls[0]<3 else v|{'exe_sha256':'f'*64}
        with patch.object(self.c,'current_observer_identity',side_effect=changed):rc,out,err=self.call(self.args())
        self.assertEqual(rc,2);self.assertEqual(out,'');self.assertEqual(err,'boundary: witness-observer\n')

if __name__=='__main__':unittest.main()
