import pathlib
import sys
import unittest
sys.path.insert(0, str(pathlib.Path(__file__).parents[1]))
from paymenter_bind import runtime_admission


class RuntimeRootTest(unittest.TestCase):
    def test_root_is_derived_from_matching_interpreter_prefixes(self):
        derive = getattr(runtime_admission, 'runtime_root', None)
        self.assertIsNotNone(derive, 'runtime admission must support a versioned prefix')
        for root in ['/opt/paymenter-bind', '/opt/paymenter-bind-runtime-test']:
            self.assertEqual(derive(root + '/venv', root + '/runtime'), pathlib.Path(root))

    def test_mismatched_or_ambiguous_prefixes_are_refused(self):
        derive = getattr(runtime_admission, 'runtime_root', None)
        self.assertIsNotNone(derive, 'runtime admission must validate both prefixes')
        for prefix, base in [('relative/venv', 'relative/runtime'),
                             ('/opt/a/venv', '/opt/b/runtime'),
                             ('/opt/a/bin', '/opt/a/runtime'),
                             ('/opt/a/../b/venv', '/opt/b/runtime'),
                             ('/opt//a/venv', '/opt/a/runtime')]:
            with self.assertRaises(ValueError):
                derive(prefix, base)


if __name__ == '__main__':
    unittest.main()
