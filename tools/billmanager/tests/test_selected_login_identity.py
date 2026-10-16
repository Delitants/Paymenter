"""CLI regressions for stable login identity; controlled SQL output, no real data."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


MYSQL = r'''#!/usr/bin/env python3
import re, sys, json
from xml.etree.ElementTree import Element, SubElement, tostring
sql = sys.stdin.read()
ids = set()
for group in re.findall(r'\bid IN \(([0-9,]+)\)', sql):
    ids.update(group.split(','))
names = {bytes.fromhex(x).decode('utf8') for x in
         re.findall(r'CONVERT\(0x([a-fA-F0-9]+) USING utf8\)', sql)}
users = [
    dict(id='41', name='new-login', account='10', email='new@example.test', enabled='on', level='16'),
    dict(id='77', name='old-login', account='20', email='other@example.test', enabled='on', level='16'),
    dict(id='99', name='bare-login', account='30', email='bare@example.test', enabled='on', level='16'),
]
selected = [u for u in users if u['id'] in ids or u['name'] in names]
for statement in sql.split(';'):
    statement = statement.strip()
    if not statement.startswith('SELECT '):
        continue
    rows = []
    if statement.startswith('SELECT UTC_TIMESTAMP()'):
        rows = [dict(captured_at_utc='2026-10-15 12:00:00', database_timezone='SYSTEM')]
    elif statement.startswith('SELECT id,name,account,level,enabled,email'):
        rows = selected
    elif statement.startswith('SELECT id,name,level,registration_date'):
        rows = [dict(id=u['account']) for u in selected]
    root = Element('resultset')
    for row in rows:
        child = SubElement(root, 'row')
        for name, value in row.items():
            SubElement(child, 'field', name=name).text = value
    print(tostring(root, encoding='unicode', short_empty_elements=False))
'''


def event(timestamp, login):
    return timestamp+'\tfixture\t'+login+'\tfixture\tfixture\n'


class SelectedLoginIdentityTests(unittest.TestCase):
    def export(self, log):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            mysql = root/'mysql'; mysql.write_text(MYSQL); mysql.chmod(0o700)
            auth = root/'auth.log'; auth.write_text(log)
            env = os.environ.copy(); env['PATH'] = str(root)+os.pathsep+env['PATH']
            result = subprocess.run([sys.executable,
                str(Path(__file__).resolve().parents[1]/'export-selected.py'),
                '--source-id', '192.0.2.44', '--login-cutoff', '2024-10-15 00:00:00',
                '--auth-log', str(auth)], env=env, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            return json.loads(result.stdout)['tables']['users']

    def test_renamed_login_retains_original_user_id(self):
        users = self.export(event('2025-01-01 00:00:00', 'old-login(41)'))
        self.assertEqual([u['id'] for u in users], ['41'])
        self.assertEqual(users[0]['last_login'], '2025-01-01 00:00:00')

    def test_reused_login_name_does_not_borrow_another_users_activity(self):
        users = self.export(event('2025-01-01 00:00:00', 'old-login(41)')+
                            event('2025-02-01 00:00:00', 'bare-login'))
        self.assertEqual({u['id'] for u in users}, {'41', '99'})
        self.assertNotIn('77', {u['id'] for u in users})

    def test_last_login_is_latest_across_name_changes(self):
        users = self.export(event('2025-01-01 00:00:00', 'new-login(41)')+
                            event('2025-02-01 00:00:00', 'old-login(41)'))
        self.assertEqual([u['id'] for u in users], ['41'])
        self.assertEqual(users[0]['last_login'], '2025-02-01 00:00:00')

    def test_cutoff_is_inclusive_and_older_activity_is_excluded(self):
        users = self.export(event('2024-10-15 00:00:00', 'new-login(41)')+
                            event('2024-10-14 23:59:59', 'old-login(77)'))
        self.assertEqual([u['id'] for u in users], ['41'])
        self.assertEqual(users[0]['last_login'], '2024-10-15 00:00:00')

    def test_bare_name_legacy_records_keep_existing_behavior(self):
        users = self.export(event('2025-01-01 00:00:00', 'bare-login'))
        self.assertEqual([u['id'] for u in users], ['99'])

    def test_malformed_and_out_of_window_records_add_no_clients(self):
        users = self.export('not\tan\tauthentication\trecord\n'+
                            event('2024-10-14 23:59:59', 'new-login(41)')+
                            event('2025-01-01 00:00:00', 'bare-login'))
        self.assertEqual([u['id'] for u in users], ['99'])


if __name__ == '__main__':
    unittest.main()
