import os
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from rehearsal import rehearse


@unittest.skipUnless(sys.platform == 'linux' and os.geteuid() == 0, 'Native isolated root Linux acceptance')
class RehearsalTest(unittest.TestCase):
    def test_native_process_crash_callbacks_and_cleanup(self):
        report = rehearse()
        self.assertTrue(all(report['checks'].values()))
        self.assertFalse(report['real_execution_ready'])
        self.assertEqual(report['custody_counts'], {'arrivals': 8, 'events': 1, 'quarantined': 6})
        self.assertGreater(report['writer_attempts_while_frozen'], 0)
        self.assertTrue(report['owned_root_removed'])
        self.assertEqual(report['remaining_owned_processes'], [])


if __name__ == '__main__':
    unittest.main()
