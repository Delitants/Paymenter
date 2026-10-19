"""Actual harmless children; vendor launch alone is redirected to a fixture.

No SDK, manager or source host is used. The long-start test really exceeds the
ordinary 30-second limit. Deadline tests scale only the supervisor's monotonic
clock; child ownership, pipes, signals and reaping remain real.
"""
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('startup_owned_process', HERE.parent/'owned_process.py')
api = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(api)
FIXED = ['/usr/local/mgr5/sbin/mgrctl', '-m', 'billmgr', '-o', 'xml', 'actionlist']
REPLY = b'<doc func="actionlist"><elem><action>fixture</action></elem></doc>\n'


class StartupProcessTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='startup-process-')
        self.addCleanup(self.temp.cleanup)
        self.marker = Path(self.temp.name)/'invocations'
        self.fixture = Path(self.temp.name)/'getter.py'
        self.fixture.write_text('''import os,sys,time
if sys.argv[1:] != ['-m','billmgr','-o','xml','actionlist']:sys.exit(8)
if sys.stdin.buffer.read() != b'':sys.exit(9)
with open(os.environ['FIXTURE_MARKER'],'ab') as f:f.write(b'called\\n')
mode=os.environ['FIXTURE_MODE']
if mode=='long-start':time.sleep(31.2)
if mode=='hang':os.write(1,b'ready');time.sleep(20)
if mode=='stdout-overflow':os.write(1,b'x'*200000);time.sleep(20)
if mode=='stderr-overflow':os.write(2,b'e'*20000);time.sleep(20)
if mode=='stderr':os.write(2,b'fixture error');sys.exit(0)
if mode=='inherited-pipe':
 if os.fork()==0:time.sleep(1);os._exit(0)
 os._exit(0)
os.write(1,b'<doc func="actionlist"><elem><action>fixture</action></elem></doc>\\n')
''')
        real_popen = subprocess.Popen

        def fixture_launch(argv, **kw):
            # All supervisor behavior stays real. A changed command fails by
            # attempting its real path, rather than being accepted by the double.
            if argv == FIXED:
                argv = [sys.executable, str(self.fixture)] + argv[1:]
            return real_popen(argv, **kw)

        self.launch = patch.object(api.subprocess, 'Popen', side_effect=fixture_launch)
        self.launch.start()
        self.addCleanup(self.launch.stop)

    def startup(self, mode='normal', expires=None):
        entry = getattr(api, 'supervise_billmanager_startup', None)
        self.assertTrue(callable(entry), 'dedicated fixed startup supervisor is missing')
        env = dict(os.environ, FIXTURE_MODE=mode, FIXTURE_MARKER=str(self.marker))
        return entry(int(time.time())+300 if expires is None else expires, env=env)

    def assert_reaped(self, result):
        self.assertEqual('complete', result['retirement'])
        with self.assertRaises(ChildProcessError):
            os.waitpid(result['identity']['pid'], os.WNOHANG)

    def test_startup_finishes_beyond_ordinary_command_limit_without_retry(self):
        start = time.monotonic()
        result = self.startup('long-start')
        self.assertGreater(time.monotonic()-start, 30)
        self.assertEqual((None, 0, REPLY), (result['failure'], result['exitcode'], result['stdout']))
        self.assertTrue(result['stdout_eof'] and result['stderr_eof'])
        self.assertEqual(b'called\n', self.marker.read_bytes())
        self.assert_reaped(result)

    def test_startup_still_has_a_hard_120_second_monotonic_deadline(self):
        original_clock = api._clock
        start = original_clock()
        with patch.object(api, '_clock', side_effect=lambda: start+(original_clock()-start)*200):
            result = self.startup('hang')
        elapsed = original_clock()-start
        self.assertGreaterEqual(elapsed, .6)
        self.assertLess(elapsed, 2)
        self.assertEqual(('deadline', b'ready'), (result['failure'], result['stdout']))
        self.assertLess(result['exitcode'], 0)
        self.assertEqual(b'called\n', self.marker.read_bytes())
        self.assert_reaped(result)

    def test_earlier_authority_expiry_stops_startup_instead_of_renewing_it(self):
        start = time.monotonic()
        result = self.startup('hang', expires=int(time.time())+1)
        self.assertLess(time.monotonic()-start, 2)
        self.assertEqual('authority-expired', result['failure'])
        self.assertLess(result['exitcode'], 0)
        self.assert_reaped(result)

    def test_invalid_or_expired_authority_refuses_before_launch(self):
        for expiry in (int(time.time())-1, int(time.time())+10000, True, None, 'later'):
            with self.subTest(expiry=expiry), self.assertRaises(ValueError):
                # None is deliberately passed directly, not given our helper's default.
                entry = getattr(api, 'supervise_billmanager_startup', None)
                self.assertTrue(callable(entry), 'dedicated fixed startup supervisor is missing')
                entry(expiry)
        self.assertFalse(self.marker.exists())

    def test_startup_stdout_overflow_keeps_bounded_original_and_reaps(self):
        result = self.startup('stdout-overflow')
        self.assertEqual(('stdout-limit', b'x'*196608), (result['failure'], result['stdout']))
        self.assert_reaped(result)

    def test_startup_stderr_overflow_keeps_bounded_original_and_reaps(self):
        result = self.startup('stderr-overflow')
        self.assertEqual(('stderr-limit', b'e'*8192), (result['failure'], result['stderr']))
        self.assert_reaped(result)

    def test_startup_stderr_is_not_accepted_as_success(self):
        result = self.startup('stderr')
        self.assertEqual(('child-stderr', 0, b'fixture error'),
                         (result['failure'], result['exitcode'], result['stderr']))
        self.assert_reaped(result)

    def test_inherited_pipe_cannot_outlive_startup_deadline_as_success(self):
        original_clock = api._clock
        start = original_clock()
        with patch.object(api, '_clock', side_effect=lambda: start+(original_clock()-start)*400):
            result = self.startup('inherited-pipe')
        self.assertLess(original_clock()-start, .8)
        self.assertEqual(('deadline', 0), (result['failure'], result['exitcode']))
        self.assertFalse(result['stdout_eof'])
        self.assert_reaped(result)
        # Only the fixture descendant owns this pipe; let it exit naturally.
        time.sleep(1)
