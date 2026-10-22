"""Protected original custody with authored C API/kernel facts, never native DDL."""
from pathlib import Path
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tempfile
import time
import unittest

HERE=Path(__file__).resolve().parent
NATIVE=HERE.parent/'native'
SCENARIOS=('create','cleanup','collision','second-create-failure','create-expired','create-wrong-boot',
 'create-wrong-issuer','create-active-authority','create-live-module','create-schema-mismatch',
 'create-trigger','create-link','create-reuse','create-approval-replaced','create-archive-failure',
 'cleanup-no-revocation','cleanup-expired','cleanup-wrong-run','cleanup-copied-schema',
 'cleanup-changed-receipt','cleanup-changed-registry','cleanup-replaced-revocation',
 'cleanup-live-writer','cleanup-unknown-writer','cleanup-mapped-module','cleanup-wrong-manager',
 'cleanup-writer-without-intent','cleanup-bad-writer-binding','cleanup-unknown-active-intent',
 'cleanup-revocation-after-open','cleanup-fork','cleanup-thread','cleanup-reuse',
 'create-credential-refusal','cleanup-partial-drop','cleanup-archive-preexists',
 'create-schema-replaced','create-schema-content-changed','cleanup-start-replaced','cleanup-complete-copied')

def sha(raw):return hashlib.sha256(raw).hexdigest()
def record(purpose,pairs):return (purpose+'\n'+''.join(k+'='+str(v)+'\n' for k,v in pairs)).encode()
def statements():
    text='\n'.join(l for l in (NATIVE/'schema.sql').read_text().splitlines() if not l.strip().startswith('--'))
    return [' '.join(x.split())+';' for x in text.split(';') if x.strip()]

class ResourceCustodyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='resource-custody-build-',dir=HERE);cls.addClassCleanup(cls.temp.cleanup)
        cls.binary=Path(cls.temp.name)/'driver';cls.compiled=None
        if (NATIVE/'resource_custody.cpp').exists():
            flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],capture_output=True,timeout=5,check=True).stdout.decode().split()
            if sys.platform.startswith('linux'):flags.append('-ldl')
            cls.compiled=subprocess.run(['/usr/bin/c++','-std=c++11','-pthread','-Wall','-Wextra','-Werror',
                '-I'+str(HERE/'fake_retirement_mysql'),str(HERE/'resource_custody_driver.cpp'),'-o',str(cls.binary),*flags],capture_output=True,timeout=30)
    def scenario(self,name):
        self.assertIsNotNone(self.compiled,'native resource creation and retirement guard are missing')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='resource-custody-',dir=HERE) as tmp:
            root=Path(tmp).resolve();now=int(time.time());run='a'*64
            images={k:b'\x7fELFfictional '+k.encode()+b', never executed\n' for k in ('module','runtime','issuer')}
            image_fields=[('module_path',str(root/'module.image')),('module_sha256',sha(images['module'])),('runtime_path',str(root/'runtime.image')),('runtime_sha256',sha(images['runtime']))]
            registry=record('paymenter-source-qa-registry-native-v1',[('run',run),('tables','paymenter_qa_business_v1,paymenter_qa_journal_v1'),('objects','none'),('diagnostics','writer.F1.original,writer.F2.original,writer.F3.original,writer.F4.original')])
            build=record('paymenter-source-qa-build-native-v1',[('run',run),*image_fields])
            fields=[('intent','issue-framework-authority'),('run',run),('boot','11111111-1111-1111-1111-111111111111'),('actor_hex',b'fictional-admin'.hex()),('issued',now-3),('expires',now+300),*image_fields,('registry_sha256',sha(registry)),('build_sha256',sha(build))]
            for i in range(1,5):fields.extend([('nonce_F'+str(i),str(i)*64),('facts_F'+str(i),'c'*64)])
            approval=record('paymenter-source-qa-approve-authority-native-v1',fields)
            create=record('paymenter-source-qa-create-approval-v1',[
                ('intent','create-owned-qa'),('run',run),('boot','other' if name=='create-wrong-boot' else fields[2][1]),
                ('issued',now-2),('expires',now-1 if name=='create-expired' else now+25),
                ('authority_approval_sha256',sha(approval)),('registry_sha256',sha(registry)),('build_sha256',sha(build)),
                ('manager_pid',2),('manager_start',99),('ddl_sha256',sha(('\n'.join(statements())).encode())),
                ('issuer_path',str(root/'issuer.image')),('issuer_sha256','f'*64 if name=='create-wrong-issuer' else sha(images['issuer']))])
            files={'registry.original':registry,'build.original':build,'approval.native.original':approval,'qa.create.approval.original':create,
                   'credential':b'[client]\npassword=fictional-secret\n'}
            files.update({k+'.image':v for k,v in images.items()})
            if name=='create-active-authority':files['authority.v1']=b'not a creation operation\n'
            for k,v in files.items():(root/k).write_bytes(v);(root/k).chmod(0o400 if k=='credential' else 0o600)
            if name=='create-credential-refusal':(root/'credential').chmod(0o644)
            result=subprocess.run([str(self.binary),name,str(root)],capture_output=True,timeout=15)
            self.assertEqual(0,result.returncode,result.stderr.decode(errors='replace'))
            self.assertNotIn(b'fictional-secret',result.stdout+result.stderr)
            if name in ('create','cleanup'):
                self.assertTrue((root/'qa.resources.complete.original').exists())
            if name=='cleanup':
                self.assertTrue((root/'retired.v1').exists());self.assertTrue((root/'qa.retirement.original').exists())

for name in SCENARIOS:
    def run(self,name=name):self.scenario(name)
    setattr(ResourceCustodyTests,'test_'+name.replace('-','_'),run)
