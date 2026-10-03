"""Exercise optional commission export with controlled source command outputs."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


class GatewayFeeExportTest(unittest.TestCase):
    def run_export(self, fees=False, state='on'):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mgr = root / 'mgrctl'
            mgr.write_text("#!/usr/bin/env python3\nprint('<doc><module>pmwebmoney</module><active>on</active><purse>Z000000000000</purse><secret>synthetic-secret</secret><currency>9</currency></doc>')\n")
            mysql = root / 'mysql'
            mysql.write_text("#!/usr/bin/env python3\nimport os\nprint('<resultset><row><field name=\"id\">21</field><field name=\"module\">pmwebmoney</field><field name=\"active\">'+os.environ['SYNTHETIC_ACTIVE']+'</field><field name=\"currency\">9</field><field name=\"commissionpercent\">2.5</field><field name=\"commissionamount\">0.2500</field></row></resultset>')\n")
            mgr.chmod(0o700)
            mysql.chmod(0o700)
            env = os.environ.copy()
            env.update(PATH=str(root)+os.pathsep+env['PATH'], SYNTHETIC_ACTIVE=state)
            args = [sys.executable, str(Path(__file__).resolve().parents[1]/'export-gateway-settings.py'), '--source', '192.0.2.44', '--id', '21', '--mgrctl', str(mgr)]
            if fees:
                args.append('--include-fees')
            return subprocess.run(args, env=env, text=True, capture_output=True)

    def test_optional_fees_preserve_credential_records(self):
        old = self.run_export()
        new = self.run_export(fees=True)
        self.assertEqual(0, old.returncode, old.stderr)
        self.assertEqual(0, new.returncode, new.stderr)
        before, after = json.loads(old.stdout), json.loads(new.stdout)
        self.assertEqual(before['gateways'], after['gateways'])
        self.assertNotIn('fees', before)
        self.assertEqual([{'id': '21', 'module': 'pmwebmoney', 'active': 'on', 'currency': '9', 'commissionpercent': '2.5', 'commissionamount': '0.2500'}], after['fees'])

    def test_source_state_drift_does_not_emit_partial_credentials(self):
        result = self.run_export(fees=True, state='off')
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('', result.stdout)


if __name__ == '__main__':
    unittest.main()
