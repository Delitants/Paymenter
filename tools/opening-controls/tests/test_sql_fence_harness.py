from dataclasses import replace
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from sql_fence_schema import SqlFenceError,canonical,FixturePlan,DefinerObject
from sql_fence_driver import SqlSnapshot,snapshot_record
from sql_fence_projection import compile_projection
from sql_fence_fixtures import model,preserved,requirements
import sql_fence_harness as h

def snap(m=None):return SqlSnapshot(m or model(),(), 'a'*64,'b'*64,'c'*64)

class SqlHarnessTests(unittest.TestCase):
    def test_exact_budget_and_limit_plus_one(self):
        r={'root_free':1024**3+128*1024**2+16*1024**2,'shm_free':1024**3,'mem_available':1024**3}
        h.check_budget(r)
        for k in r:
            bad=dict(r);bad[k]-=1
            with self.assertRaises(SqlFenceError):h.check_budget(bad)
        for val in (True,1.0,-1):
            with self.assertRaises(SqlFenceError):h.check_budget(dict(r,root_free=val))
        with self.assertRaises(SqlFenceError):h.check_budget(dict(r,extra=1))
    def test_child_cannot_bootstrap_before_owned_admission(self):
        s=object.__new__(h.OwnedSupervisor);s._admitted=False
        with self.assertRaises(SqlFenceError):s.spawn(('true',),'bootstrap',False)
        with self.assertRaises(SqlFenceError):s.assert_admitted({})
    def test_controller_death_does_not_restore_grants(self):
        p=compile_projection(model(),'fixture_source',preserved(),requirements())
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);root.chmod(0o700)
            with h.OperationJournal(root,'g1') as j:
                j.intent(p.establish[0],snap())
                with patch.object(h,'snapshot',return_value=snap(p.held)),patch.object(h,'restore_original',side_effect=AssertionError('ambiguous state must not restore')):
                    self.assertEqual(h.recover(object(),snap(),p,j),'recovery-required')
    def test_intent_without_readback_never_claims_held(self):
        p=compile_projection(model(),'fixture_source',preserved(),requirements())
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);root.chmod(0o700)
            with h.OperationJournal(root,'g1') as j:
                intent=j.intent(p.establish[0],snap());self.assertEqual(len(j.verify_prefix()),1)
                j.readback(intent,snap());self.assertEqual(len(j.verify_prefix()),2)
                with self.assertRaises(SqlFenceError):j.readback(intent,snap())
            # A modified existing original must deny a fresh reader.
            path=root/'000001.json';v=json.loads(path.read_text());v['generation']='forged';path.write_bytes(canonical(v))
            with self.assertRaises(SqlFenceError):
                with h.OperationJournal(root,'g1') as j:j.verify_prefix()
    def test_competing_row_or_auth_change_denies_restore(self):
        self.assertFalse(h.restorable(snap(),replace(snap(),account_nonprivilege_sha256='d'*64)))
        self.assertFalse(h.restorable(snap(),replace(snap(),protected_sha256='e'*64)))
    def test_restore_retains_unrelated_deltas_and_exact_grant_metadata(self):
        changed=replace(snap(),unrelated_sha256='d'*64)
        self.assertTrue(h.restorable(snap(),changed))
        self.assertEqual(snapshot_record(changed)['unrelated_sha256'],'d'*64)
    def test_retirement_never_signals_or_deletes_reused_objects(self):
        class P:
            pid=42
            def poll(self):return None
            def terminate(self):raise AssertionError('must not signal reused pid')
        s=object.__new__(h.OwnedSupervisor);s.processes=[{'process':P(),'identity':{'pid':42,'boot_id':'original','start_ticks':'1'}}]
        with patch.object(h,'process_identity',return_value={'pid':42,'boot_id':'reused','start_ticks':'2'}):
            with self.assertRaises(SqlFenceError):s.stop_owned(s.processes[0]['process'])
    def test_journal_replacement_gap_fsync_and_unacknowledged_evidence(self):
        p=compile_projection(model(),'fixture_source',preserved(),requirements())
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);root.chmod(0o700)
            with h.OperationJournal(root,'g1') as j:
                with patch('os.fsync',side_effect=OSError('full')):
                    with self.assertRaises(SqlFenceError):j.intent(p.establish[0],snap())
                with self.assertRaises(SqlFenceError):j.verify_prefix()

    def test_readmission_denies_cached_sessions_even_with_matching_snapshot(self):
        s=object.__new__(h.OwnedSupervisor);s.context=object();s.clients_allowed=False;s.evidence=lambda *a:None
        with patch.object(h,'snapshot',return_value=snap()),patch.object(h,'drain_barrier',side_effect=SqlFenceError('cached')):
            with self.assertRaisesRegex(SqlFenceError,'cached'):s.set_client_admission(True,h.snapshot_hash(snap()))
        self.assertFalse(s.clients_allowed)

    def test_network_admission_uses_namespace_link_and_route_inventory(self):
        h.validate_network([{'ifname':'lo'}],[],[])
        for links,routes in (([{'ifname':'lo'},{'ifname':'eth0'}],[]),([{'ifname':'lo'}],[{'dst':'default'}])):
            with self.assertRaises(SqlFenceError):h.validate_network(links,routes,[])

    def test_native_launch_failure_is_retained_as_false_aggregate(self):
        import types
        from sql_fence_fixtures import plan_record
        docs=types.SimpleNamespace(verify_unchanged=lambda:None,plan_reference={'sha256':'a'*64})
        plan=FixturePlan(plan_record(),model(),requirements(),tuple(plan_record()['cases']))
        with tempfile.TemporaryDirectory() as td:
            root=Path(td).resolve();root.chmod(0o700)
            with patch.object(h,'_launch',side_effect=SqlFenceError('sql-admission')):
                result=h.run_rehearsal(plan,docs,root/'evidence')
            self.assertFalse(result['native_passed']);self.assertFalse(result['real_execution_ready'])
            self.assertTrue((root/'evidence/parent-failure.json').is_file())

    def test_existing_evidence_directory_is_never_written_on_admission_failure(self):
        import types
        from sql_fence_fixtures import plan_record
        docs=types.SimpleNamespace(verify_unchanged=lambda:None,plan_reference={'sha256':'a'*64})
        with tempfile.TemporaryDirectory() as td:
            root=Path(td).resolve();root.chmod(0o700)
            with patch.object(h,'_launch',side_effect=SqlFenceError('sql-evidence-exclusive')):
                with self.assertRaises(SqlFenceError):h.run_rehearsal(FixturePlan(plan_record(),model(),requirements(),tuple(plan_record()['cases'])),docs,root)
            self.assertEqual(list(root.iterdir()),[])

    def test_repeated_native_evidence_keeps_original_once_and_durable_events(self):
        import time
        with tempfile.TemporaryDirectory() as td:
            root=Path(td).resolve();root.chmod(0o700)
            s=object.__new__(h.OwnedSupervisor);s.counter=0;s.blobs={};s.deadline=time.monotonic()+10;s.evidence_root=root;s.events=None;s.events_identity=None;s.events_bytes=b''
            s.evidence('attempt',{'counter':1});s.evidence('attempt',{'counter':1})
            rows=[json.loads(x) for x in (root/'events.jsonl').read_text().splitlines()]
            self.assertEqual(len(rows),2);self.assertEqual(rows[0]['original'],rows[1]['original'])
            self.assertEqual(len(list(root.glob('*attempt.json'))),1)
            if s.events is not None:s.events.close()

    def test_unsafe_seed_is_rejected_before_any_native_launch_or_evidence_write(self):
        import types
        from sql_fence_fixtures import plan_record
        bad=replace(model(),definitions=(DefinerObject('fixture_web','view',('fx_admin','localhost'),'0'*64),))
        plan=FixturePlan(plan_record(),bad,requirements(),tuple(plan_record()['cases']))
        docs=types.SimpleNamespace(verify_unchanged=lambda:None,plan_reference={'sha256':'a'*64})
        with tempfile.TemporaryDirectory() as td:
            root=Path(td).resolve();root.chmod(0o700)
            with patch.object(h,'_launch',side_effect=AssertionError('unsafe input reached native launch')):
                with self.assertRaises(SqlFenceError):h.run_rehearsal(plan,docs,root/'evidence')
            self.assertFalse((root/'evidence').exists())
