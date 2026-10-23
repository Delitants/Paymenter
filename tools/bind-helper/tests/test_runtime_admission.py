import pathlib
import os,json,hashlib,tempfile,tracemalloc
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


class StreamingAdmissionTest(unittest.TestCase):
    def test_large_runtime_hash_does_not_retain_file_contents(self):
        from paymenter_bind.custody import RootDirectory
        with tempfile.TemporaryDirectory(dir=pathlib.Path(__file__).parents[1]) as directory:
            root=pathlib.Path(directory);(root/'runtime').mkdir()
            data=b'public-runtime-fixture'*(6*1024*1024//22)
            file=root/'runtime/python';file.write_bytes(data);file.chmod(0o644)
            manifest={'schema':'paymenter-bind-runtime-candidate-v1','native_accepted':False,'installation_authorized':False,'native_admission_required':True,'files':{'runtime/python':{'sha256':hashlib.sha256(data).hexdigest(),'mode':0o644}}}
            (root/'bundle-manifest.json').write_text(json.dumps(manifest))
            tracemalloc.start()
            try:
                observed=runtime_admission.verify_tree(root,os.getuid())
                _,peak=tracemalloc.get_traced_memory()
            finally:tracemalloc.stop()
            self.assertEqual(observed,manifest)
            self.assertLess(peak,1024*1024,'hash-only admission must not retain an entire runtime file')


if __name__ == '__main__':
    unittest.main()
