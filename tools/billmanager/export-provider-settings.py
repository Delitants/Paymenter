"""Stream explicitly selected native provider settings to an encrypted receiver.

Never redirect stdout to a local file or a log. Run on the authorized source and
pipe directly to the authorized destination's receive-provider-settings.php.
"""
from __future__ import print_function
import argparse
import json
import subprocess
import sys
import xml.etree.ElementTree as ET

parser = argparse.ArgumentParser()
parser.add_argument('--mgrctl', required=True)
parser.add_argument('--manager', required=True)
parser.add_argument('--source-id', required=True)
parser.add_argument('--module-id', action='append', required=True)
args = parser.parse_args()
if any(not ident.isdigit() for ident in args.module_id) or len(set(args.module_id)) != len(args.module_id):
    raise ValueError('Explicit unique numeric module identifiers are required')
fields = ['id', 'module', 'active', 'url', 'login', 'password', 'api_key', 'reseller_id', 'partner_code', 'auth_token']
providers = []
for ident in args.module_id:
    process = subprocess.Popen([args.mgrctl, '-m', args.manager, 'processing.edit', 'elid=' + ident, 'out=xml'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    output, error = process.communicate()
    if process.returncode:
        raise RuntimeError('Provider configuration read failed')
    if b'<!DOCTYPE' in output.upper() or b'<!ENTITY' in output.upper():
        raise RuntimeError('Unexpected provider XML declaration')
    root = ET.fromstring(output)
    if root.find('error') is not None:
        raise RuntimeError('Provider configuration returned an error')
    values = {key: root.findtext(key) for key in fields if root.find(key) is not None}
    if str(values.get('id')) != ident:
        raise RuntimeError('Provider identity mismatch')
    for key in ['password', 'api_key', 'auth_token']:
        if key in values and (not values[key] or set(values[key]) == set('*')):
            raise RuntimeError('Provider credential is absent or masked')
    providers.append(values)
json.dump({'schema_version': 1, 'kind': 'provider_settings', 'source_host': args.source_id, 'providers': providers}, sys.stdout)
