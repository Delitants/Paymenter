"""Real protected files + authored C API boundary; no native DB acceptance."""
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
TABLES=('account','account2project','affiliatereward','billorder','billorderitem','currency','expense','expense2payment','invoiceitem2payment','item','lineitem','payment','payment2payment','payment_upload2payment','paymenter_qa_journal_v1','paymethod','paymethod2company','paymethod2group','paymethod2project','paymethodparam','promotioncoupon2account','recurring','recurring_subscription','subaccount','user')
SCENARIOS=('late-schema-change','after-original-revoke','after-complete-revoke','lost-completion','prior-original','prior-completion','invalid-journal','valid','M5','legacy-limit','expired','wrong-boot','wrong-intent','registry-hash','identity-hash','schema-hash','bad-writer','replay','prior-failure','credential-permissions','credential-symlink','credential-include','credential-change','approval-change','image-change','connect-failure','query-failure','wrong-principal','wrong-socket','active-transaction','oversize-cell','fetch-error','foreign-row','observation-revoke','preimage-binding','extra-registry-field')
def digest(b):return hashlib.sha256(b).hexdigest()
def encode(purpose,fields):return (purpose+'\n'+''.join(k+'='+v+'\n' for k,v in fields)).encode()
class ManualFinancialNativeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='manual-native-build-',dir=HERE);cls.addClassCleanup(cls.temp.cleanup);cls.binary=Path(cls.temp.name)/'driver';cls.compiled=None
        if (NATIVE/'manual_financial_reader.cpp').exists():
            flags=subprocess.run([shutil.which('pkg-config') or 'pkg-config','--cflags','--libs','openssl'],capture_output=True,check=True).stdout.decode().split()
            if sys.platform.startswith('linux'):flags.append('-ldl')
            cls.compiled=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror','-pthread','-I'+str(HERE/'fake_manual_mysql'),'-I'+str(HERE),str(HERE/'manual_financial_native_driver.cpp'),'-o',str(cls.binary),*flags],capture_output=True,timeout=30)
    def scenario(self,mode):
        self.assertIsNotNone(self.compiled,'protected manual financial collector is not implemented')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='manual-native-control-',dir=HERE) as temp:
            root=Path(temp).resolve();root.chmod(0o700);now=int(time.time());files={};identities={}
            for name in ('runtime','library','reader'):
                raw=('fictional '+name).encode();files[name]=raw;identities[name+'_sha256']=digest(raw)
            identity=encode('paymenter-manual-read-identity-v1',list(identities.items()));files['manual.identity.original']=identity
            fields=[('run','a'*64),('boot','11111111-1111-1111-1111-111111111111'),('actor_hex',b'fictional-admin'.hex()),('facts','c'*64),('account','101'),('subaccount','201'),('project','301'),('currency','401'),('method','501'),('module','pmfixture'),('payments','1001,1002,1003,1004'),('users','801,802')]
            files['manual.registry.original']=encode('paymenter-manual-financial-registry-v1',fields+list(identities.items())+([('unknown','yes')] if mode=='extra-registry-field' else []))
            pre=[p for p in fields if p[0] not in ('actor_hex','module')]+[('balance','0.0000'),('creation_sha256','e'*64)]
            if mode=='preimage-binding':pre=[(k,'999' if k=='account' else v) for k,v in pre]
            files['manual.preimage.original']=encode('paymenter-manual-financial-preimage-v1',pre)
            files['manual.writer.original']=encode('paymenter-manual-financial-writer-v1',[('run','a'*64),('boot',fields[1][1]),('actor_hex',fields[2][1]),('case','M0' if mode=='invalid-journal' else 'M1'),('payment','1001'),('facts','c'*64),('connection','99' if mode=='bad-writer' else '11'),('pid','123'),('start','456'),('runtime_sha256',identities['runtime_sha256']),('library_sha256',identities['library_sha256'])])
            files['manual.foreign_keys.original']=b'paymenter-manual-financial-foreign-keys-v1\n'
            schemas=[]
            for table in TABLES:
                raw=('fictional schema '+table).encode();files['manual.schema.'+table+'.original']=raw;schemas.append((table,digest(raw)))
            if mode=='schema-hash':schemas[0]=(schemas[0][0],'f'*64)
            files['manual.schema.original']=encode('paymenter-manual-financial-schema-v1',schemas)
            approval=[('intent','prepare-only' if mode=='wrong-intent' else 'observe-manual-financial'),('run','a'*64),('request','b'*64),('boot','other' if mode=='wrong-boot' else fields[1][1]),('actor_hex',fields[2][1]),('case','M5' if mode=='M5' else 'M1'),('journal_case','M0' if mode=='invalid-journal' else 'M1'),('phase','after'),('facts','c'*64),('writer','11'),('previous','10')]
            for key,name in [('registry','manual.registry.original'),('preimage','manual.preimage.original'),('schema','manual.schema.original'),('foreign_keys','manual.foreign_keys.original'),('writer','manual.writer.original'),('identity','manual.identity.original')]:approval.append((key+'_sha256','f'*64 if mode==key+'-hash' else digest(files[name])))
            approval += [('issued',str(now-2)),('expires',str(now-1 if mode=='expired' else now+25))]
            files['manual.read.approval.original']=encode('paymenter-manual-financial-read-approval-v1',approval)
            files['credential']=b'[client]\npassword=fictional-secret\n'+(b'!include /unreviewed\n' if mode=='credential-include' else b'')
            if mode=='replay':files['manual.read.started.original']=b'old attempt\n'
            if mode=='prior-original':files['manual.read.original']=b'old observation\n'
            if mode=='prior-completion':files['manual.read.complete.original']=b'old completion\n'
            if mode=='prior-failure':files['manual.read.failure.original']=b'old failure\n'
            for name,b in files.items():(root/name).write_bytes(b);(root/name).chmod(0o644 if name=='credential' and mode=='credential-permissions' else 0o600)
            for name in ('runtime','reader'):(root/name).chmod(0o700)
            if mode=='credential-symlink':(root/'credential-link').symlink_to(root/'credential')
            result=subprocess.run([str(self.binary),mode,str(root)],capture_output=True,timeout=30)
            self.assertEqual(0,result.returncode,result.stderr.decode(errors='replace'))
            self.assertNotIn(b'fictional-secret',result.stdout+result.stderr)
            complete=root/'manual.read.complete.original'
            if mode in ('valid','M5','legacy-limit'):
                raw=(root/'manual.read.original').read_bytes();self.assertIn(b'connection=12\n',raw);self.assertIn(b'row=journal\t1001\tM1\tM1\t',raw);self.assertIn(digest(raw).encode(),complete.read_bytes());self.assertNotIn(b'fictional-secret',raw)
            else:
                if mode not in ('after-complete-revoke','lost-completion','prior-completion'):
                    self.assertFalse(complete.exists())
                if mode=='lost-completion':self.assertFalse(complete.is_file())
                if mode=='after-complete-revoke':self.assertTrue(complete.is_file())
                if mode in ('after-original-revoke','after-complete-revoke','lost-completion'):
                    self.assertTrue((root/'manual.read.original').is_file())
                    self.assertTrue((root/'manual.read.failure.original').is_file())
                if (root/'manual.read.started.original').exists() and mode!='replay':
                    self.assertTrue((root/'manual.read.failure.original').is_file())
for mode in SCENARIOS:
    def run(self,mode=mode):self.scenario(mode)
    setattr(ManualFinancialNativeTests,'test_'+mode.replace('-','_'),run)
