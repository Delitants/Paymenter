"""Real native file publisher; authored local boot/target facts, not native QA."""
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import unittest

HERE=Path(__file__).resolve().parent
NATIVE=HERE.parent/'native'
SCENARIOS=('authority-valid','prepare-only','bad-registry','wrong-image','bad-boot','expired',
           'reused-nonce','proposal-json','wrong-build','sync-failure','authority-reuse',
           'case-valid','case-nonce','case-expired','case-unbounded','target-refused','f5',
           'existing-intent','case-reuse','unseal-sync-failure','case-sync-failure',
           'changed-approval','target-late-change')


def sha(raw):return hashlib.sha256(raw).hexdigest()
def lines(purpose, fields):return (purpose+'\n'+''.join(k+'='+str(v)+'\n' for k,v in fields)).encode()


class NativeIssuerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='native-issuer-build-',dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary=Path(cls.temp.name)/'native-issuer-driver'
        cls.compiled=None
        if (NATIVE/'control_issuer.cpp').exists():
            flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],
                capture_output=True,timeout=5,check=True).stdout.decode().split()
            if sys.platform.startswith('linux'):flags.append('-ldl')
            cls.compiled=subprocess.run(['/usr/bin/c++','-std=c++11','-pthread','-Wall','-Wextra','-Werror',
                str(HERE/'native_issuer_driver.cpp'),'-o',str(cls.binary),*flags],capture_output=True,timeout=30)

    def scenario(self, name):
        self.assertIsNotNone(self.compiled,'native control issuer is not implemented')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='native-issuer-fixture-',dir=HERE) as temp:
            root=Path(temp).resolve()
            module=b'\x7fELFfictional module, never loaded\n'
            runtime=b'\x7fELFfictional runtime, never executed\n'
            run='a'*64
            image_fields=[('module_path',str(root/'module.image')),('module_sha256',sha(module)),
                          ('runtime_path',str(root/'runtime.image')),('runtime_sha256',sha(runtime))]
            registry=lines('paymenter-source-qa-registry-native-v1', [('run',run),
                ('tables','account' if name=='bad-registry' else 'paymenter_qa_business_v1,paymenter_qa_journal_v1'),
                ('objects','none'),('diagnostics','writer.F1.original,writer.F2.original,writer.F3.original,writer.F4.original')])
            build=lines('paymenter-source-qa-build-native-v1',[('run','f'*64 if name=='wrong-build' else run),*image_fields])
            now=int(time.time())
            fields=[('intent','prepare-only' if name=='prepare-only' else 'issue-framework-authority'),
                ('run',run),('boot','22222222-2222-2222-2222-222222222222' if name=='bad-boot' else '11111111-1111-1111-1111-111111111111'),
                ('actor_hex',b'fictional-admin'.hex()),('issued',now-1),('expires',now-1 if name=='expired' else now+300),
                *image_fields,('registry_sha256',sha(registry)),('build_sha256',sha(build))]
            for case, nonce in [('F1','1'),('F2','1' if name=='reused-nonce' else '2'),('F3','3'),('F4','b')]:
                fields.extend([('nonce_'+case,nonce*64),('facts_'+case,'c'*64)])
            approval=lines('paymenter-source-qa-approve-authority-native-v1',fields)
            if name=='proposal-json':approval=b'{"intent":"prepare-only","reviewed":true}'
            expected_fields=[('intent','framework-qa-execute'),*fields[1:]]
            authority=lines('paymenter-source-qa-authority-v1',expected_fields)
            case=lines('paymenter-source-qa-approve-case-native-v1', [('intent','issue-framework-case'),('run',run),
                ('case','F1'),('nonce',('f' if name=='case-nonce' else '1')*64),('facts','c'*64),('pid','2'),('start','99'),
                ('boot','11111111-1111-1111-1111-111111111111'),('issued',now),
                ('expires',now-1 if name=='case-expired' else now+(31 if name=='case-unbounded' else 20)),
                ('authority_sha256',sha(authority))])
            for path,raw in {'module.image':b'changed' if name=='wrong-image' else module,'runtime.image':runtime,
                'registry.original':registry,'build.original':build,'approval.native.original':approval,
                'case.F1.approval.native.original':case}.items():
                (root/path).write_bytes(raw);(root/path).chmod(0o600)
            result=subprocess.run([str(self.binary),name,str(root)],capture_output=True,timeout=5)
            self.assertEqual(0,result.returncode,result.stderr.decode(errors='replace'))
            if name in ('authority-valid','case-valid'):
                self.assertEqual(authority,(root/'authority.v1').read_bytes())


for name in SCENARIOS:
    def run(self,name=name):self.scenario(name)
    setattr(NativeIssuerTests,'test_'+name.replace('-','_'),run)
