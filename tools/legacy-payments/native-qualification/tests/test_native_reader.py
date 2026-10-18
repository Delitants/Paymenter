"""Protected originals plus authored MySQL C API boundary, not a real DB."""
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
SCENARIOS=('audit','audit-empty','audit-schema-changed','audit-schema-hash','audit-trigger','channel','observation','expired','wrong-boot','replay','include-config',
           'extra-config-key','credential-permissions','credential-change','connect-failure',
           'query-failure','wrong-socket','active-transaction','proposal-intent','credential-symlink','spaced-config')


class NativeReaderTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='native-reader-build-',dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary=Path(cls.temp.name)/'driver'
        cls.compiled=None
        if (NATIVE/'committed_reader.cpp').exists():
            flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],capture_output=True,timeout=5,check=True).stdout.decode().split()
            if sys.platform.startswith('linux'):flags.append('-ldl')
            cls.compiled=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror','-pthread',
                '-I'+str(HERE/'fake_mysql'),str(HERE/'native_reader_driver.cpp'),'-o',str(cls.binary),*flags],capture_output=True,timeout=30)

    def scenario(self,name):
        self.assertIsNotNone(self.compiled,'native committed reader is not implemented')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='reader-control-',dir=HERE) as tmp:
            root=Path(tmp).resolve();now=int(time.time())
            credential=b'[client]\npassword=fictional-secret\n'
            if name=='spaced-config':credential=b'[client]\npassword = fictional-secret\n'
            if name=='include-config':credential+=b'!include /unreviewed\n'
            if name=='extra-config-key':credential+=b'init-command=DELETE FROM forbidden\n'
            (root/'credential').write_bytes(credential);(root/'credential').chmod(0o644 if name=='credential-permissions' else 0o400)
            if name=='credential-symlink':(root/'credential-link').symlink_to(root/'credential')
            audit=name.startswith('audit')
            kind='audit' if audit else 'observation' if name=='observation' else 'channel'
            fields=[('intent','prepare-only' if name=='proposal-intent' else 'observe-framework'),('kind',kind),('run','a'*64),('request','b'*64),
                ('boot','other' if name=='wrong-boot' else '11111111-1111-1111-1111-111111111111'),
                ('case','F1' if kind=='observation' else 'none'),('phase','after' if kind in ('observation','audit') else 'none'),
                ('facts','c'*64),('writer','11' if kind=='observation' else 'none'),('previous','10' if kind=='observation' else 'none'),
                ('issued',str(now-2)),('expires',str(now-1 if name=='expired' else now+20))]
            if audit:
                for case in ('F1','F2','F3','F4'):fields.append(('facts_'+case,'c'*64))
                for table in ('business','journal'):
                    schema=('fictional '+table+' schema').encode()
                    (root/('qa.schema.'+table+'.original')).write_bytes(schema);(root/('qa.schema.'+table+'.original')).chmod(0o600)
                    fields.append((table+'_sha256','f'*64 if name=='audit-schema-hash' else hashlib.sha256(schema).hexdigest()))
            raw=(('paymenter-source-qa-audit-approval-native-v1\n' if audit else 'paymenter-source-qa-read-approval-native-v1\n')+''.join(k+'='+v+'\n' for k,v in fields)).encode()
            (root/'read.approval.native.original').write_bytes(raw);(root/'read.approval.native.original').chmod(0o600)
            if name=='replay':(root/'read.started.original').write_bytes(b'old');(root/'read.started.original').chmod(0o600)
            result=subprocess.run([str(self.binary),name,str(root)],capture_output=True,timeout=3)
            self.assertEqual(0,result.returncode,result.stderr.decode(errors='replace'))
            self.assertNotIn(b'fictional-secret',result.stdout+result.stderr)
            if name in ('channel','observation','spaced-config','audit','audit-empty'):
                original=(root/'read.original').read_bytes()
                self.assertIn(b'connection=12\n',original)
                self.assertIn(b'transaction_open=0\n',original)
                self.assertIn(hashlib.sha256(original).hexdigest().encode(),(root/'read.complete.original').read_bytes())
            else:self.assertFalse((root/'read.complete.original').exists())


for name in SCENARIOS:
    def run(self,name=name):self.scenario(name)
    setattr(NativeReaderTests,'test_'+name.replace('-','_'),run)
