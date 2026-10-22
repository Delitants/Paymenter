"""Closed pure runtime preflight; no manager, service or guard is executed."""
from pathlib import Path
import copy
import importlib
import sys
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from containment_schema import (ControlContext, EvidenceRef, ContainmentError,
    canonical, digest)
from dataclasses import asdict
from containment_runtime import OwnedContext


def context():
    return ControlContext('fictional', 'freeze', 1, 'fictional-boot', 'a'*64,
        'b'*64, EvidenceRef('/private/authority', 'c'*64, 1),
        EvidenceRef('/private/budget', 'd'*64, 1))


def panel_fixture(ctx):
    return {'schema_version': 1, 'purpose': 'source-containment-panel-preflight',
        'context_sha256': digest(canonical(asdict(ctx))), 'runtime_id': 'fictional-runtime',
        'artifacts': [{'object_id': role, 'role': role, 'sha256': 'e'*64} for role in
                      ('manager', 'wrapper', 'configuration', 'library', 'kernel',
                       'scheduler', 'service-manager')],
        'launchers': [{'object_id': 'selected-'+kind, 'kind': kind,
                      'role': 'selected', 'guard_sha256': 'f'*64} for kind in
                     ('service', 'alias', 'direct-cli', 'manual', 'timer', 'boot', 'socket')],
        'unresolved': []}


class StartPreflightTests(unittest.TestCase):
    def setUp(self):
        try: self.a = importlib.import_module('source_start_adapter')
        except ModuleNotFoundError: self.fail('panel runtime preflight is not implemented')
        self.ctx = context(); self.value = panel_fixture(self.ctx)

    def inspect(self, value=None):
        return self.a.inspect_panel_runtime(canonical(self.value if value is None else value), self.ctx)

    def test_even_complete_claims_do_not_qualify_manager(self):
        result = self.inspect()
        self.assertIn('panel-native-runtime-unqualified', result.blockers)
        self.assertIn('panel-installed-behavior-unaccepted', result.blockers)
        self.assertFalse(result.real_execution_ready)
        self.assertEqual(result.observation_refs, ())

    def test_missing_guard_and_alternate_launchers_block(self):
        for index in range(len(self.value['launchers'])):
            value = copy.deepcopy(self.value)
            value['launchers'][index]['guard_sha256'] = None
            with self.subTest(index=index):
                self.assertIn('panel-start-guard-unproved', self.inspect(value).blockers)
        self.value['launchers'].pop()
        self.assertIn('panel-launch-coverage', self.inspect().blockers)

    def test_shared_core_and_unrelated_panel_not_admitted(self):
        self.value['launchers'][0]['role'] = 'shared'
        self.assertIn('panel-shared-launcher', self.inspect().blockers)
        self.value['launchers'][0]['role'] = 'unrelated'
        self.assertIn('panel-unrelated-control', self.inspect().blockers)
        self.value['launchers'][0]['guard_sha256'] = None
        self.assertNotIn('panel-unrelated-control', self.inspect().blockers)

    def test_unresolved_boot_order_remains_explicit(self):
        self.value['unresolved'] = ['guard-before-dispatch-unproved']
        result = self.inspect()
        self.assertIn('guard-before-dispatch-unproved', result.blockers)
        self.assertIn('panel-boot-order-unaccepted', result.blockers)

    def test_artifact_gaps_context_drift_and_unknown_fields_reject(self):
        self.value['artifacts'].pop()
        self.assertIn('panel-artifact-coverage', self.inspect().blockers)
        for mutate in (lambda v: v.update(command='mgrctl -l'),
                       lambda v: v.update(context_sha256='0'*64),
                       lambda v: v['artifacts'].append(v['artifacts'][0]),
                       lambda v: v['launchers'].append(v['launchers'][0]),
                       lambda v: v.update(schema_version=True)):
            value = panel_fixture(self.ctx); mutate(value)
            with self.subTest(value=value):
                with self.assertRaises(ContainmentError): self.inspect(value)

    def test_no_physical_api_opens_runtime_or_executes_manager(self):
        ctx = object.__new__(OwnedContext)
        ref = EvidenceRef('/does/not/exist/must-not-open', 'a'*64, 9)
        for call in (lambda: self.a.qualify_panel_runtime(ctx, ref),
                     lambda: self.a.establish_start(ctx), lambda: self.a.read_start(ctx),
                     lambda: self.a.release_start(ctx, ref)):
            with self.assertRaisesRegex(ContainmentError, '^panel-native-runtime-unqualified$'):
                call()


if __name__ == '__main__': unittest.main()
