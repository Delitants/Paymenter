import builtins
import contextlib
import dataclasses
import importlib.util
import io
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from fixtures import FixtureClock,make_demo,write_json

class OfflineCommandTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('audit'),'offline command missing')
        from audit import run_offline,main
        self.run,self.main=run_offline,main
        self.stack=contextlib.ExitStack();self.addCleanup(self.stack.close)
        self.root=Path(self.stack.enter_context(tempfile.TemporaryDirectory())).resolve();self.root.chmod(0o700)
        self.clock=FixtureClock();self.d,self.f=make_demo(self.root,self.clock)
    def command(self,args=None):
        out,err=io.StringIO(),io.StringIO()
        with patch('audit.SystemClock',return_value=self.clock),contextlib.redirect_stdout(out),contextlib.redirect_stderr(err):
            status=self.main(args or ['--descriptor',str(self.d),'--fixture',str(self.f)])
        return status,out.getvalue(),err.getvalue()
    def test_complete_command_emits_only_fictional_summary(self):
        status,out,err=self.command();self.assertEqual(status,0);self.assertEqual(err,'')
        s=json.loads(out);self.assertEqual(s['origin'],'fictional');self.assertEqual(s['completion'],'complete')
        self.assertNotIn('source_instance',out);self.assertNotIn('payments:7',out);self.assertNotIn(str(self.root),out)
        data=json.loads((self.root/'out'/'fixture-00000001'/'analysis.json').read_bytes())
        self.assertEqual(data['payment_authority'],{'payments:7':'unqualified'})
    def test_spent_attempt_is_not_retried(self):
        self.assertEqual(self.command()[0],0);status,out,err=self.command()
        self.assertEqual(status,2);self.assertEqual(out,'');self.assertEqual(err,'attempt-spent\n')
    def test_older_changed_payment_is_found_on_next_full_sweep(self):
        first=self.run(self.d,self.f,self.clock).as_dict()
        d=json.loads(self.d.read_text());d['attempt_id']='fixture-00000002';d['previous_ref']=first['evidence']
        newd=write_json(self.root/'second.json',d);f=json.loads(self.f.read_text());f['rows']['payments'][0]['status']='4';write_json(self.f,f)
        self.run(newd,self.f,self.clock)
        report=json.loads((self.root/'out'/'fixture-00000002'/'analysis.json').read_text())
        self.assertEqual(report['changes']['payments:7']['kind'],'changed')
        self.assertEqual(report['changes']['payments:7']['fields'],['status'])
    def test_invalid_ownership_retains_incomplete_without_baseline_token(self):
        f=json.loads(self.f.read_text());f['rows']['subaccounts'][0]['account']='999';write_json(self.f,f)
        status,out,err=self.command();self.assertEqual(status,2);self.assertEqual(err,'')
        s=json.loads(out);self.assertEqual(s['completion'],'incomplete');self.assertIsNone(s['evidence']['completion_token'])
    def test_unsafe_or_private_arguments_do_not_echo(self):
        for args in [['--host','private-secret'],['--descriptor',str(self.d),'--fixture'],['--socket','private-secret']]:
            status,out,err=self.command(args);self.assertEqual(status,2);self.assertNotIn('private-secret',out+err)
            self.assertNotIn(str(self.root),out+err)
    def test_native_or_extra_fixture_fields_are_refused(self):
        for change in [{'origin':'native'},{'secret':'do-not-echo'}]:
            f=json.loads(self.f.read_text());f.update(change);write_json(self.f,f)
            status,out,err=self.command();self.assertEqual(status,2);self.assertEqual(out,'');self.assertNotIn('do-not-echo',err)
            # Reset for an independent preflight refusal.
            f.pop('secret',None);f['origin']='fictional';write_json(self.f,f)
    def test_no_external_channels_or_database_imports(self):
        original=builtins.__import__
        def deny(name,*args,**kwargs):
            if name.split('.')[0] in ('pymysql','mysql','MySQLdb','psycopg','requests','app','laravel'):raise AssertionError('forbidden-import')
            return original(name,*args,**kwargs)
        import http.client,smtplib
        with patch('socket.socket',side_effect=AssertionError('network')),patch('socket.create_connection',side_effect=AssertionError('network')),patch('http.client.HTTPConnection.connect',side_effect=AssertionError('http')),patch('smtplib.SMTP',side_effect=AssertionError('mail')),patch('subprocess.Popen',side_effect=AssertionError('process')),patch('builtins.__import__',side_effect=deny):
            self.assertEqual(self.command()[0],0)
    def test_finalization_failure_never_returns_success_or_raw_error(self):
        with patch('evidence.os.link',side_effect=OSError('private-finalization-error')):
            status,out,err=self.command()
        self.assertEqual(status,2);self.assertEqual(out,'');self.assertNotIn('private-finalization-error',err)
        self.assertTrue((self.root/'out'/'fixture-00000001'/'claim.json').exists())
    def test_elected_hold_remains_unqualified(self):
        f=json.loads(self.f.read_text());f['rows']['subaccounts'][0]['account']='102';write_json(self.f,f)
        summary=self.run(self.d,self.f,self.clock).as_dict()
        self.assertEqual(summary['reason_counts']['elected_hold'],1)
    def test_corrupt_prior_blocks_new_collection(self):
        first=self.run(self.d,self.f,self.clock).as_dict();d=json.loads(self.d.read_text());d['attempt_id']='fixture-00000002';d['previous_ref']=first['evidence'];d['previous_ref']['manifest_hash']='f'*64
        nextd=write_json(self.root/'second.json',d)
        status,out,err=self.command(['--descriptor',str(nextd),'--fixture',str(self.f)])
        self.assertEqual(status,2);self.assertEqual(out,'');self.assertEqual(err,'prior-hash\n')
    def test_documented_generator_accepts_public_system_clock(self):
        from models import SystemClock
        example=self.root/'documented-example';example.mkdir(mode=0o700)
        d,f=make_demo(example,SystemClock())
        self.assertEqual(self.run(d,f,SystemClock()).as_dict()['completion'],'complete')
    def test_clock_rollback_between_stages_never_gets_completion(self):
        import datetime as dt
        from models import AuditError
        for boundary,recover in [(2,False),(3,False),(3,True)]:
            with self.subTest(boundary=boundary,recover=recover):
                root=self.root/('clock-'+str(boundary)+'-'+str(recover));root.mkdir(mode=0o700)
                d,f=make_demo(root,FixtureClock())
                class RollbackClock(FixtureClock):
                    calls=0
                    def utc_now(self):
                        self.calls+=1
                        return self.utc-dt.timedelta(seconds=.5) if self.calls>=boundary and (not recover or self.calls==boundary) else self.utc
                try:summary=self.run(d,f,RollbackClock()).as_dict()
                except AuditError:continue
                self.assertEqual(summary['completion'],'incomplete')
                self.assertIsNone(summary['evidence']['completion_token'])
    def test_authority_loss_retains_available_incomplete_evidence(self):
        import datetime as dt
        from collect import FictionalTransport
        original=FictionalTransport.execute
        for reason in ('expiry','rollback','revocation'):
            with self.subTest(reason=reason):
                root=self.root/('failure-'+reason);root.mkdir(mode=0o700);clock=FixtureClock();d,f=make_demo(root,clock)
                def lose_authority(transport,query,timeout_s):
                    frame=original(transport,query,timeout_s)
                    if query.kind=='page' and query.dataset.value=='payments':
                        if reason=='expiry':clock.utc+=dt.timedelta(seconds=301)
                        if reason=='rollback':clock.utc-=dt.timedelta(seconds=.5)
                        if reason=='revocation':
                            data=json.loads(d.read_text());data['approval_scope_ref']='revoked';write_json(d,data)
                    return frame
                with patch.object(FictionalTransport,'execute',lose_authority):summary=self.run(d,f,clock).as_dict()
                self.assertEqual(summary['completion'],'incomplete');self.assertIsNone(summary['evidence']['completion_token'])
                attempt=root/'out'/'fixture-00000001';m=json.loads((attempt/'failure.manifest.json').read_text())
                self.assertEqual(m['collection_state'],'incomplete')
                name=next(iter(m['files']));c=json.loads((attempt/name).read_text())
                self.assertEqual(len(c['rows']['accounts']),2);self.assertEqual(c['lifecycle']['close'],'observed')
                self.assertTrue(m['reasons'])
    def test_previous_database_or_socket_mismatch_is_rejected(self):
        from models import AuditError
        first=self.run(self.d,self.f,self.clock).as_dict()
        for field in ('database_identity','socket_identity'):
            with self.subTest(field=field):
                data=json.loads(self.d.read_text());data['attempt_id']='other-'+field;data[field]='other-fictional-identity';data['previous_ref']=first['evidence']
                d=write_json(self.root/(field+'.json'),data)
                with self.assertRaisesRegex(AuditError,'analysis-binding'):self.run(d,self.f,self.clock)
    def test_canonical_values_are_stored_alongside_originals(self):
        data=json.loads(self.f.read_text());data['rows']['payments'][0]['subaccountamount']='10.00';write_json(self.f,data)
        self.run(self.d,self.f,self.clock)
        attempt=self.root/'out'/'fixture-00000001'
        source=json.loads((attempt/'collection.json').read_text())
        report=json.loads((attempt/'analysis.json').read_text())
        self.assertEqual(source['rows']['payments'][0]['subaccountamount'],'10.00')
        self.assertEqual(report['canonical_values']['payments:7']['subaccountamount'],'10.0000')
    def test_expiry_after_manifest_publication_has_no_completion_token(self):
        import datetime as dt
        original=os.link
        def expire_after_link(src,dst,**kwargs):
            result=original(src,dst,**kwargs)
            if dst=='manifest.json':self.clock.utc+=dt.timedelta(seconds=301)
            return result
        with patch('evidence.os.link',side_effect=expire_after_link):summary=self.run(self.d,self.f,self.clock).as_dict()
        self.assertEqual(summary['completion'],'incomplete');self.assertIsNone(summary['evidence']['completion_token'])
        attempt=self.root/'out'/'fixture-00000001';m=json.loads((attempt/'failure.manifest.json').read_text())
        self.assertEqual(m['collection_state'],'incomplete');self.assertIn('collection.json',m['files'])
