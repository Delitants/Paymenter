"""Authored policy references never establish native prevention or authority."""
from dataclasses import FrozenInstanceError, replace
from enum import Enum
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import fixture_isolation as old
try:
    import fixture_notification_policy as api
except ModuleNotFoundError as exc:
    if exc.name != 'fixture_notification_policy':
        raise
    api = None


class PolicyTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(api, 'offline diagnostic policy is not implemented')
        self.profile = api.PolicyProfile(
            'paymenter-fixture-policy-offline-v2', 'authored-vendor-config-candidate-v1',
            api.DiagnosticPolicy.EXISTING_ADMIN_PROBLEMS_ALLOWED,
            'a'*64, 'pmqa'+'a'*28, 'b'*64, 'c'*64,
            (old.ImagePin('/fictional/fixture', '1:2:3', 'd'*64, 'e'*64),), 10**30)
        self.refs = tuple(api.PolicyEvidenceRef(kind, digest*64, 'a'*64, 'b'*64, 'c'*64)
                          for kind, digest in zip(api.PolicyEvidenceKind, 'def'))

    def refuse(self, code, call):
        try:
            call()
        except Exception as exc:
            self.assertIs(type(exc), old.IsolationError)
            self.assertEqual(code, str(exc))
        else:
            self.fail('malformed policy input accepted')

    def check_profile(self, profile, now=0, names=frozenset()):
        return api.validate_policy_profile(profile, now, names)

    def gate(self, refs, profile=None, gate=old.NativeGate.N0):
        return api.offline_policy_gate(gate, self.profile if profile is None else profile, refs)

    def test_complete_policy_evidence_still_denies_all_native_gates(self):
        self.check_profile(self.profile)
        second = old.ImagePin('/fictional/metadata', '4:5:6', 'e'*64, 'f'*64)
        self.check_profile(replace(self.profile, images=self.profile.images+(second,)))
        for gate in old.NativeGate:
            for refs in ((), self.refs[:1], self.refs):
                with self.subTest(gate=gate, refs=len(refs)):
                    result = self.gate(refs, gate=gate)
                    self.assertIs(type(result), old.AdmissionDecision)
                    self.assertIs(result.allowed, False)
                    self.assertEqual(('native-evidence-verifier-absent',), result.missing)
        for value in (self.profile, self.refs[0]):
            with self.assertRaises(FrozenInstanceError):
                value.run = 'f'*64

    def test_policy_generation_and_foreign_references_refuse(self):
        changes = ({'run': 'a'*28+'f'*36}, {'registry_sha256': 'f'*64},
                   {'generation': 'f'*64}, {'sha256': 'F'*64}, {'sha256': 'f'*63})
        for change in changes:
            with self.subTest(change=change):
                mixed = (replace(self.refs[0], **change),)+self.refs[1:]
                self.refuse('fixture-policy-evidence', lambda: self.gate(mixed))
        bad = (self.refs+(self.refs[0],), (self.refs[0], self.refs[0]),
               (self.refs[0], replace(self.refs[1], sha256=self.refs[0].sha256)))
        for refs in bad:
            with self.subTest(refs=refs):
                self.refuse('fixture-policy-evidence', lambda: self.gate(refs))
        other = replace(self.profile, run='a'*28+'f'*36)
        self.check_profile(other)
        self.refuse('fixture-policy-evidence', lambda: self.gate(self.refs, other))
        self.refuse('fixture-policy-profile',
                    lambda: self.check_profile(other, names=frozenset({other.module})))

    def test_malformed_policy_inputs_have_fixed_refusals(self):
        v1 = old.FixtureProfile('paymenter-fixture-isolation-offline-v1', 'a'*64,
            'pmqa'+'a'*28, 'b'*64, self.profile.images, 'authored-config-v0', 10**30)
        for profile in (v1, {}, [], None):
            self.refuse('fixture-policy-profile', lambda: self.check_profile(profile))
        class OtherPolicy(Enum):
            OTHER = api.DiagnosticPolicy.EXISTING_ADMIN_PROBLEMS_ALLOWED.value
        changes = [
            {'policy': self.profile.policy.value}, {'policy': OtherPolicy.OTHER},
            {'policy': 'unknown'}, {'policy': []}, {'expires_monotonic_ns': True},
            {'expires_monotonic_ns': 0}, {'images': ()}, {'images': []},
            {'images': self.profile.images*2}, {'images': self.profile.images*3},
            {'images': ({},)}, {'module': []}, {'module': 'other'},
        ]
        for field in ('run', 'registry_sha256', 'generation'):
            changes += [{field: value} for value in ('A'*64, 'a'*63, [], None)]
        for change in changes:
            with self.subTest(change=change):
                self.refuse('fixture-policy-profile',
                            lambda: self.check_profile(replace(self.profile, **change)))
        for now in (True, -1, 0.5, None, 10**30):
            self.refuse('fixture-policy-profile', lambda: self.check_profile(self.profile, now))
        for names in ([], set(), frozenset({1})):
            self.refuse('fixture-policy-profile',
                        lambda: self.check_profile(self.profile, names=names))
        image = self.profile.images[0]
        image_changes = [{'path': p} for p in (
            'relative', '/', '/a//b', '/a/../b', '/a/./b', '/a/', '/a b',
            '/a\\b', '/a\nb', '/é', '/'+('x'*256), '/'+('a/'*2050)+'b', None)]
        image_changes += [{'identity': v} for v in ('', 'x'*513, 'a\nb', [], None)]
        image_changes += [{field: value} for field in ('sha256', 'ancestors_sha256')
                          for value in ('A'*64, 'a'*63, [], None)]
        for change in image_changes:
            with self.subTest(image_change=change):
                p = replace(self.profile, images=(replace(image, **change),))
                self.refuse('fixture-policy-profile', lambda: self.check_profile(p))
        v1ref = old.EvidenceRef('notification-direct', 'd'*64, 'a'*64, 'b'*64)
        for refs in ([], {}, None, (v1ref,), ({},), ([],)):
            self.refuse('fixture-policy-evidence', lambda: self.gate(refs))
        for kind in ([], {}, 'customer-direct-prevention', 'unknown', OtherPolicy.OTHER):
            self.refuse('fixture-policy-evidence',
                        lambda: self.gate((replace(self.refs[0], kind=kind),)))
        for field in ('sha256', 'run', 'registry_sha256', 'generation'):
            for value in ([], None, 'A'*64, 'a'*63):
                self.refuse('fixture-policy-evidence',
                            lambda: self.gate((replace(self.refs[0], **{field: value}),)))
        for gate in ('N0', [], None):
            self.refuse('fixture-policy-gate', lambda: self.gate((), gate=gate))

    def test_config_and_policy_versions_do_not_downgrade(self):
        for change in ({'version': 'paymenter-fixture-isolation-offline-v1'},
                       {'protocol': 'authored-config-v0'}, {'policy': object()}):
            self.refuse('fixture-policy-profile',
                        lambda: self.check_profile(replace(self.profile, **change)))
        self.refuse('fixture-profile',
                    lambda: old.validate_profile(self.profile, 0, frozenset()))
