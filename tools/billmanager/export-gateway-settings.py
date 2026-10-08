#!/usr/bin/env python
from __future__ import print_function
import argparse
import json
import os
import subprocess
import sys
import xml.etree.ElementTree as ET

parser = argparse.ArgumentParser(description='Stream selected active gateway settings directly to an encrypted destination receiver')
parser.add_argument('--source', required=True)
parser.add_argument('--id', action='append', required=True)
parser.add_argument('--mgrctl', default='/usr/local/mgr5/sbin/mgrctl')
parser.add_argument('--authorizenet-broker')
parser.add_argument('--include-fees', action='store_true')
parser.add_argument('--fee-database', default='billmgr')
args = parser.parse_args()
if len(set(args.id)) != len(args.id) or any(not x.isdigit() for x in args.id):
    raise RuntimeError('Gateway IDs must be unique numbers')
fields = {
    'pmwebmoney': ['purse', 'secret', 'apiurl', 'currency'],
    'pmauthorizenet': ['currency'],
    'pmklarna': ['merchant_id', 'secret', 'environment', 'callback_url', 'currency'],
    'pmwave': ['wave_access_token', 'wave_business_id', 'wave_customer_id', 'wave_product_id', 'return_url_base', 'currency'],
}
records = []
fees = []
for ident in args.id:
    proc = subprocess.Popen([args.mgrctl, '-m', 'billmgr', 'paymethod.edit', 'elid=' + ident, 'out=xml'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    raw, unused = proc.communicate()
    if proc.returncode:
        raise RuntimeError('Gateway configuration read failed')
    xml = ET.fromstring(raw)
    module = xml.findtext('module')
    if xml.find('error') is not None or module not in fields or xml.findtext('active') != 'on':
        raise RuntimeError('Unexpected gateway module or state')
    settings = dict((key, xml.findtext(key)) for key in fields[module])
    if module == 'pmauthorizenet':
        if not args.authorizenet_broker:
            raise RuntimeError('Explicit Authorize.Net broker path required')
        helper = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'export-authorizenet-settings.php')
        proc = subprocess.Popen(['php', helper, args.authorizenet_broker], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        raw, unused = proc.communicate()
        if proc.returncode:
            raise RuntimeError('Authorize.Net declarations could not be read')
        settings.update(json.loads(raw))
    for key in ['secret', 'wave_access_token', 'transaction_key', 'signature_key']:
        if key in settings and (not settings[key] or set(settings[key]) == set('*')):
            raise RuntimeError('Gateway credential missing or masked')
    if args.include_fees:
        query = 'SELECT id,module,active,currency,commissionpercent,commissionamount FROM paymethod WHERE id=' + ident
        proc = subprocess.Popen(['mysql', '--default-character-set=utf8', '--xml', '--batch', args.fee_database], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        raw, unused = proc.communicate(query.encode('ascii'))
        if proc.returncode:
            raise RuntimeError('Source commission read failed')
        rows = ET.fromstring(raw).findall('row')
        if len(rows) != 1:
            raise RuntimeError('Source commission identity missing or ambiguous')
        values = dict((field.attrib['name'], field.text) for field in rows[0].findall('field'))
        if values.get('id') != ident or values.get('module') != module or values.get('active') not in ('on', '1') or values.get('currency') != settings.get('currency') or any(values.get(key) is None for key in ['commissionpercent', 'commissionamount']):
            raise RuntimeError('Source commission identity or active state changed')
        values['active'] = 'on'
        fees.append(values)
    records.append({'id': ident, 'module': module, 'active': 'on', 'settings': settings})
bundle = {'schema_version': 1, 'kind': 'gateway_settings', 'source_host': args.source, 'gateways': records}
if args.include_fees:
    bundle['fees'] = fees
json.dump(bundle, sys.stdout)
