"""Export credentials only for an explicit selected user-id list on stdin.

Pipe stdout directly into the encrypted destination receiver. Never redirect it
to an ordinary file or terminal. Python 2.7/3 compatible; read-only source access.
"""
from __future__ import print_function
import argparse
import datetime
import json
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

parser = argparse.ArgumentParser()
parser.add_argument('--source-id', required=True)
args = parser.parse_args()
requested = json.load(sys.stdin)
if not isinstance(requested, list) or not requested or len(requested) > 10000:
    raise RuntimeError('Invalid selected-user list')
ids = sorted(set(str(int(value)) for value in requested))
if len(ids) != len(requested) or any(int(value) <= 0 for value in ids):
    raise RuntimeError('Duplicate or invalid selected identity')
selection = ','.join(ids)
sql = "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; START TRANSACTION WITH CONSISTENT SNAPSHOT;"
sql += "SELECT id,password FROM user WHERE level=16 AND enabled='on' AND id IN (" + selection + ");"
sql += "SELECT user,secret FROM totp WHERE user IN (" + selection + ");ROLLBACK;"
proc = subprocess.Popen(['mysql', '--default-character-set=utf8', '--xml', '--batch', 'billmgr'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
raw, errors = proc.communicate(sql.encode('utf8'))
if proc.returncode:
    raise RuntimeError('Credential export query failed')
parts = re.findall(r'<resultset\b.*?</resultset>|<resultset\b[^>]*/>', raw.decode('utf8'), flags=re.S)
if len(parts) != 2:
    raise RuntimeError('Incomplete credential result')
def rows(part):
    root = ET.fromstring(part.encode('utf8'))
    return [{f.attrib['name']: f.text or '' for f in row} for row in root.findall('row')]
users, factors = rows(parts[0]), rows(parts[1])
if set(row['id'] for row in users) != set(ids):
    raise RuntimeError('Selected customer status changed; refusing partial transfer')
credentials = []
for user in users:
    secrets = [factor['secret'] for factor in factors if factor['user'] == user['id']]
    credentials.append({'source_user_id': user['id'], 'hash': user['password'],
                        'requires_mfa': bool(secrets), 'totp_secret': secrets[0] if len(secrets) == 1 else None})
json.dump({'schema_version': 1, 'source_host': args.source_id,
           'captured_at_utc': datetime.datetime.utcnow().isoformat() + 'Z',
           'credentials': credentials}, sys.stdout, ensure_ascii=True)
