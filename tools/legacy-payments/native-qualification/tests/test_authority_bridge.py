"""Real file custody/bridge checks; authored runtime facts are not native QA."""
import hashlib
import os
import shutil
import sys
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

HERE = Path(__file__).resolve().parent
NATIVE = HERE.parent / 'native'
SCENARIOS = ('valid', 'prepare-only', 'unknown-field', 'duplicate-field', 'expired',
             'future', 'unbounded', 'wrong-actor', 'wrong-action', 'wrong-run',
             'wrong-nonce', 'wrong-case', 'wrong-pid', 'wrong-start', 'wrong-facts',
             'intent-replaced', 'authority-replaced', 'registry-changed', 'build-changed',
             'revoked', 'runtime-revoked', 'forked', 'directory-replaced',
             'authority-symlink', 'authority-hardlink', 'authority-mode',
             'directory-mode', 'intent-mode', 'duplicate-diagnostic',
             'writer-symlink', 'writer-connection', 'release-absent', 'release-valid',
             'release-wrong-nonce', 'release-wrong-original', 'public-factory-refuses',
             'clock-forward', 'clock-backward', 'reused-nonces', 'registry-hash',
             'forged-grant', 'reused-case', 'wrong-intent-nonce', 'empty-identity',
             'wrong-diagnostic-binding', 'release-file-mode', 'long-connection', 'sync-failure',
             'wrong-diagnostic-session', 'wrong-grant-thread', 'forked-locked')


class AuthorityBridgeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='authority-build-', dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary = Path(cls.temp.name) / 'authority-driver'
        cls.compile_result = None
        if (NATIVE / 'authority_bridge.cpp').exists():
            flags = subprocess.run([shutil.which('pkg-config') or 'pkg-config', '--cflags', '--libs', 'openssl'],
                                   capture_output=True, timeout=5, check=True).stdout.decode().split()
            if sys.platform.startswith('linux'):
                flags.append('-ldl')
            cls.compile_result = subprocess.run(
                ['/usr/bin/c++', '-std=c++11', '-Wall', '-Wextra', '-Werror', '-pthread',
                 str(HERE / 'authority_driver.cpp'), '-o', str(cls.binary), *flags],
                capture_output=True, timeout=30)

    def scenario(self, name):
        self.assertIsNotNone(self.compile_result, 'protected authority bridge is not implemented')
        self.assertEqual(0, self.compile_result.returncode,
                         self.compile_result.stderr.decode(errors='replace'))
        with tempfile.TemporaryDirectory(prefix='authority-fixture-', dir=HERE) as temp:
            root = Path(temp).resolve()
            raw_registry = b'fictional registry original; no native resource created\n'
            raw_build = b'fictional build original; no vendor acceptance\n'
            files = {'registry.original': raw_registry, 'build.original': raw_build}
            now = int(time.time())
            base = [('intent', 'framework-qa-execute'), ('run', 'a' * 64),
                    ('boot', '11111111-1111-1111-1111-111111111111'),
                    ('actor_hex', b'fictional-admin'.hex()), ('issued', str(now - 1)),
                    ('expires', str(now + 300)), ('module_path', '/fictional/module.so'),
                    ('module_sha256', 'd' * 64), ('runtime_path', '/fictional/wrapper'),
                    ('runtime_sha256', 'e' * 64),
                    ('registry_sha256', hashlib.sha256(raw_registry).hexdigest()),
                    ('build_sha256', hashlib.sha256(raw_build).hexdigest())]
            for case, nonce in [('F1', '1'), ('F2', '2'), ('F3', '3'), ('F4', 'b')]:
                base.extend([('nonce_' + case, nonce * 64), ('facts_' + case, 'c' * 64)])
            if name == 'prepare-only': base[0] = ('intent', 'prepare-only')
            if name == 'unknown-field': base.append(('reviewed', 'true'))
            if name == 'duplicate-field': base.append(('run', 'a' * 64))
            if name == 'expired': base[5] = ('expires', str(now - 1))
            if name == 'future': base[4] = ('issued', str(now + 10))
            if name == 'unbounded': base[5] = ('expires', str(now + 1000))
            if name == 'reused-nonces': base = [(k, 'b' * 64 if k == 'nonce_F1' else v) for k, v in base]
            if name == 'registry-hash': base = [(k, 'f' * 64 if k == 'registry_sha256' else v) for k, v in base]
            files['authority.v1'] = ('paymenter-source-qa-authority-v1\n' +
                                     ''.join(k + '=' + v + '\n' for k, v in base)).encode()
            files['intent.v1'] = ('paymenter-source-qa-intent-v1\nrun=' + 'a' * 64 +
                '\ncase=F4\nnonce=' + 'b' * 64 + '\nfacts=' + 'c' * 64 +
                '\npid=PID\nstart=99\nissued=' + str(now) + '\nexpires=' + str(now + 20) + '\n').encode()
            original = b'fictional independently-read barrier original\n'
            files['barrier.original'] = original
            files['release.F4.v1'] = ('paymenter-source-qa-release-v1\nrun=' + 'a' * 64 +
                '\ncase=F4\nnonce=' + ('f' if name == 'release-wrong-nonce' else 'b') * 64 +
                '\noriginal_sha256=' + (('f' * 64) if name == 'release-wrong-original'
                                       else hashlib.sha256(original).hexdigest()) + '\n').encode()
            for path, raw in files.items():
                (root / path).write_bytes(raw)
                (root / path).chmod(0o600)
            result = subprocess.run([str(self.binary), name, str(root)],
                                    capture_output=True, timeout=5)
            self.assertEqual(0, result.returncode, result.stderr.decode(errors='replace'))


for name in SCENARIOS:
    def run(self, name=name):
        self.scenario(name)
    setattr(AuthorityBridgeTests, 'test_' + name.replace('-', '_'), run)


if __name__ == '__main__':
    unittest.main()
