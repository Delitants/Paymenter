"""Actual native source-shaped qualification; unavailable runtime is a failure.

One owned invocation covers all seven cases. Original private attempt records,
not mocked replies or a configuration digest, establish the measurements.
"""
from pathlib import Path
import importlib
import hashlib
import os
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from containment_schema import EvidenceRef
from containment_schema import ContainmentError


class SourceRetirementReferenceTests(unittest.TestCase):
    def test_original_retirement_rejects_leaf_and_ancestor_substitution(self):
        adapter = importlib.import_module('source_sql_adapter')
        self.assertTrue(callable(getattr(adapter, '_validate_retirement', None)),
                        'retirement evidence validator is not implemented')
        with tempfile.TemporaryDirectory(dir='/dev/shm') as temp:
            root = Path(temp); root.chmod(0o700)
            path = root/'retirement.json'; raw = b'{"owned_operator_retired":true}'
            path.write_bytes(raw); path.chmod(0o600)
            expected = EvidenceRef(str(path), hashlib.sha256(raw).hexdigest(), len(raw))
            operator = subprocess.Popen([sys.executable, '-I', '-c', 'pass'])
            operator.wait(timeout=5)
            identity = (path.stat().st_dev, path.stat().st_ino)
            state = {'operator': operator, 'retirement': expected,
                'retirement_identity': identity,
                'driver': SimpleNamespace(supervisor=SimpleNamespace(evidence_root=root))}
            adapter._validate_retirement(state, expected)
            original = root/'preimage'
            path.rename(original); path.symlink_to(original)
            with self.assertRaisesRegex(ContainmentError, 'private-reference'):
                adapter._validate_retirement(state, expected)
            path.unlink(); original.rename(path)
            os.link(path, original)
            with self.assertRaisesRegex(ContainmentError, 'private-reference'):
                adapter._validate_retirement(state, expected)
            original.unlink()
            path.write_bytes(b'changed')
            with self.assertRaisesRegex(ContainmentError, 'reference-hash'):
                adapter._validate_retirement(state, expected)
            path.write_bytes(raw)
            path.rename(original); path.write_bytes(raw); path.chmod(0o600)
            with self.assertRaisesRegex(ContainmentError, 'reference-substitution'):
                adapter._validate_retirement(state, expected)
            path.unlink(); original.rename(path)
            root.chmod(0o755)
            with self.assertRaisesRegex(ContainmentError, 'private-reference'):
                adapter._validate_retirement(state, expected)
            root.chmod(0o700)
            sleeper = subprocess.Popen([sys.executable, '-I', '-c',
                                        'import time; time.sleep(30)'])
            try:
                state['operator'] = sleeper
                with self.assertRaisesRegex(ContainmentError, 'sql-operator-not-retired'):
                    adapter._validate_retirement(state, expected)
            finally:
                sleeper.terminate(); sleeper.wait(timeout=5)


class SourceSqlAdapterTests(unittest.TestCase):
    result = None
    failure = None

    def setUp(self):
        try:
            self.adapter = importlib.import_module('source_sql_adapter')
            self.native = importlib.import_module('source_sql_native')
        except ModuleNotFoundError:
            self.fail('owned source SQL adapter interface is not implemented')
        if type(self).failure is not None:
            self.fail(type(self).failure)
        if type(self).result is None:
            # Explicit private runtime locations are accepted archives only;
            # no input accepts an existing SQL socket, datadir or live server.
            archive = os.environ.get('SOURCE_CONTAINMENT_RUNTIME_ARCHIVE')
            manifest = os.environ.get('SOURCE_CONTAINMENT_RUNTIME_MANIFEST')
            evidence = os.environ.get('SOURCE_CONTAINMENT_EVIDENCE')
            archive_sha = os.environ.get('SOURCE_CONTAINMENT_RUNTIME_ARCHIVE_SHA256')
            manifest_sha = os.environ.get('SOURCE_CONTAINMENT_RUNTIME_MANIFEST_SHA256')
            self.assertTrue(archive and manifest and evidence and archive_sha and manifest_sha,
                            'native source-shaped qualification runtime unavailable')
            try:
                type(self).result = self.native.run_owned_qualification(
                    EvidenceRef(archive, archive_sha, Path(archive).stat().st_size),
                    EvidenceRef(manifest, manifest_sha, Path(manifest).stat().st_size),
                    Path(evidence))
            except Exception as exc:
                type(self).failure = getattr(exc, 'code', type(exc).__name__)
                raise
        self.assertIs(type(self).result['real_execution_ready'], False)
        self.assertIs(type(self).result['retirement']['complete'], True)

    def case(self, name):
        value = type(self).result['cases'][name]
        self.assertTrue(value['attempt_refs'])
        return value

    def test_source_shaped_proxy_and_precedence(self):
        v = self.case('proxy-precedence')
        self.assertEqual(v['principals'], 7)
        self.assertEqual(v['proxies'], 2)
        self.assertEqual(v['authenticated_tcp'], 2)
        self.assertTrue(v['manual_proxy_rows_unchanged'])
        self.assertTrue(v['protected_unchanged'])

    def test_cached_session_requires_owned_idle_reconnect(self):
        v = self.case('cached-session')
        self.assertTrue(v['cached_authorized'])
        self.assertTrue(v['fresh_denied'])
        self.assertTrue(v['held_denied_until_drained'])
        self.assertEqual(v['closed_owned_idle'], 1)

    def test_active_or_unowned_session_blocks_without_kill(self):
        v = self.case('active-unowned')
        self.assertEqual(v['blocked_active'], 1)
        self.assertEqual(v['blocked_unowned'], 1)
        self.assertEqual(v['killed_sessions'], 0)
        self.assertTrue(v['grants_unchanged'])

    def test_seven_grant_tables_check_rows_before_readmission(self):
        v = self.case('grant-integrity')
        self.assertEqual(v['checked_tables'], 7)
        self.assertTrue(v['actual_status_rows_ok'])
        self.assertTrue(v['fresh_denial_observed'])

    def test_keeper_loss_primary_denial_persists(self):
        v = self.case('keeper-loss')
        self.assertEqual(v['locked_tables'], 300)
        self.assertTrue(v['transaction_released_locks_detected'])
        self.assertTrue(v['keeper_exit_detected'])
        self.assertTrue(v['primary_denial_persisted'])

    def test_partial_grant_restore_preserves_other_schema_deltas(self):
        v = self.case('exact-restore')
        self.assertTrue(v['live_operator_release_denied'])
        self.assertTrue(v['competing_grant_restore_denied'])
        self.assertTrue(v['positive_release_completed'])
        self.assertIn('partial_operations_restored', v,
                      'native restoration must include an actual acknowledged partial prefix')
        self.assertGreater(v['partial_operations_restored'], 0)
        self.assertLess(v['partial_operations_restored'], v['full_establishment_operations'])
        self.assertTrue(v['retirement_substitution_denied'])
        self.assertTrue(v['all_grant_rows_restored'])
        self.assertTrue(v['authentication_unchanged'])
        self.assertTrue(v['unrelated_deltas_retained'])
        self.assertTrue(v['protected_unchanged'])

    def test_restart_and_unacknowledged_operation_recovery(self):
        v = self.case('restart-recovery')
        self.assertEqual(v['restarts'], 2)
        self.assertTrue(v['physical_integrity_before_readmission'])
        self.assertTrue(v['unacknowledged_native_operation_observed'])
        self.assertEqual(v['recovered_state'], 'recovery-required')
        self.assertTrue(v['no_automatic_restore'])


if __name__ == '__main__': unittest.main()
