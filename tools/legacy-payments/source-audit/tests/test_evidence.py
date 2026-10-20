import contextlib
import dataclasses
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from fixtures import FixtureClock,profile,descriptor_data,write_json,ScriptedTransport

class EvidenceTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('evidence'),'evidence behavior missing')
        from evidence import claim_attempt,seal_attempt,load_previous,summarize
        from private_io import open_private_root
        from descriptor import load_descriptor
        from query_plan import build_plan
        from collect import collect
        from analyze import compare
        from models import AuditError
        self.claim,self.seal,self.load,self.summary=claim_attempt,seal_attempt,load_previous,summarize;self.Error=AuditError
        self.stack=contextlib.ExitStack();self.addCleanup(self.stack.close)
        tmp=self.stack.enter_context(tempfile.TemporaryDirectory());self.root=Path(tmp).resolve();self.root.chmod(0o700)
        self.out=self.root/'out';self.out.mkdir(mode=0o700);self.clock=FixtureClock();self.p=profile()
        self.path=write_json(self.root/'d',descriptor_data(self.out,self.clock));self.d=load_descriptor(self.path,self.p,self.clock)
        self.private=self.stack.enter_context(open_private_root(self.out));self.c=collect(self.d,self.p,build_plan(self.d,self.p),ScriptedTransport(self.d,self.clock),self.clock);self.a=compare(self.c,None,self.d,self.p)
    def claimed(self):return self.stack.enter_context(self.claim(self.private,self.d,self.clock))
    def test_spent_attempt_even_after_failure(self):
        self.claimed()
        with self.assertRaisesRegex(self.Error,'attempt-spent'):self.claim(self.private,self.d,self.clock)
    def test_seal_roundtrip_requires_delivered_completion_token(self):
        ref=self.seal(self.claimed(),self.c,self.a,self.clock)
        self.assertEqual(ref.collection_state,'complete');self.assertEqual(len(ref.completion_token),64)
        restored=self.load(self.private,ref,self.d);self.assertEqual(restored.rows,self.c.rows)
        with self.assertRaisesRegex(self.Error,'prior-completion'):self.load(self.private,dataclasses.replace(ref,completion_token=None),self.d)
        with self.assertRaises(self.Error):self.load(self.private,dataclasses.replace(ref,completion_token='f'*64),self.d)
    def test_summary_has_no_customer_identifiers_or_row_hashes(self):
        ref=self.seal(self.claimed(),self.c,self.a,self.clock);summary=self.summary(ref,self.c,self.a).as_dict()
        self.assertEqual(summary['origin'],'fictional');self.assertEqual(summary['completion'],'complete')
        for forbidden in ['account_ids','payments:7']:
            self.assertNotIn(forbidden,json.dumps(summary))
    def test_modified_prior_artifact_refused_without_overwriting(self):
        ref=self.seal(self.claimed(),self.c,self.a,self.clock)
        p=self.out/self.d.attempt_id/'collection.json';p.write_bytes(p.read_bytes()+b' ')
        before=p.read_bytes()
        with self.assertRaises(self.Error):self.load(self.private,ref,self.d)
        self.assertEqual(p.read_bytes(),before)
    def test_final_expiry_or_rollback_prevents_success(self):
        claim=self.claimed();self.clock.advance(301)
        ref=self.seal(claim,self.c,self.a,self.clock)
        self.assertEqual(ref.collection_state,'incomplete');self.assertIsNone(ref.completion_token)
    def test_backwards_clock_prevents_success(self):
        claim=self.claimed();self.clock.advance(-2)
        ref=self.seal(claim,self.c,self.a,self.clock)
        self.assertEqual(ref.collection_state,'incomplete');self.assertIsNone(ref.completion_token)
    def test_fsync_failure_does_not_return_completion(self):
        claim=self.claimed()
        with patch('os.fsync',side_effect=OSError('private-disk-error')):
            with self.assertRaises(self.Error) as err:self.seal(claim,self.c,self.a,self.clock)
        self.assertNotIn('private-disk-error',str(err.exception))
        self.assertTrue((self.out/self.d.attempt_id).exists())
    def test_incomplete_artifacts_are_retained_not_baselines(self):
        partial=dataclasses.replace(self.c,state='incomplete',reasons=('collection-error',))
        ref=self.seal(self.claimed(),partial,None,self.clock)
        self.assertIsNone(ref.completion_token)
        with self.assertRaises(self.Error):self.load(self.private,ref,self.d)
        self.assertGreaterEqual(self.summary(ref,partial,None).as_dict()['reason_counts']['collection_incomplete'],1)
    def test_reseal_never_overwrites(self):
        claim=self.claimed();self.seal(claim,self.c,self.a,self.clock)
        before=(self.out/self.d.attempt_id/'manifest.json').read_bytes()
        with self.assertRaises(self.Error):self.seal(claim,self.c,self.a,self.clock)
        self.assertEqual((self.out/self.d.attempt_id/'manifest.json').read_bytes(),before)
    def test_root_swap_refused(self):
        claim=self.claimed();self.out.rename(self.root/'old');self.out.mkdir(mode=0o700)
        with self.assertRaises(self.Error):self.seal(claim,self.c,self.a,self.clock)
        self.assertEqual(list(self.out.iterdir()),[])
    def test_descriptor_replacement_revokes_sealing(self):
        claim=self.claimed();raw=json.loads(self.path.read_text());raw['approval_scope_ref']='changed';write_json(self.path,raw)
        ref=self.seal(claim,self.c,self.a,self.clock)
        self.assertEqual(ref.collection_state,'incomplete');self.assertIsNone(ref.completion_token)
    def test_manifest_publication_is_no_replace(self):
        claim=self.claimed();p=self.out/self.d.attempt_id/'manifest.json';p.write_text('owned-preimage');p.chmod(0o600)
        with self.assertRaises(self.Error):self.seal(claim,self.c,self.a,self.clock)
        self.assertEqual(p.read_text(),'owned-preimage')
    def test_concurrent_processes_cannot_claim_same_attempt(self):
        import time
        readfd,writefd=os.pipe();children=[]
        try:
            for _ in range(2):
                pid=os.fork()
                if pid==0:
                    os.close(writefd);os.read(readfd,1);os.close(readfd)
                    try:
                        with self.claim(self.private,self.d,self.clock):pass
                        os._exit(0)
                    except self.Error as e:os._exit(10 if e.code=='attempt-spent' else 20)
                    except BaseException:os._exit(21)
                children.append(pid)
            os.close(readfd);readfd=None;os.write(writefd,b'xx');os.close(writefd);writefd=None
            results=[];end=time.monotonic()+10
            while children and time.monotonic()<end:
                for pid in children[:]:
                    got,status=os.waitpid(pid,os.WNOHANG)
                    if got:children.remove(pid);results.append(os.waitstatus_to_exitcode(status))
                if children:time.sleep(.01)
            self.assertFalse(children,'owned claim workers must retire')
            self.assertEqual(sorted(results),[0,10])
        finally:
            if readfd is not None:os.close(readfd)
            if writefd is not None:os.close(writefd)
            for pid in children:os.kill(pid,9);os.waitpid(pid,0)
    def test_previous_descriptor_reference_cannot_mutate_in_memory(self):
        from descriptor import load_descriptor
        ref=self.seal(self.claimed(),self.c,self.a,self.clock)
        data=json.loads(self.path.read_text());data['attempt_id']='fixture-next';data['previous_ref']=dataclasses.asdict(ref);write_json(self.path,data)
        d=load_descriptor(self.path,self.p,self.clock)
        with self.assertRaises(TypeError):d.previous_ref['manifest_hash']='f'*64
    def test_disk_budget_failure_does_not_seal(self):
        claim=self.claimed()
        with patch('evidence.TOTAL_CAP',1):
            with self.assertRaisesRegex(self.Error,'evidence-cap'):self.seal(claim,self.c,self.a,self.clock)
        self.assertFalse((self.out/self.d.attempt_id/'manifest.json').exists())
