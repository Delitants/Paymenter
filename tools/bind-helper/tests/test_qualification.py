"""Local package qualification; no connection, native execution or installation."""
import json
import pathlib
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).parents[1]))
try:
    from paymenter_bind.qualify import qualify
except ImportError:
    qualify = None

ROOT = pathlib.Path(__file__).parents[1]


class QualificationTest(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(qualify, 'package auditor missing')
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = pathlib.Path(self.tmp.name) / 'helper'
        shutil.copytree(ROOT, self.root, ignore=shutil.ignore_patterns('__pycache__', '.git'))

    def test_complete_package_has_no_executable_native_authority(self):
        report = qualify(self.root)
        self.assertEqual(report['version'], '0.1.0')
        self.assertFalse(report['native_accepted'])
        self.assertFalse(report['installation_authorized'])

    def test_missing_runtime_or_dependency_is_rejected(self):
        for name in ['paymenter_bind/cli.py', 'paymenter_bind/journal.py', 'requirements.lock']:
            with self.subTest(name=name):
                path = self.root / name
                before = path.read_bytes()
                path.unlink()
                with self.assertRaises(ValueError):
                    qualify(self.root)
                path.write_bytes(before)

    def test_unsafe_or_populated_templates_are_rejected(self):
        changes = {
            'install/sudoers.example': 'paymenter-dns ALL=(root) NOPASSWD: ALL\n',
            'install/authorized_keys.example': 'ssh-ed25519 POPULATED_KEY\n',
            'install/ssh-dispatch': '#!/bin/sh\nexec sh\n',
            'install/server-config.example.json': json.dumps({'bindings': {'client': {'enabled': True}}}),
            'tests/native-acceptance.example.json': json.dumps({'installation_authorized': True, 'host': '192.0.2.10'}),
        }
        for name, data in changes.items():
            with self.subTest(name=name):
                path = self.root / name
                before = path.read_bytes()
                path.write_text(data)
                with self.assertRaises(ValueError):
                    qualify(self.root)
                path.write_bytes(before)

    def test_manifest_detects_changed_runtime_even_if_importable(self):
        path = self.root / 'paymenter_bind/cli.py'
        path.write_text(path.read_text() + '\n# unexpected package change\n')
        with self.assertRaises(ValueError):
            qualify(self.root)


if __name__ == '__main__':
    unittest.main()
