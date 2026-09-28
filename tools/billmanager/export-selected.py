"""Export only selected BILLmanager accounts in one read-only DB snapshot.

Python 2.7/3 compatible. Output is private: use a mode-0600 destination.
No passwords or merchant credentials are exported. Parameters are required.
"""
from __future__ import print_function
import argparse
import binascii
import datetime
import json
import os
import re
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

parser = argparse.ArgumentParser()
parser.add_argument('--source-id', required=True)
parser.add_argument('--login-cutoff', required=True)
parser.add_argument('--auth-log', default='/usr/local/mgr5/var/billmgr.auth.log')
args = parser.parse_args()
datetime.datetime.strptime(args.login_cutoff, '%Y-%m-%d %H:%M:%S')
last_login = {}
for line in open(args.auth_log):
    fields = line.rstrip('\n').split('\t')
    if len(fields) == 5 and fields[0] >= args.login_cutoff:
        match = re.match(r"^(.*)\(([0-9]+)\)$", fields[2])
        login = match.group(1) if match else fields[2]
        last_login[login] = max(fields[0], last_login.get(login, ''))
if not last_login:
    raise RuntimeError('No eligible logins')
hexnames = []
for name in sorted(last_login):
    encoded = name.encode('utf8') if not isinstance(name, bytes) else name
    hexnames.append("CONVERT(0x" + binascii.hexlify(encoded).decode('ascii') + " USING utf8)")
selection = "level=16 AND enabled='on' AND name IN (" + ','.join(hexnames) + ")"
account_filter = ' IN (SELECT account FROM user WHERE ' + selection + ')'
active_items = 'SELECT id FROM item WHERE account' + account_filter + ' AND parent IS NULL AND status IN (1,2,3,5)'
tickets = 'SELECT id FROM ticket WHERE account_client' + account_filter
profiles = 'SELECT id FROM profile WHERE account' + account_filter
payments = 'SELECT p.id FROM payment p JOIN subaccount s ON s.id=p.subaccount WHERE s.account' + account_filter
queries = {
    'accounts': 'SELECT id,name,level,registration_date,country,state,taxexclusive,taxhide,internal FROM account WHERE id' + account_filter,
    'items': 'SELECT id,pricelist,account,period,currency,parent,processingmodule,status,createdate,expiredate,opendate,suspenddate,updatedate,price,autoprolong,autosuspend,employeesuspend,abusesuspend,name,cost,reservedsum,costperiod,costdate,processingnode,fixedprices,scheduledclose FROM item WHERE id IN (' + active_items + ')',
    'addons': 'SELECT id,pricelist,parent,intvalue,enumerationitem,price,boolvalue,cost,costperiod,fixedprices FROM item WHERE parent IN (' + active_items + ')',
    'itemparams': "SELECT item,intname,value FROM itemparam WHERE item IN (" + active_items + ") AND intname IN ('domain','ip','panelid','serverid','username','user.panelid','username.processingmodule','ostempl','ns0','ns1','ns2','ns3','nameserver1','nameserver2','import_itemtype_intname','import_pricelist_intname','import_service_name','import_remote_id','startperioddate')",
    'pricelists': 'SELECT id,name,parent,itemtype,billtype,billdaily,billhourly,billprorata,active,intname,addontype,measure,addonstep,addonmin,addonmax,enumeration,enumerationitem,manualprocessing,chargestoped,autocalcday,allowpostpaid FROM pricelist',
    'prices': 'SELECT * FROM price',
    'pricelistprices': 'SELECT * FROM pricelistprice',
    'fixedprices': 'SELECT * FROM fixedprices',
    'fixedpricesprice': 'SELECT * FROM fixedpricesprice',
    'itemtypes': 'SELECT id,name,intname,parent FROM itemtype',
    'subaccounts': 'SELECT * FROM subaccount WHERE account' + account_filter,
    'currencies': 'SELECT id,iso,name,symbol,active FROM currency',
    'processingmodules': 'SELECT id,name,module,active,failed,datacenter FROM processingmodule',
    'processingnodes': 'SELECT id,name,processingmodule,panelid,ip,failed FROM processingnode',
    'paymethods': 'SELECT id,name,module,active,currency,recurring FROM paymethod',
    'profiles': 'SELECT id,name,person,account,profiletype,email,phone,country_legal,postcode_legal,state_legal,city_legal,address_legal,vatnum,active FROM profile WHERE account' + account_filter,
    'payments': 'SELECT id,subaccount,paymethod,status,number,subaccountamount,paymethodamount,usedamount,commissionamount,paydate,createdate,taxrate,taxamount,currency,externalid,billorder,invoice,refund FROM payment WHERE id IN (' + payments + ')',
    'invoices': 'SELECT id,company,customer,number,cdate,sdate,currency,amount,realamount,invoice_status,revision,fromdate,todate FROM invoice WHERE customer IN (' + profiles + ')',
    'invoiceitems': 'SELECT ii.* FROM invoiceitem ii JOIN invoice i ON i.id=ii.invoice WHERE i.customer IN (' + profiles + ')',
    'tickets': 'SELECT id,name,account_client,date_start,responsible,item,priority,status,date_last,summary,alt_status FROM ticket WHERE id IN (' + tickets + ')',
    'ticket_messages': 'SELECT id,ticket,user,user_delete,message,date_post,date_delete FROM ticket_message WHERE ticket IN (' + tickets + ')',
    'ticket_attachments': 'SELECT a.* FROM ticket_message_attach a JOIN ticket_message m ON m.id=a.ticket_message WHERE m.ticket IN (' + tickets + ')',
    'ticket_notes': 'SELECT * FROM ticket_note WHERE ticket IN (' + tickets + ')',
    'ticket_history': 'SELECT * FROM ticket_history WHERE ticket IN (' + tickets + ')',
}
expenses = 'SELECT id FROM expense WHERE subaccount IN (SELECT id FROM subaccount WHERE account' + account_filter + ')'
selected_invoices = 'SELECT id FROM invoice WHERE customer IN (' + profiles + ')'
selected_invoiceitems = 'SELECT id FROM invoiceitem WHERE invoice IN (' + selected_invoices + ')'
queries.update({
    'expenses': 'SELECT id,subaccount,item,period,discount,amount,discountamount,notpayd,cdate,name,realdate,operation,taxrate,taxamount FROM expense WHERE id IN (' + expenses + ')',
    'expense_payments': 'SELECT * FROM expense2payment WHERE expense IN (' + expenses + ') AND payment IN (' + payments + ')',
    'invoiceitem_expenses': 'SELECT * FROM invoiceitem2expense WHERE invoiceitem IN (' + selected_invoiceitems + ')',
    'invoiceitem_payments': 'SELECT * FROM invoiceitem2payment WHERE invoiceitem IN (' + selected_invoiceitems + ')',
    'payment_refunds': 'SELECT * FROM payment2payment WHERE payment_base IN (' + payments + ') OR payment_refund IN (' + payments + ')',
    'payment_history': 'SELECT * FROM history_payment WHERE reference IN (' + payments + ')',
    'invoice_history': 'SELECT * FROM history_invoice WHERE reference IN (' + selected_invoices + ')',
    'invoiceitem_history': 'SELECT * FROM history_invoiceitem WHERE reference IN (' + selected_invoiceitems + ')',
    'expense_changes': 'SELECT * FROM expensechange WHERE reference IN (' + expenses + ')',
    'discounts': 'SELECT * FROM discount WHERE account' + account_filter + ' OR item IN (' + active_items + ') OR id IN (SELECT discount FROM expense WHERE id IN (' + expenses + ')) OR (account IS NULL AND item IS NULL)',
    'discountprices': 'SELECT * FROM discountprice WHERE discount IN (SELECT id FROM discount WHERE account' + account_filter + ' OR item IN (' + active_items + ') OR id IN (SELECT discount FROM expense WHERE id IN (' + expenses + ')) OR (account IS NULL AND item IS NULL))',
    'ticket_authors': 'SELECT id,realname,level,account FROM user WHERE id IN (SELECT user FROM ticket_message WHERE ticket IN (' + tickets + ') UNION SELECT user FROM ticket_note WHERE ticket IN (' + tickets + ') UNION SELECT user FROM ticket_history WHERE ticket IN (' + tickets + '))',
    'authentication_factors': 'SELECT user,COUNT(*) AS totp_count FROM totp WHERE user IN (SELECT id FROM user WHERE ' + selection + ') GROUP BY user',
})
queries['users'] = "SELECT id,name,account,level,enabled,email,emailverified,emailinvalid,realname,phone,language,timezone,orderpriority FROM user WHERE " + selection
keys = sorted(queries)
# All tables are InnoDB. No temporary tables, DDL or writes occur on the source.
sql = "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; START TRANSACTION WITH CONSISTENT SNAPSHOT;\n"
sql += "SELECT UTC_TIMESTAMP() AS captured_at_utc, @@session.time_zone AS database_timezone;\n"
sql += ';\n'.join(queries[key] for key in keys) + ';\nROLLBACK;\n'
proc = subprocess.Popen(['mysql', '--default-character-set=utf8', '--xml', '--batch', 'billmgr'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
raw, errors = proc.communicate(sql.encode('utf8'))
if proc.returncode:
    raise RuntimeError('Source snapshot failed; mysql exit ' + str(proc.returncode))
raw = raw.decode('utf8')
parts = re.findall(r'<resultset\b.*?</resultset>|<resultset\b[^>]*/>', raw, flags=re.S)
if len(parts) != len(keys) + 1:
    raise RuntimeError('Incomplete snapshot result sets')
def rows(part):
    root = ET.fromstring(part.encode('utf8'))
    return [{f.attrib['name']: None if f.attrib.get('{http://www.w3.org/2001/XMLSchema-instance}nil') == 'true' else (f.text or '') for f in row} for row in root.findall('row')]
metadata = rows(parts[0])[0]
tables = dict((key, rows(part)) for key, part in zip(keys, parts[1:]))
if not tables['users'] or not tables['accounts']:
    raise RuntimeError('Empty selection')
for user in tables['users']:
    user['last_login'] = last_login[user['name']]
zone_path = os.path.realpath('/etc/localtime')
source_timezone = zone_path.split('/zoneinfo/', 1)[1] if '/zoneinfo/' in zone_path else None
result = {'source_timezone': source_timezone, 'schema_version': 1, 'source_host': args.source_id, 'login_cutoff': args.login_cutoff,
          'captured_at_utc': metadata['captured_at_utc'].replace(' ', 'T') + 'Z',
          'database_timezone': metadata['database_timezone'], 'auth_log_timezone': list(time.tzname),
          'date_boundary': 'Inclusive local timestamp as recorded in the authentication log',
          'consistent_snapshot': True, 'tables': tables}
json.dump(result, sys.stdout, ensure_ascii=True)
