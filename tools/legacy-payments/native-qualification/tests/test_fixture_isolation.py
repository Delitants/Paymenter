"""Offline identity/refusal models only; no native proof or side effects."""
import dataclasses
from pathlib import Path
import sys
import time
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
try:
    import fixture_isolation as api
except ModuleNotFoundError as exc:
    if exc.name != 'fixture_isolation':
        raise
    api = None


class FixtureIsolationTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(api, 'offline fixture profile boundary is not implemented')
        self.now = time.monotonic_ns()
        self.profile = api.FixtureProfile(
            'paymenter-fixture-isolation-offline-v1', 'a'*64, 'pmqa'+'a'*28,
            'b'*64, (api.ImagePin('/fictional/fixture', '1:2:3', 'c'*64, 'd'*64),),
            'authored-config-v0', self.now+10**9)

    def test_module_name_and_full_run(self):
        self.assertEqual('pmqa'+'a'*28, api.module_name('a'*64))
        self.assertEqual(32, len(api.module_name('a'*64)))
        for run in ('A'*64, 'a'*63, 'a'*65, 'g'*64, '', 12):
            with self.subTest(run=run), self.assertRaises(api.IsolationError):
                api.module_name(run)
        api.validate_profile(self.profile, self.now, frozenset())

    def test_prefix_collision_and_identity_replacement_refuse(self):
        other = dataclasses.replace(self.profile, run='a'*28+'e'*36)
        with self.assertRaises(api.IsolationError):
            api.validate_profile(other, self.now, frozenset({'pmqa'+'a'*28}))
        for field, value in (('identity', 'different'), ('sha256', 'e'*64),
                             ('ancestors_sha256', 'f'*64), ('path', '/fictional/replaced')):
            image = dataclasses.replace(self.profile.images[0], **{field:value})
            actual = dataclasses.replace(self.profile, images=(image,))
            with self.subTest(field=field), self.assertRaises(api.IsolationError):
                api.validate_profile_binding(actual, self.profile, self.now, frozenset())
        with self.assertRaises(api.IsolationError):
            api.validate_profile_binding(other, self.profile, self.now, frozenset())
        api.validate_profile_binding(self.profile, self.profile, self.now, frozenset())

    def test_unhashable_evidence_kind_has_fixed_refusal(self):
        for kind in ([],{}):
            reference=api.EvidenceRef(kind,'e'*64,'a'*64,'b'*64)
            for name,call in (
                ('gate',lambda:api.offline_native_gate(api.NativeGate.N0,self.profile,(reference,))),
                ('lifecycle',lambda:api.advance(api.Lifecycle(api.FixtureState.ABSENT,'a'*64,()),'reserve-intent',reference))):
                with self.subTest(kind=kind,consumer=name):
                    try:
                        call()
                    except Exception as exc:
                        self.assertIs(type(exc),api.IsolationError)
                        self.assertEqual('fixture-evidence-reference',str(exc))
                    else:
                        self.fail('malformed evidence was accepted')

    def test_malformed_expired_and_mutable_profiles_refuse(self):
        changes = ({'expires_monotonic_ns':self.now}, {'version':'native-v1'},
                   {'protocol':'vendor-config'}, {'module':'production'},
                   {'registry_sha256':'B'*64}, {'images':()},
                   {'images':self.profile.images*2}, {'images':list(self.profile.images)},
                   {'expires_monotonic_ns':True})
        for change in changes:
            with self.subTest(change=change), self.assertRaises(api.IsolationError):
                api.validate_profile(dataclasses.replace(self.profile, **change), self.now, frozenset())
        with self.assertRaises(dataclasses.FrozenInstanceError):
            self.profile.module = 'different'
        for path in ('relative', '/a//b', '/a/../b', '/a/./b', '/a/', '/a b', '/a\\b'):
            image = dataclasses.replace(self.profile.images[0], path=path)
            with self.subTest(path=path), self.assertRaises(api.IsolationError):
                api.validate_profile(dataclasses.replace(self.profile, images=(image,)), self.now, frozenset())
        metadata = api.ImagePin('/fictional/metadata', '4:5:6', 'e'*64, 'f'*64)
        api.validate_profile(dataclasses.replace(self.profile, images=self.profile.images+(metadata,)), self.now, frozenset())
        with self.assertRaises(api.IsolationError):
            api.validate_profile(dataclasses.replace(self.profile, images=self.profile.images+(metadata,metadata)), self.now, frozenset())

    def test_offline_success_cannot_enable_native_gate(self):
        kinds = ('launcher','config','filter','fallback','creation','financial-read',
                 'notification-direct','notification-queue','retirement')
        refs = tuple(api.EvidenceRef(k, 'e'*64, 'a'*64, 'b'*64) for k in kinds)
        for gate in api.NativeGate:
            result = api.offline_native_gate(gate, self.profile, refs)
            self.assertFalse(result.allowed)
            self.assertIn('native-evidence-verifier-absent', result.missing)
            self.assertFalse(api.offline_native_gate(gate, self.profile, ()).allowed)
        for change in ({'kind':'unknown'}, {'run':'f'*64}, {'registry_sha256':'f'*64}, {'sha256':'invalid'}):
            with self.subTest(change=change), self.assertRaises(api.IsolationError):
                api.offline_native_gate(api.NativeGate.N0, self.profile,
                    (dataclasses.replace(refs[0], **change),))
        with self.assertRaises(api.IsolationError):
            api.offline_native_gate('N0', self.profile, refs)


class FixtureLifecycleTests(unittest.TestCase):
    def setUp(self):
        FixtureIsolationTests.setUp(self)
        self.assertTrue(hasattr(api,'Lifecycle'),'forward-only fixture lifecycle is not implemented')

    def ref(self,kind='creation',digest='e'):
        return api.EvidenceRef(kind,digest*64,'a'*64,'b'*64)

    def initial(self):
        return api.Lifecycle(api.FixtureState.ABSENT,'a'*64,())

    def intent(self):
        return api.advance(self.initial(),'reserve-intent',self.ref())

    def test_commit_then_lost_result_stays_unknown(self):
        intent=self.intent()
        unknown=api.advance(intent,'response-lost',self.ref(digest='f'))
        self.assertEqual(api.FixtureState.OUTCOME_UNKNOWN,unknown.state)
        self.assertEqual(2,len(unknown.original_refs))
        for event in ('reserve-intent','creation-result','creation-error','response-lost','retry'):
            with self.subTest(event=event), self.assertRaises(api.IsolationError):
                api.advance(unknown,event,self.ref(digest='1'))
        observed=api.advance(unknown,'independent-readback',self.ref('financial-read','1'))
        self.assertEqual(api.FixtureState.OUTCOME_UNKNOWN,observed.state)
        self.assertEqual(unknown.original_refs,observed.original_refs[:2])
        self.assertEqual(3,len(observed.original_refs))

    def test_originals_and_partial_failure_preserved(self):
        intent=self.intent()
        error=api.advance(intent,'creation-error',self.ref(digest='f'))
        self.assertEqual(api.FixtureState.OUTCOME_UNKNOWN,error.state)
        known=api.advance(intent,'creation-result',self.ref(digest='2'))
        self.assertEqual(api.FixtureState.OUTCOME_KNOWN,known.state)
        readback=api.advance(known,'independent-readback',self.ref('financial-read','3'))
        self.assertEqual(api.FixtureState.READBACK_BOUND,readback.state)
        self.assertEqual(intent.original_refs,readback.original_refs[:1])
        for reference in (self.ref(),dataclasses.replace(self.ref(digest='4'),run='f'*64),
                dataclasses.replace(self.ref(digest='4'),registry_sha256='f'*64),
                self.ref('unknown','4')):
            with self.subTest(reference=reference), self.assertRaises(api.IsolationError):
                api.advance(intent,'creation-result',reference)
        for event in ('isolation-qualified','repair-balance','rollback','delete','refund'):
            with self.subTest(event=event), self.assertRaises(api.IsolationError):
                api.advance(readback,event,self.ref('retirement','4'))
        self.assertEqual(1,len(intent.original_refs))

    def test_duplicate_config_and_reload_slots(self):
        budget=api.InvocationBudget()
        cold=api.consume_slot(budget,'cold-config')
        done=api.consume_slot(cold,'post-commit-reload')
        self.assertEqual((False,False,False),(budget.cold_config,budget.post_commit_reload,budget.failed))
        self.assertEqual((True,True,False),(done.cold_config,done.post_commit_reload,done.failed))
        for slot in ('cold-config','post-commit-reload','retry','other'):
            with self.subTest(slot=slot), self.assertRaises(api.IsolationError):
                api.consume_slot(done,slot)
        for slot in ('cold-config','post-commit-reload'):
            with self.subTest(slot=slot), self.assertRaises(api.IsolationError):
                api.consume_slot(api.fail_budget(budget),slot)
        with self.assertRaises(api.IsolationError):
            api.consume_slot(budget,'post-commit-reload')

    def test_offline_success_cannot_enable_native_gate(self):
        known=api.advance(self.intent(),'creation-result',self.ref(digest='f'))
        readback=api.advance(known,'independent-readback',self.ref('financial-read','1'))
        self.assertNotEqual(api.FixtureState.ISOLATION_QUALIFIED,readback.state)
        refs=tuple(self.ref(k,str(i)) for i,k in enumerate(('launcher','config','filter','creation','financial-read')))
        for gate in api.NativeGate:
            self.assertFalse(api.offline_native_gate(gate,self.profile,refs).allowed)
        # A forged terminal state cannot be advanced or used to produce a grant.
        with self.assertRaises(api.IsolationError):
            api.advance(dataclasses.replace(readback,state=api.FixtureState.ISOLATION_QUALIFIED),
                        'independent-readback',self.ref('financial-read','2'))

    def test_referenced_inert_image_is_retained(self):
        for paid in (False,True):
            for referenced in (False,True):
                with self.subTest(paid=paid,referenced=referenced):
                    result=api.retention_decision(paid,referenced)
                    self.assertTrue(result.retain_evidence)
                    self.assertEqual(paid,result.retain_audit)
                    self.assertEqual(referenced,result.retain_inert_image)
                    self.assertFalse(result.may_delete_or_refund)
