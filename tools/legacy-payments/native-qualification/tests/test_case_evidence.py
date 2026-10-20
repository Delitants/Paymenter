"""Protected file integration with authored runtime/DB/caller facts only."""
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
# Keep protected runtime fixtures shallow; every custody check still runs.
FIXTURE_ROOT=HERE.parents[3]
NATIVE=HERE.parent/'native'
SCENARIOS=('f1','f2','f3','f4-barrier','f4-after','split','wrong-writer','wrong-intent',
           'wrong-request','same-reader','read-failure','completion-hash','stale-read',
           'target-changed','read-symlink','bind-replay','lost-recovered','reused-reader',
           'changed-rows','missing-withheld','caller-still-alive','caller-stderr',
           'caller-partial','response-delivered','recovery-earlier','changed-original',
           'replaced-original','replaced-writer','replaced-authority','preexisting-fresh',
           'caller-wrong-intent','caller-wrong-nonce')


def fields(purpose, values):
    return (purpose+'\n'+''.join(k+'='+str(v)+'\n' for k,v in values)).encode()


def controls(root):
    run='a'*64;now=int(time.time());image=b'\x7fELFfictional-only\n'
    images=[('module_path',str(root/'module.image')),('module_sha256',hashlib.sha256(image).hexdigest()),
            ('runtime_path',str(root/'runtime.image')),('runtime_sha256',hashlib.sha256(image+b'runtime').hexdigest())]
    registry=fields('paymenter-source-qa-registry-native-v1',[('run',run),('tables','paymenter_qa_business_v1,paymenter_qa_journal_v1'),
        ('objects','none'),('diagnostics','writer.F1.original,writer.F2.original,writer.F3.original,writer.F4.original')])
    build=fields('paymenter-source-qa-build-native-v1',[('run',run),*images])
    auth=[('intent','issue-framework-authority'),('run',run),('boot','11111111-1111-1111-1111-111111111111'),
          ('actor_hex',b'fictional-admin'.hex()),('issued',now-3),('expires',now+100),*images,
          ('registry_sha256',hashlib.sha256(registry).hexdigest()),('build_sha256',hashlib.sha256(build).hexdigest())]
    for case,nonce in [('F1','1'),('F2','2'),('F3','3'),('F4','4')]:auth.extend([('nonce_'+case,nonce*64),('facts_'+case,'c'*64)])
    for name,raw in [('module.image',image),('runtime.image',image+b'runtime'),('registry.original',registry),
                     ('build.original',build),('approval.native.original',fields('paymenter-source-qa-approve-authority-native-v1',auth))]:
        (root/name).write_bytes(raw);(root/name).chmod(0o600)


class CaseEvidenceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='case-evidence-build-',dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary=Path(cls.temp.name)/'driver';cls.compiled=None
        if (NATIVE/'case_evidence.cpp').exists():
            flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],capture_output=True,timeout=5,check=True).stdout.decode().split()
            if sys.platform.startswith('linux'):flags.append('-ldl')
            cls.compiled=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror','-pthread',
                str(HERE/'case_evidence_driver.cpp'),'-o',str(cls.binary),*flags],capture_output=True,timeout=30)

    def scenario(self,name):
        self.assertIsNotNone(self.compiled,'protected case evidence binding is not implemented')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='case-evidence-',dir=FIXTURE_ROOT) as tmp:
            root=Path(tmp).resolve();controls(root)
            r=subprocess.run([str(self.binary),name,str(root)],capture_output=True,timeout=5)
            self.assertEqual(0,r.returncode,r.stderr.decode(errors='replace'))
            if name=='lost-recovered':
                raw=(root/'recovered.F1.original').read_text()
                self.assertIn('scope=collector-response-only\n',raw)
                self.assertNotIn('framework_atomicity=1',raw)


for name in SCENARIOS:
    def run(self,name=name):self.scenario(name)
    setattr(CaseEvidenceTests,'test_'+name.replace('-','_'),run)
