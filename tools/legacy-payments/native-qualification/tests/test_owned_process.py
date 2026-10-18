"""Real local child/pipe tests; Linux identity acceptance is qualified on source."""
import importlib.util
import os
from pathlib import Path
import sys
import time
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('owned_process', HERE.parent/'owned_process.py')
api = None
if spec.origin and Path(spec.origin).exists():
    api = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(api)


class OwnedProcessTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(api, 'owned process supervisor is not implemented')

    def run_child(self, script, **kw):
        return api.supervise_owned([sys.executable, '-c', script], b'', **kw)

    def test_captures_original_streams_and_nonzero_exit_without_success_claim(self):
        r = self.run_child('import os;os.write(1,b"hello");os.write(2,b"error");os._exit(7)', seconds=2)
        self.assertEqual((b'hello', b'error', 7), (r['stdout'], r['stderr'], r['exitcode']))
        self.assertEqual('complete', r['retirement'])
        self.assertTrue(r['stdout_eof'] and r['stderr_eof'])
        self.assertEqual('child-exit', r['failure'])

    def test_supplies_bounded_stdin_without_deadlocking_on_output_first(self):
        script = 'import os;os.write(1,b"a"*8192);d=os.read(0,8192);os.write(1,d)'
        r = api.supervise_owned([sys.executable, '-c', script], b'input', seconds=2)
        self.assertEqual(b'a'*8192+b'input', r['stdout'])
        self.assertIsNone(r['failure'])

    def test_timeout_kills_and_reaps_only_the_owned_child(self):
        start = time.monotonic()
        r = self.run_child('import os,time;os.write(1,b"ready");time.sleep(20)', seconds=.5)
        self.assertLess(time.monotonic()-start, 2)
        self.assertEqual(('deadline', 'complete', b'ready'), (r['failure'], r['retirement'], r['stdout']))
        self.assertLess(r['exitcode'], 0)
        with self.assertRaises(ChildProcessError):
            os.waitpid(r['identity']['pid'], os.WNOHANG)

    def test_overflow_preserves_exact_prefix_and_reaps_child(self):
        r = self.run_child('import os,time;os.write(1,b"z"*50000);time.sleep(20)', seconds=2, stdout_limit=37)
        self.assertEqual(b'z'*37, r['stdout'])
        self.assertEqual(('stdout-limit', 'complete'), (r['failure'], r['retirement']))

    def test_stderr_overflow_is_also_bounded(self):
        r = self.run_child('import os;os.write(2,b"e"*50000)', seconds=2, stderr_limit=19)
        self.assertEqual(b'e'*19, r['stderr'])
        self.assertEqual('stderr-limit', r['failure'])

    def test_child_exit_does_not_turn_inherited_pipe_into_unbounded_wait(self):
        # The short-lived descendant is outside the supervisor's signal authority.
        script = 'import os,time\nif os.fork()==0:time.sleep(.8);os._exit(0)\nos._exit(0)'
        start = time.monotonic()
        r = self.run_child(script, seconds=.3)
        self.assertLess(time.monotonic()-start, .65)
        self.assertEqual(('deadline', 0), (r['failure'], r['exitcode']))
        self.assertFalse(r['stdout_eof'])
        time.sleep(.8)

    def test_identity_mismatch_is_held_and_never_signalled(self):
        with patch.object(api, '_same_child', return_value=False), patch.object(api.os, 'kill', wraps=os.kill) as kill:
            r = self.run_child('import time;time.sleep(20)', seconds=.1)
            self.assertEqual('identity-held', r['retirement'])
            kill.assert_not_called()
        # This test created that child; cleanup stays outside production code.
        os.kill(r['identity']['pid'], 9)
        os.waitpid(r['identity']['pid'], 0)

    def test_invalid_argv_input_and_limits_refuse_before_spawning(self):
        for argv, data, kw in [(['relative'], b'', {}), ([sys.executable], b'x'*65537, {}),
                              ([sys.executable], b'', {'seconds':31}),
                              ([sys.executable], b'', {'stdout_limit':1048577})]:
            with self.subTest(kw=kw), self.assertRaises(ValueError):
                api.supervise_owned(argv, data, **kw)

    def test_missing_executable_is_a_retained_spawn_failure(self):
        r = api.supervise_owned(['/nonexistent/paymenter-qa-child'], b'', seconds=1)
        self.assertEqual(('spawn', 'not-started', None), (r['failure'], r['retirement'], r['identity']))
