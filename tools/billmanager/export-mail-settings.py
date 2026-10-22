"""Stream only SMTP configuration to the encrypted destination receiver."""
from __future__ import print_function
import argparse, json, shlex, sys

p = argparse.ArgumentParser()
p.add_argument('--config', required=True)
p.add_argument('--source-id', required=True)
a = p.parse_args()
values = {}
for line in open(a.config):
    key = line.strip().split(None, 1)
    if not key or key[0] not in ('MailMode', 'SMTPServer', 'SMTPPort', 'SMTPUser', 'SMTPPass'):
        continue
    parts = shlex.split(line)
    if len(parts) != 2: raise ValueError('Ambiguous SMTP configuration syntax')
    values[parts[0]] = parts[1]
if values.get('MailMode') != 'smtp': raise ValueError('Source mail mode is not SMTP')
if any(not values.get(k) for k in ('SMTPServer', 'SMTPPort', 'SMTPUser', 'SMTPPass')):
    raise ValueError('Incomplete source SMTP configuration')
port = int(values['SMTPPort'])
if port not in (465, 587): raise ValueError('SMTP transport requires explicit review')
json.dump({'schema_version': 1, 'kind': 'mail_settings', 'source_host': a.source_id, 'settings': {
    'mail_host': values['SMTPServer'], 'mail_port': str(port), 'mail_username': values['SMTPUser'],
    'mail_password': values['SMTPPass'], 'mail_encryption': 'ssl' if port == 465 else 'tls',
}}, sys.stdout)
