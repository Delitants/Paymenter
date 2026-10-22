"""Portable XML candidate coverage; no kernel or vendor acceptance."""
from pathlib import Path
import hashlib
import shutil
import struct
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
NATIVE = HERE.parent / 'native'
CONFIG = (b'<doc><feature><pmtune>off</pmtune><pmvalidate>off</pmvalidate>'
          b'<pmusercreate>off</pmusercreate></feature></doc>')


class FixtureVendorConfigTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='fixture-xml-', dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary = Path(cls.temp.name) / 'portable'
        cls.compiled = None
        sources = (HERE / 'fixture_vendor_config_driver.c',
                   NATIVE / 'fixture_vendor_config_child.c')
        if all(p.exists() for p in sources):
            cls.compiled = subprocess.run(
                ['/usr/bin/cc', '-std=c11', '-Wall', '-Wextra', '-Werror',
                 *map(str, sources), '-o', str(cls.binary)],
                capture_output=True, timeout=30)

    def setUp(self):
        self.assertIsNotNone(self.compiled, 'XML candidate is not implemented')
        self.assertEqual(0, self.compiled.returncode,
                         self.compiled.stderr.decode(errors='replace'))

    def run_candidate(self, mode, args):
        r = subprocess.run([str(self.binary), mode, *args],
                           capture_output=True, timeout=2)
        return r.returncode, r.stdout, r.stderr

    def test_exact_vendor_candidate_and_refusals(self):
        self.assertEqual(110, len(CONFIG))
        self.assertEqual('2650423ddfff875c7824a89a36be0557298e0015b8b3a336788b0f7fa9d94757',
                         hashlib.sha256(CONFIG).hexdigest())
        self.assertEqual((0, CONFIG, b''),
                         self.run_candidate('normal', ['--command', 'config']))
        invalid = [[], ['--command'], ['config'], ['--offline-config-v0'],
                   ['--command', 'config', 'extra'],
                   ['--command', 'config', '--lang', 'en']]
        invalid += [['--command', c] for c in
                    ('pay', 'refund', 'recurring', 'pmtune', 'pmvalidate',
                     'pmusercreate', 'async', 'Config', 'configx')]
        for args in invalid:
            with self.subTest(args=args):
                self.assertEqual((64, b'', b'fixture invocation refused\n'),
                                 self.run_candidate('normal', args))

    def test_setup_failure_produces_no_output(self):
        for mode in ('no-new-privs-fail', 'setup-fail'):
            with self.subTest(mode=mode):
                self.assertEqual((126, b'', b''),
                                 self.run_candidate(mode, ['--command', 'config']))

    def test_output_failure_is_one_attempt(self):
        for mode in ('stdout-error', 'stdout-short', 'stderr-error', 'stderr-short'):
            with self.subTest(mode=mode):
                args = ['--command', 'config'] if mode.startswith('stdout') else []
                self.assertEqual((74, b'', b''), self.run_candidate(mode, args))

    def test_argv0_is_not_dereferenced(self):
        self.assertEqual((0, CONFIG, b''), self.run_candidate('argv0-null', []))

    def test_candidate_objects_compile(self):
        clang = shutil.which('clang')
        self.assertIsNotNone(clang, 'existing clang required; no automatic download')
        for source in ('fixture_vendor_config_child.c', 'fixture_child_x86_64.S'):
            with self.subTest(source=source):
                obj = Path(self.temp.name) / (source + '.o')
                r = subprocess.run(
                    [clang, '--target=x86_64-linux-gnu', '-ffreestanding',
                     '-fno-builtin', '-fno-stack-protector', '-fno-pie',
                     '-Wall', '-Wextra', '-Werror', '-c', str(NATIVE / source),
                     '-o', str(obj)], capture_output=True, timeout=30)
                self.assertEqual(0, r.returncode, r.stderr.decode(errors='replace'))
                data = obj.read_bytes()
                self.assertEqual(b'\x7fELF\x02\x01', data[:6])
                self.assertEqual((1, 62), struct.unpack_from('<HH', data, 16))
