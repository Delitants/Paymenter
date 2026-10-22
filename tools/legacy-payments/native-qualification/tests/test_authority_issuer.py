"""Real custody/publish tests with fictional boot and test-user image ownership."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
try:
    import authority_issuer as api
except ModuleNotFoundError as exc:
    if exc.name != 'authority_issuer':
        raise
    api = None

BOOT = '11111111-1111-1111-1111-111111111111'
RUN = 'a' * 64


def encode(value):
    return json.dumps(value, separators=(',', ':')).encode()


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


class IssuerFixture(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(api, 'protected native authority issuer is not implemented')
        self.temp = tempfile.TemporaryDirectory(prefix='issuer-fixture-', dir=HERE)
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.directory = self.root / 'operation'
        self.directory.mkdir(mode=0o700)
        self.clock = 2000000000
        self.images = {}
        for name in ('module.so', 'runtime'):
            path = self.root / name
            raw = b'\x7fELFfictional image, never executed: ' + name.encode()
            path.write_bytes(raw)
            path.chmod(0o500)
            self.images[name] = dict(path=str(path), sha256=sha(raw), bytes=len(raw))
        self.build = dict(purpose='source-framework-control-build-v1', run=RUN,
                          module=self.images['module.so'], runtime=self.images['runtime'])
        self.registry = dict(purpose='source-framework-control-registry-v1', run=RUN,
            tables=['paymenter_qa_business_v1', 'paymenter_qa_journal_v1'], objects=[],
            diagnostics=['writer.F1.original', 'writer.F2.original',
                         'writer.F3.original', 'writer.F4.original'])
        self.approval = dict(purpose='source-framework-authority-approval-v1',
            intent='issue-framework-authority', run=RUN, boot=BOOT, actor='fictional-admin',
            issued=self.clock - 1, expires=self.clock + 300,
            registry_sha256=sha(encode(self.registry)), build_sha256=sha(encode(self.build)),
            cases={f'F{n}': dict(nonce=str(n) * 64, facts_hash='c' * 64) for n in range(1, 5)})
        self.save()

    def put(self, name, raw):
        path = self.directory / name
        path.write_bytes(raw)
        path.chmod(0o600)

    def save(self):
        for name, value in [('registry.original', self.registry), ('build.original', self.build)]:
            self.put(name, encode(value))
        self.approval['registry_sha256'] = sha(encode(self.registry))
        self.approval['build_sha256'] = sha(encode(self.build))
        self.put('approval.original', encode(self.approval))

    def issue(self):
        with api._Store(str(self.directory), os.getuid(), os.getgid()) as store:
            return api._issue(store, BOOT, lambda: self.clock)

    def refused(self, code=None):
        with self.assertRaises(api.AdmissionError) as caught:
            self.issue()
        if code:
            self.assertEqual(code, str(caught.exception))
        self.assertFalse((self.directory / 'authority.v1').exists())


class AuthorityIssuerTests(IssuerFixture):
    def test_publishes_ordered_bridge_control_with_bound_originals_and_no_capability(self):
        receipt = self.issue()
        raw = (self.directory / 'authority.v1').read_bytes()
        lines = raw.decode().splitlines()
        self.assertEqual('paymenter-source-qa-authority-v1', lines[0])
        self.assertEqual(['intent', 'run', 'boot', 'actor_hex', 'issued', 'expires',
                          'module_path', 'module_sha256', 'runtime_path', 'runtime_sha256',
                          'registry_sha256', 'build_sha256', 'nonce_F1', 'facts_F1',
                          'nonce_F2', 'facts_F2', 'nonce_F3', 'facts_F3', 'nonce_F4', 'facts_F4'],
                         [line.split('=', 1)[0] for line in lines[1:]])
        self.assertIn('intent=framework-qa-execute', lines)
        self.assertIn('actor_hex=66696374696f6e616c2d61646d696e', lines)
        self.assertIn('module_sha256=' + self.images['module.so']['sha256'], lines)
        self.assertIn('registry_sha256=' + sha(encode(self.registry)), lines)
        self.assertEqual(sha(raw), receipt.authority_sha256)
        self.assertEqual(RUN, receipt.run_id)
        self.assertEqual(0o600, (self.directory / 'authority.v1').stat().st_mode & 0o7777)
        self.assertTrue((self.directory / 'issuance.original').exists())
        self.assertFalse((self.directory / 'revoked.v1').exists())

    def test_prepare_only_cannot_create_execution_authority(self):
        self.approval['intent'] = 'prepare-only'
        self.save()
        self.refused('issuer-approval')

    def test_unknown_approval_and_duplicate_json_fields_refuse(self):
        self.approval['reviewed'] = True
        self.save()
        self.refused()
        self.save()
        raw = (self.directory / 'approval.original').read_bytes()
        self.put('approval.original', raw[:-1] + b',"run":"' + RUN.encode() + b'"}')
        self.refused('duplicate-key')

    def test_boot_mismatch_refuses(self):
        self.approval['boot'] = '22222222-2222-2222-2222-222222222222'
        self.save()
        self.refused('issuer-boot')

    def test_expired_future_and_unbounded_windows_refuse(self):
        for issued, expires in [(self.clock - 20, self.clock), (self.clock + 1, self.clock + 30),
                                (self.clock - 1, self.clock + 600), (True, self.clock + 30)]:
            self.approval.update(issued=issued, expires=expires)
            self.save()
            self.refused('issuer-window')

    def test_reused_nonce_or_wrong_case_set_refuses(self):
        self.approval['cases']['F2']['nonce'] = '1' * 64
        self.save()
        self.refused('issuer-nonce-reuse')

    def test_native_objects_and_wrong_table_names_refuse(self):
        self.registry['objects'] = [dict(kind='account', id='123')]
        self.save()
        self.refused('issuer-framework-registry')
        self.registry['objects'] = []
        self.registry['tables'][0] = 'account'
        self.save()
        self.refused('issuer-framework-registry')

    def test_missing_registered_diagnostic_refuses(self):
        self.registry['diagnostics'].pop()
        self.save()
        self.refused('issuer-framework-registry')

    def test_changed_build_hash_cannot_be_hidden_by_modified_image(self):
        path = Path(self.images['module.so']['path'])
        path.chmod(0o600)
        path.write_bytes(b'\x7fELFwrong image')
        path.chmod(0o500)
        self.refused('issuer-image')

    def test_existing_authority_is_never_replaced(self):
        self.put('authority.v1', b'previous original\n')
        with self.assertRaises(api.AdmissionError):
            self.issue()
        self.assertEqual(b'previous original\n', (self.directory / 'authority.v1').read_bytes())

    def test_preexisting_issuance_diagnostic_or_revocation_refuses_reuse(self):
        for name in ('issuance.original', 'writer.F2.original', 'revoked.v1', 'retired.v1'):
            self.put(name, b'previous original\n')
            self.refused('issuer-reused-operation')
            (self.directory / name).unlink()

    def test_private_mode_symlink_and_hardlink_refuse(self):
        path = self.directory / 'approval.original'
        path.chmod(0o644)
        self.refused('issuer-file')
        path.chmod(0o600)
        os.link(path, self.directory / 'other')
        self.refused('issuer-file')
        (self.directory / 'other').unlink()
        path.rename(self.directory / 'other')
        path.symlink_to('other')
        self.refused('issuer-io')

    def test_writable_image_and_symlinked_image_refuse(self):
        path = Path(self.images['module.so']['path'])
        path.chmod(0o522)
        self.refused('issuer-image')
        path.chmod(0o500)
        path.rename(path.with_suffix('.original'))
        path.symlink_to(path.with_suffix('.original').name)
        self.refused('issuer-io')

    def test_directory_replacement_is_detected_before_publication(self):
        with api._Store(str(self.directory), os.getuid(), os.getgid()) as store:
            moved = self.root / 'old'
            self.directory.rename(moved)
            self.directory.mkdir(mode=0o700)
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-directory-changed$'):
                api._issue(store, BOOT, lambda: self.clock)
        self.assertFalse((moved / 'authority.v1').exists())

    def test_failed_sync_retains_fence_and_originals_and_refuses_retry(self):
        real_sync = os.fsync
        count = 0
        def fail_authority_sync(fd):
            nonlocal count
            count += 1
            if count == 5:
                raise OSError('fictional sync failure')
            return real_sync(fd)
        with patch.object(api.os, 'fsync', side_effect=fail_authority_sync):
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-io$'):
                self.issue()
        self.assertTrue((self.directory / 'revoked.v1').exists())
        self.assertTrue((self.directory / 'issuance.original').exists())
        self.assertTrue((self.directory / 'authority.v1').exists())
        raw = (self.directory / 'authority.v1').read_bytes()
        with self.assertRaises(api.AdmissionError):
            self.issue()
        self.assertEqual(raw, (self.directory / 'authority.v1').read_bytes())

    def test_changed_original_after_fence_leaves_revocation(self):
        real_sync = os.fsync
        count = 0
        def change_approval(fd):
            nonlocal count
            real_sync(fd)
            count += 1
            if count == 2:
                self.put('approval.original', b'changed original\n')
        with patch.object(api.os, 'fsync', side_effect=change_approval):
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-original-changed$'):
                self.issue()
        self.assertTrue((self.directory / 'revoked.v1').exists())

    def test_public_entry_has_no_fixture_override_and_refuses_this_host(self):
        with self.assertRaises(api.AdmissionError):
            api.issue_framework_authority(str(self.directory))
        with self.assertRaises(TypeError):
            api.issue_framework_authority(str(self.directory), owner=os.getuid())

    def test_registry_and_build_must_match_explicit_approval_hashes(self):
        for name in ('registry.original', 'build.original'):
            original = (self.directory / name).read_bytes()
            self.put(name, original + b' ')
            self.refused('issuer-original-hash')
            self.put(name, original)

    def test_foreign_build_run_and_same_runtime_module_refuse(self):
        self.build['run'] = 'f' * 64
        self.save()
        self.refused('issuer-build')
        self.build['run'] = RUN
        self.build['module'] = self.build['runtime'].copy()
        self.save()
        self.refused('issuer-build')

    def test_group_writable_ancestor_refuses(self):
        self.root.chmod(0o720)
        self.refused('issuer-directory')

    def test_incomplete_case_set_and_bad_fact_digest_refuse(self):
        binding = self.approval['cases'].pop('F2')
        self.save()
        self.refused('evidence-fields')
        self.approval['cases']['F2'] = binding
        self.approval['cases']['F3']['facts_hash'] = 'not-a-digest'
        self.save()
        self.refused('digest')

    def test_successful_issuance_cannot_be_repeated(self):
        self.issue()
        original = (self.directory / 'authority.v1').read_bytes()
        with self.assertRaisesRegex(api.AdmissionError, '^issuer-reused-operation$'):
            self.issue()
        self.assertEqual(original, (self.directory / 'authority.v1').read_bytes())

    def test_failure_removing_fence_restores_revocation(self):
        real_sync = os.fsync
        count = 0
        def fail_unseal_sync(fd):
            nonlocal count
            count += 1
            if count == 7:
                raise OSError('fictional unseal sync failure')
            return real_sync(fd)
        with patch.object(api.os, 'fsync', side_effect=fail_unseal_sync):
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-io$'):
                self.issue()
        self.assertTrue((self.directory / 'revoked.v1').exists())
        self.assertTrue((self.directory / 'authority.v1').exists())

    def test_expiry_while_fenced_preserves_claim_and_blocks_activation(self):
        real_sync = os.fsync
        def expire(fd):
            real_sync(fd)
            self.clock += 500
        with patch.object(api.os, 'fsync', side_effect=expire):
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-window$'):
                self.issue()
        self.assertTrue((self.directory / 'revoked.v1').exists())
        self.assertFalse((self.directory / 'authority.v1').exists())

    def test_expiry_after_authority_write_leaves_revoked_bytes(self):
        real_sync = os.fsync
        count = 0
        def expire(fd):
            nonlocal count
            real_sync(fd)
            count += 1
            if count == 6:
                self.clock += 500
        with patch.object(api.os, 'fsync', side_effect=expire):
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-window$'):
                self.issue()
        self.assertTrue((self.directory / 'revoked.v1').exists())
        self.assertTrue((self.directory / 'authority.v1').exists())

    def test_identical_approval_replacement_is_not_the_original(self):
        real_sync = os.fsync
        count = 0
        def replace_approval(fd):
            nonlocal count
            real_sync(fd)
            count += 1
            if count == 2:
                self.put('replacement', (self.directory / 'approval.original').read_bytes())
                (self.directory / 'replacement').replace(self.directory / 'approval.original')
        with patch.object(api.os, 'fsync', side_effect=replace_approval):
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-original-changed$'):
                self.issue()
        self.assertTrue((self.directory / 'revoked.v1').exists())
        self.assertFalse((self.directory / 'authority.v1').exists())

    def test_replaced_fence_cannot_be_removed_as_our_own(self):
        real_sync = os.fsync
        count = 0
        def replace_fence(fd):
            nonlocal count
            real_sync(fd)
            count += 1
            if count == 6:
                self.put('replacement', (self.directory / 'revoked.v1').read_bytes())
                (self.directory / 'replacement').replace(self.directory / 'revoked.v1')
        with patch.object(api.os, 'fsync', side_effect=replace_fence):
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-fence-changed$'):
                self.issue()
        self.assertTrue((self.directory / 'revoked.v1').exists())

    def test_monotonic_deadline_cannot_be_extended_by_static_wall_time(self):
        values = iter([100.0, 500.0])
        with patch.object(api.time, 'monotonic', side_effect=lambda: next(values)):
            self.refused('issuer-window')

    def test_inherited_store_cannot_issue_in_forked_child(self):
        with api._Store(str(self.directory), os.getuid(), os.getgid()) as store:
            child = os.fork()
            if child == 0:
                try:
                    api._issue(store, BOOT, lambda: self.clock)
                except api.AdmissionError as exc:
                    os._exit(0 if str(exc) == 'issuer-process' else 2)
                os._exit(1)
            pid, status = os.waitpid(child, 0)
            self.assertEqual(child, pid)
            self.assertTrue(os.WIFEXITED(status))
            self.assertEqual(0, os.WEXITSTATUS(status))
        self.assertFalse((self.directory / 'authority.v1').exists())

    def test_published_bytes_are_accepted_by_real_bridge_parser(self):
        # Internal authored runtime facts only; this does not call the Linux factory.
        self.approval['cases']['F4']['nonce'] = 'b' * 64
        self.save()
        self.issue()
        self.put('intent.v1', ('paymenter-source-qa-intent-v1\nrun=' + RUN +
            '\ncase=F4\nnonce=' + 'b' * 64 + '\nfacts=' + 'c' * 64 +
            '\npid=PID\nstart=99\nissued=' + str(self.clock) +
            '\nexpires=' + str(self.clock + 20) + '\n').encode())
        flags = subprocess.run([shutil.which('pkg-config') or 'pkg-config',
                                '--cflags', '--libs', 'openssl'],
                               capture_output=True, timeout=5, check=True).stdout.decode().split()
        if sys.platform.startswith('linux'):
            flags.append('-ldl')
        binary = self.root / 'bridge-driver'
        compile_result = subprocess.run(['/usr/bin/c++', '-std=c++11', '-Wall', '-Wextra',
            '-Werror', '-pthread', str(HERE / 'authority_driver.cpp'), '-o', str(binary), *flags],
            capture_output=True, timeout=30)
        self.assertEqual(0, compile_result.returncode, compile_result.stderr.decode(errors='replace'))
        result = subprocess.run([str(binary), 'valid', str(self.directory)],
                                capture_output=True, timeout=5)
        self.assertEqual(0, result.returncode, result.stderr.decode(errors='replace'))
        self.assertIn(b'connection=123\n', (self.directory / 'writer.F4.original').read_bytes())


class CaseControllerTests(IssuerFixture):
    def setUp(self):
        super().setUp()
        self.issue()
        self.approval_path = 'case.F1.approval.original'
        self.case = dict(purpose='source-framework-case-approval-v1', intent='issue-framework-case',
            run=RUN, case='F1', nonce='1' * 64, facts_hash='c' * 64, pid=2, start_ticks=99,
            boot=BOOT, issued=self.clock, expires=self.clock + 20,
            authority_sha256=sha((self.directory / 'authority.v1').read_bytes()))
        self.save_case()

    def save_case(self):
        self.put(self.approval_path, encode(self.case))

    def publish(self, check_manager=lambda *unused: True):
        self.assertTrue(hasattr(api, '_issue_case'), 'case control publisher is not implemented')
        with api._Store(str(self.directory), os.getuid(), os.getgid()) as store:
            return api._issue_case(store, 'F1', BOOT, lambda: self.clock, check_manager)

    def test_publishes_exact_bound_intent_and_retains_original(self):
        receipt = self.publish()
        expected = ('paymenter-source-qa-intent-v1\nrun=' + RUN + '\ncase=F1\nnonce=' +
                    '1' * 64 + '\nfacts=' + 'c' * 64 + '\npid=2\nstart=99\nissued=2000000000\n'
                    'expires=2000000020\n').encode()
        self.assertEqual(expected, (self.directory / 'intent.v1').read_bytes())
        self.assertEqual(expected, (self.directory / 'intent.F1.original').read_bytes())
        self.assertEqual(sha(expected), receipt.intent_sha256)
        self.assertFalse((self.directory / 'revoked.v1').exists())

    def test_unknown_mode_and_mismatched_case_bindings_refuse(self):
        for key, value in [('mode', 'normal'), ('nonce', 'f' * 64), ('facts_hash', 'f' * 64),
                           ('case', 'F2'), ('run', 'f' * 64), ('authority_sha256', 'f' * 64),
                           ('intent', 'prepare-only'), ('boot', 'wrong-boot')]:
            original = self.case.copy()
            self.case[key] = value
            self.save_case()
            with self.subTest(key=key), self.assertRaises(api.AdmissionError):
                self.publish()
            self.assertFalse((self.directory / 'intent.v1').exists())
            self.case = original

    def test_pid_start_and_window_must_be_positive_canonical_bounded_values(self):
        for key, value in [('pid', 1), ('pid', True), ('start_ticks', 0), ('start_ticks', True),
                           ('expires', self.clock + 31), ('issued', self.clock + 1),
                           ('expires', self.clock), ('expires', True)]:
            original = self.case.copy()
            self.case[key] = value
            self.save_case()
            with self.subTest(key=key, value=value), self.assertRaises(api.AdmissionError):
                self.publish()
            self.assertFalse((self.directory / 'intent.v1').exists())
            self.case = original

    def test_actual_manager_check_is_mandatory(self):
        with self.assertRaisesRegex(api.AdmissionError, '^issuer-manager$'):
            self.publish(lambda *unused: False)
        self.assertFalse((self.directory / 'intent.v1').exists())

    def test_changed_manager_after_fencing_keeps_revocation(self):
        count = 0
        def manager(*unused):
            nonlocal count
            count += 1
            return count == 1
        with self.assertRaisesRegex(api.AdmissionError, '^issuer-manager$'):
            self.publish(manager)
        self.assertTrue((self.directory / 'revoked.v1').exists())
        self.assertFalse((self.directory / 'intent.v1').exists())

    def test_case_sync_failure_keeps_intent_original_and_revocation(self):
        real_sync = os.fsync
        count = 0
        def fail(fd):
            nonlocal count
            count += 1
            if count == 5:
                raise OSError('fictional intent sync failure')
            return real_sync(fd)
        with patch.object(api.os, 'fsync', side_effect=fail):
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-io$'):
                self.publish()
        self.assertTrue((self.directory / 'revoked.v1').exists())
        self.assertTrue((self.directory / 'intent.F1.original').exists())
        self.assertTrue((self.directory / 'intent.v1').exists())

    def test_manager_change_after_intent_write_keeps_revocation(self):
        count = 0
        def manager(*unused):
            nonlocal count
            count += 1
            return count < 3
        with self.assertRaisesRegex(api.AdmissionError, '^issuer-manager$'):
            self.publish(manager)
        self.assertTrue((self.directory / 'revoked.v1').exists())
        self.assertTrue((self.directory / 'intent.v1').exists())

    def test_case_approval_replacement_after_fencing_refuses(self):
        real_sync = os.fsync
        count = 0
        def replace_approval(fd):
            nonlocal count
            real_sync(fd)
            count += 1
            if count == 2:
                self.put('replacement', (self.directory / self.approval_path).read_bytes())
                (self.directory / 'replacement').replace(self.directory / self.approval_path)
        with patch.object(api.os, 'fsync', side_effect=replace_approval):
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-original-changed$'):
                self.publish()
        self.assertTrue((self.directory / 'revoked.v1').exists())
        self.assertFalse((self.directory / 'intent.v1').exists())

    def test_public_case_entry_has_no_manager_override(self):
        with self.assertRaises(api.AdmissionError):
            api.issue_framework_case(str(self.directory), 'F1')
        with self.assertRaises(TypeError):
            api.issue_framework_case(str(self.directory), 'F1', check_manager=lambda: True)

    def test_active_intent_or_existing_case_original_is_never_replaced(self):
        for name in ('intent.v1', 'intent.F1.original', 'writer.F1.original', 'revoked.v1'):
            self.put(name, b'previous original\n')
            with self.assertRaisesRegex(api.AdmissionError, '^issuer-reused-case$'):
                self.publish()
            self.assertEqual(b'previous original\n', (self.directory / name).read_bytes())
            (self.directory / name).unlink()

    def test_changed_authority_or_issuance_receipt_refuses(self):
        for name in ('authority.v1', 'issuance.original'):
            original = (self.directory / name).read_bytes()
            self.put(name, original + b' ')
            with self.assertRaises(api.AdmissionError):
                self.publish()
            self.assertFalse((self.directory / 'intent.v1').exists())
            self.put(name, original)

    def test_case_cannot_be_reissued_even_after_intent_is_externally_removed(self):
        self.publish()
        original = (self.directory / 'intent.F1.original').read_bytes()
        (self.directory / 'intent.v1').unlink()
        with self.assertRaisesRegex(api.AdmissionError, '^issuer-reused-case$'):
            self.publish()
        self.assertEqual(original, (self.directory / 'intent.F1.original').read_bytes())

    def test_f5_and_unapproved_control_paths_are_not_accepted(self):
        self.assertTrue(hasattr(api, '_issue_case'), 'case control publisher is not implemented')
        with api._Store(str(self.directory), os.getuid(), os.getgid()) as store:
            with self.assertRaises(api.AdmissionError):
                api._issue_case(store, 'F5', BOOT, lambda: self.clock, lambda *unused: True)

    def test_native_stat_parser_handles_parentheses_and_rejects_different_pid(self):
        self.assertTrue(hasattr(api, '_process_start'), 'kernel identity parser is not implemented')
        raw = ('2 (manager (worker)) S ' + '0 ' * 18 + '99 0 0\n').encode()
        self.assertEqual(99, api._process_start(raw, 2))
        with self.assertRaises(api.AdmissionError):
            api._process_start(raw, 3)

    def test_native_mapping_requires_exec_permissions_device_inode_and_exact_path(self):
        self.assertTrue(hasattr(api, '_mapping'), 'kernel image mapping parser is not implemented')
        raw = b'7f00-8000 r-xp 00000000 08:02 123 /fictional/module.so\n'
        self.assertTrue(api._mapping(raw, '/fictional/module.so', (8, 2), 123))
        for changed in [raw.replace(b'r-xp', b'rw-p'), raw.replace(b'08:02', b'08:03'),
                        raw.replace(b'123 ', b'124 '), raw.replace(b'.so', b'.so (deleted)')]:
            self.assertFalse(api._mapping(changed, '/fictional/module.so', (8, 2), 123))


if __name__ == '__main__':
    unittest.main()
