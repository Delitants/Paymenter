"""Breaks caught: forged admission, incomplete coverage and borrowed resources.

Run on root Linux: the actual anchored reader and owned RAM allocation are used.
No mock grants, socket, scheduler or manager is created.
"""
from pathlib import Path
import dataclasses
import hashlib
import importlib
import json
import os
import sys
import tempfile
import time
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))


class ContainmentAdmissionTests(unittest.TestCase):
    def setUp(self):
        try:
            self.s = importlib.import_module('containment_schema')
            self.a = importlib.import_module('containment_admission')
            self.r = importlib.import_module('containment_runtime')
        except ModuleNotFoundError:
            self.fail('closed containment admission interface is not implemented')
        self.tmp = tempfile.TemporaryDirectory(dir='/dev/shm')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.root.chmod(0o700)
        self.boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
        self.authority = self.put('authority', {
            'purpose': 'source-containment-authority', 'source_boot_id': self.boot,
            'automated_actors': ['automatic'], 'manual_actors': ['maintenance'],
            'exclusive_maintenance': True, 'refs': []})
        self.budget = self.put('budget', {
            'purpose': 'source-containment-budget', 'root_reserve': 1207959552,
            'ram_reserve': 536870912, 'staging_limit': 268435456,
            'memory_limit': 536870912, 'evidence_limit': 16777216,
            'pids_limit': 64, 'swap_limit': 0, 'full_cohort_accepted': False,
            'refs': []})
        self.profile_data = {'purpose': 'source-containment-profile',
            'source_boot_id': self.boot, 'assignments': [
                {'object_id': 'worker', 'object_sha256': 'a'*64,
                 'control_id': 'source-process', 'role': 'selected'},
                {'object_id': 'library', 'object_sha256': 'b'*64,
                 'control_id': 'source-process', 'role': 'selected'}], 'refs': []}
        self.profile = self.put('profile', self.profile_data)
        self.context = self.s.ControlContext('session', 'freeze', 1, self.boot,
            self.profile.sha256, 'c'*64, self.authority, self.budget)
        original = self.root/'original-capture'
        original.write_bytes(b'fictional original capture bytes')
        original.chmod(0o600)
        original_ref = self.s.EvidenceRef(str(original),
            hashlib.sha256(original.read_bytes()).hexdigest(), original.stat().st_size)
        self.observation_data = {'purpose': 'source-containment-observation',
            'source_boot_id': self.boot, 'observer_boot_id': self.boot,
            'end_ns': str(time.monotonic_ns()), 'objects': [
                {'object_id': 'worker', 'object_sha256': 'a'*64,
                 'dependency_ids': ['library'], 'writer_id': 'automatic'},
                {'object_id': 'library', 'object_sha256': 'b'*64,
                 'dependency_ids': [], 'writer_id': None}],
            'unresolved': [], 'original_refs': [dataclasses.asdict(original_ref)],
            'refs': []}
        self.obs = self.put('observation', self.observation_data)

    def put(self, name, value):
        value = {'schema_version': 1, **value}
        raw = json.dumps(value, sort_keys=True, separators=(',', ':')).encode()
        p = self.root/name
        p.write_bytes(raw)
        p.chmod(0o600)
        return self.s.EvidenceRef(str(p), hashlib.sha256(raw).hexdigest(), len(raw))

    def evaluate(self, observation=None):
        return self.a.evaluate_admission(self.context, (observation or self.obs,),
                                         (self.profile,))

    def test_mixed_boot_and_stale_capture_block(self):
        for fields, code in [({'source_boot_id': 'different'}, 'source-boot'),
                             ({'observer_boot_id': 'different'}, 'observer-boot'),
                             ({'end_ns': '1'}, 'observation-stale'),
                             ({'end_ns': str(time.monotonic_ns()+10**12)},
                              'observation-future')]:
            with self.subTest(code=code):
                obs = self.put('different', {**self.observation_data, **fields})
                report = self.evaluate(obs)
                self.assertIn(code, report.blockers)
                self.assertIs(report.real_execution_ready, False)

    def test_complete_graph_budget_and_substitution(self):
        old = Path(self.obs.path)
        original = old.read_bytes()
        old.write_bytes(original.replace(b'automatic', b'intruder!'))
        with self.assertRaisesRegex(self.s.ContainmentError, 'reference-hash'):
            self.evaluate()
        old.write_bytes(original)
        link = self.root/'alias'
        os.link(old, link)
        with self.assertRaisesRegex(self.s.ContainmentError, 'private-reference'):
            self.evaluate()
        link.unlink()
        child = None
        for n in range(10):
            child = self.put('chain'+str(n), {
                'purpose': 'source-containment-evidence',
                'refs': [dataclasses.asdict(child)] if child else []})
        deep = self.put('deep', {**self.observation_data,
                               'refs': [dataclasses.asdict(child)]})
        with self.assertRaisesRegex(self.s.ContainmentError, 'reference-depth'):
            self.evaluate(deep)
        excessive = self.put('excessive', {**self.observation_data,
                                          'unresolved': ['unknown']*10001})
        with self.assertRaisesRegex(self.s.ContainmentError, 'reference-budget'):
            self.evaluate(excessive)
        moved = self.root/'original-moved'
        old.rename(moved)
        old.symlink_to(moved)
        with self.assertRaisesRegex(self.s.ContainmentError, 'private-reference'):
            self.evaluate()

    def test_unknown_writer_and_missing_dependency_block(self):
        value = json.loads(json.dumps(self.observation_data))
        value['objects'][0]['writer_id'] = 'unattributed-root'
        value['objects'][0]['dependency_ids'].append('omitted-library')
        report = self.evaluate(self.put('incomplete', value))
        self.assertIn('unknown-writer', report.blockers)
        self.assertIn('missing-dependency', report.blockers)
        value['objects'].pop()
        report = self.evaluate(self.put('missing-member', value))
        self.assertIn('profile-coverage', report.blockers)

    def test_original_capture_binding_and_aggregate_bytes(self):
        original = self.root/'original-capture'
        original.write_bytes(b'changed original bytes')
        with self.assertRaisesRegex(self.s.ContainmentError, 'reference-hash'):
            self.evaluate()
        original.write_bytes(b'fictional original capture bytes')
        empty = self.put('no-original', {**self.observation_data, 'original_refs': []})
        self.assertIn('observation-original-unaccepted', self.evaluate(empty).blockers)
        refs = []
        # Actual original bytes, not a declared byte counter, cross the 32 MiB cap.
        for n in range(9):
            p = self.root/('large-original-'+str(n))
            p.write_bytes(bytes([n])*4194304)
            p.chmod(0o600)
            refs.append(dataclasses.asdict(self.s.EvidenceRef(str(p),
                hashlib.sha256(p.read_bytes()).hexdigest(), p.stat().st_size)))
        large = self.put('large', {**self.observation_data, 'original_refs': refs})
        with self.assertRaisesRegex(self.s.ContainmentError, 'reference-budget'):
            self.evaluate(large)

    def test_readonly_observation_never_accepts_controls(self):
        before = {p.name: p.read_bytes() for p in self.root.iterdir()}
        report = self.evaluate()
        self.assertEqual(report.blockers, ('full-cohort-unbudgeted',
                                          'physical-control-unaccepted'))
        self.assertIs(report.real_execution_ready, False)
        self.assertEqual(before, {p.name: p.read_bytes() for p in self.root.iterdir()})
        raw = self.s.admission_document(self.context, report)
        self.assertEqual(self.s.parse_admission(raw), report)

    def test_no_command_or_ready_override(self):
        report = self.evaluate()
        base = json.loads(self.s.admission_document(self.context, report))
        for extra in [{'command': 'touch /tmp/forbidden'},
                      {'real_execution_ready': True},
                      {'real_execution_ready': 0}, {'passed': True}]:
            with self.subTest(extra=extra):
                with self.assertRaises(self.s.ContainmentError):
                    self.s.parse_admission(json.dumps({**base, **extra}).encode())
        with self.assertRaises(self.s.ContainmentError):
            self.s.parse_admission(b'{"schema_version":1,"schema_version":1}')
        for raw in [b'{"value":NaN}', b'{"value":1.2}']:
            with self.assertRaises(self.s.ContainmentError):
                self.s.parse_admission(raw)
        with self.assertRaises(self.s.ContainmentError):
            self.s.Admission((), (), True)

    def runtime(self, **extra):
        return self.put('runtime', {
            'purpose': 'source-containment-owned-runtime', 'kind': 'sql',
            'source_boot_id': self.boot, 'adapter_manifest_sha256': 'c'*64,
            'refs': [], **extra})

    def test_owned_factory_rejects_external_socket_root_and_forged_token(self):
        for key, val in [('socket', '/run/mysqld/mysqld.sock'),
                         ('root', '/var/www'), ('command', 'true')]:
            with self.subTest(key=key):
                with self.assertRaises(self.s.ContainmentError):
                    self.r.create_owned_context('sql', self.context,
                                                self.runtime(**{key: val}))
        with self.assertRaises(self.s.ContainmentError):
            self.r.OwnedContext({'root': '/dev/shm/borrowed'})
        with self.assertRaises(self.s.ContainmentError):
            self.r.retire_owned_context(object.__new__(self.r.OwnedContext))
        for kind in ['process', 'not-an-adapter']:
            with self.assertRaises(self.s.ContainmentError):
                self.r.create_owned_context(kind, self.context, self.runtime())

    def test_owned_retirement_checks_identity(self):
        ctx = self.r.create_owned_context('sql', self.context, self.runtime())
        self.assertTrue(ctx.root.is_dir())
        self.assertEqual(ctx.root.parent, Path('/dev/shm'))
        self.assertNotEqual(ctx.root, self.root)
        # An unknown file is not owned, so retirement must not delete it.
        unknown = ctx.root/'not-owned'
        unknown.write_bytes(b'preserve')
        with self.assertRaisesRegex(self.s.ContainmentError, 'owned-drift'):
            self.r.retire_owned_context(ctx)
        self.assertEqual(unknown.read_bytes(), b'preserve')
        unknown.unlink()
        receipt = self.r.retire_owned_context(ctx)
        self.assertFalse(ctx.root.exists())
        value = json.loads(Path(receipt.path).read_bytes())
        self.assertIs(value['real_execution_ready'], False)
        self.assertEqual(value['purpose'], 'source-containment-owned-retirement')
        self.assertEqual(value['source_boot_id'], self.boot)
        with self.assertRaises(self.s.ContainmentError):
            self.r.retire_owned_context(ctx)

    def test_forked_process_cannot_borrow_owned_identity(self):
        ctx = self.r.create_owned_context('sql', self.context, self.runtime())
        self.addCleanup(lambda: self.r.retire_owned_context(ctx)
                        if ctx.root.exists() else None)
        read, write = os.pipe()
        pid = os.fork()
        if pid == 0:
            os.close(read)
            try:
                self.r._guard(ctx)
                outcome = b'accepted'
            except self.s.ContainmentError as exc:
                outcome = exc.code.encode()
            os.write(write, outcome)
            os.close(write)
            os._exit(0)
        os.close(write)
        outcome = os.read(read, 100)
        os.close(read)
        os.waitpid(pid, 0)
        self.assertEqual(outcome, b'owned-owner')


if __name__ == '__main__':
    unittest.main()
