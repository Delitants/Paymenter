import copy
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from control import ControlSession, ControlError, process_identity, operator_alive


class ControlTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name).resolve()
        self.manifest = {'schema_version': 1, 'purpose': 'opening-control-rehearsal-manifest',
                         'freeze_id': 'fixture-freeze', 'controls': ['source-writer', 'target-writer', 'ingress']}
        self.identity = {'pid': 1234, 'boot_id': 'fixture-boot', 'start_ticks': '123'}
        self.live = patch('control.operator_alive', return_value=True)
        self.observer = self.live.start()

    def tearDown(self):
        self.live.stop()
        self.tmp.cleanup()

    def session(self, manifest=None):
        return ControlSession(self.root, self.manifest if manifest is None else manifest, self.identity)

    def test_permits_are_rehearsal_only_and_monotonic(self):
        with self.session() as s:
            s.establish()
            first = s.renew(100)
            next_permit = s.renew(110)
            self.assertEqual(next_permit['purpose'], 'opening-control-rehearsal-permit')
            self.assertFalse(next_permit['real_execution_ready'])
            self.assertEqual(next_permit['sequence'], first['sequence'] + 1)
            self.assertFalse(s.valid(first, 111))
            self.assertTrue(s.valid(next_permit, 111))

    def test_expiry_and_backward_clock_deny_without_unfreezing(self):
        with self.session() as s:
            s.establish()
            permit = s.renew(100)
            self.assertFalse(s.valid(permit, 160))
            with self.assertRaises(ControlError):
                s.renew(99)
        self.assertEqual(len(list(self.root.glob('*.gate'))), 3)

    def test_crash_restart_retains_original_sequence_and_gates(self):
        with self.session() as s:
            s.establish()
            original = s.renew(100)
        with self.session() as resumed:
            self.assertTrue(resumed.valid(original, 101))
            fresh = resumed.renew(102)
            self.assertEqual(fresh['sequence'], 2)
            with self.assertRaises(ControlError):
                resumed.establish()
        self.assertEqual(len(list(self.root.glob('*.gate'))), 3)

    def test_lost_control_is_latched_and_never_repaired_automatically(self):
        with self.session() as s:
            s.establish()
            old = s.renew(100)
            gate = self.root / 'source-writer.gate'
            content = gate.read_bytes()
            gate.unlink()
            with self.assertRaises(ControlError):
                s.renew(101)
            gate.write_bytes(content)
            gate.chmod(0o600)
            self.assertFalse(s.valid(old, 102))
            with self.assertRaises(ControlError):
                s.renew(102)
        with self.session() as resumed:
            with self.assertRaises(ControlError):
                resumed.renew(103)

    def test_unknown_boundary_or_changed_manifest_denies(self):
        with self.session() as s:
            s.establish()
            alias = self.root / 'unknown-alias.gate'
            alias.write_bytes(b'fixture')
            alias.chmod(0o600)
            with self.assertRaises(ControlError):
                s.renew(100)
        changed = copy.deepcopy(self.manifest)
        changed['freeze_id'] = 'other-freeze'
        with self.assertRaises(ControlError):
            self.session(changed)
        for change in ({'controls': ['source-writer']}, {'extra': True}, {'purpose': 'account-opening-fence'}):
            with self.assertRaises(ControlError):
                self.session(self.manifest | change)

    def test_active_or_reused_operator_prevents_release(self):
        with self.session() as s:
            s.establish()
            with self.assertRaises(ControlError):
                s.release('RELEASE DISPOSABLE REHEARSAL')
            self.assertEqual(len(list(self.root.glob('*.gate'))), 3)
            self.observer.return_value = False
            with self.assertRaises(ControlError):
                s.release('yes')
            s.release('RELEASE DISPOSABLE REHEARSAL')
            self.assertEqual(len(list(self.root.glob('*.gate'))), 0)
            with self.assertRaises(ControlError):
                s.renew(100)

    def test_lost_operator_denies_permit_but_retains_gates(self):
        with self.session() as s:
            s.establish()
            old = s.renew(100)
            self.observer.return_value = False
            self.assertFalse(s.valid(old, 101))
            with self.assertRaises(ControlError):
                s.renew(101)
        self.assertEqual(len(list(self.root.glob('*.gate'))), 3)

    def test_exclusive_controller_and_private_state(self):
        with self.session() as s:
            s.establish()
            with self.assertRaises(ControlError):
                self.session()
            self.assertEqual((self.root / 'control-state.json').stat().st_mode & 0o777, 0o600)
            (self.root / 'ingress.gate').chmod(0o644)
            with self.assertRaises(ControlError):
                s.renew(100)

    def test_permit_tampering_and_invalid_time_bounds_deny(self):
        with self.session() as s:
            s.establish()
            p = s.renew(100)
            for change in ({'real_execution_ready': True}, {'sequence': True}, {'extra': 1}, {'expires_at': 1000}):
                self.assertFalse(s.valid(p | change, 101))
            for ttl in (0, -1, 61, True):
                with self.assertRaises(ControlError):
                    s.renew(101, ttl)

    def test_short_permit_expiry_cannot_be_extended(self):
        with self.session() as s:
            s.establish()
            p = s.renew(100, ttl=1)
            self.assertFalse(s.valid(p, 110))
            self.assertFalse(s.valid(p | {'expires_at': 160}, 110))

    def test_lock_replacement_denies_both_original_and_second_controller(self):
        with self.session() as s:
            s.establish()
            s.renew(100)
            (self.root / 'control.lock').rename(self.root / 'old.lock')
            with self.assertRaises(ControlError):
                self.session()
            with self.assertRaises(ControlError):
                s.renew(101)

    def test_interrupted_release_requires_explicit_resume_and_preserves_state(self):
        with self.session() as s:
            s.establish()
            self.observer.return_value = False
            real_unlink = Path.unlink
            def failing_unlink(path, *args, **kwargs):
                if path.name == 'source-writer.gate':
                    raise OSError('fixture release interruption')
                return real_unlink(path, *args, **kwargs)
            with patch.object(Path, 'unlink', failing_unlink):
                with self.assertRaises(OSError):
                    s.release('RELEASE DISPOSABLE REHEARSAL')
        self.assertEqual(len(list(self.root.glob('*.gate'))), 2)
        with self.session() as resumed:
            self.assertEqual(len(list(self.root.glob('*.gate'))), 2)
            with self.assertRaises(ControlError):
                resumed.renew(102)
            resumed.release('RELEASE DISPOSABLE REHEARSAL')
            self.assertEqual(len(list(self.root.glob('*.gate'))), 0)
            resumed.release('RELEASE DISPOSABLE REHEARSAL')

    @unittest.skipUnless(sys.platform == 'linux', 'Actual process identity requires Linux /proc')
    def test_actual_process_identity_pins_boot_pid_and_start_time(self):
        identity = process_identity(os.getpid())
        self.assertTrue(operator_alive(identity))
        self.assertFalse(operator_alive(identity | {'start_ticks': '0'}))
        self.assertFalse(operator_alive(identity | {'boot_id': 'other-boot'}))


if __name__ == '__main__':
    unittest.main()
