import base64
import importlib
from pathlib import Path
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from source_fixtures import raw_capture,file_record
from source_schema import validate_observation

class InventoryTest(unittest.TestCase):
    def setUp(self):
        try:self.m=importlib.import_module('source_inventory')
        except ModuleNotFoundError:self.fail('source_inventory functionality missing')
    def test_duplicate_jobs_and_positional_selectors_remain_distinct(self):
        line=b'* * * * * /fixture/cron-billmgr daily\n';jobs=self.m.parse_cron(line+line+b'* * * * * /fixture/notify ntemail\n','/fixture/crontab')
        self.assertEqual(len(jobs),3);self.assertNotEqual(jobs[0]['id'],jobs[1]['id']);self.assertEqual(jobs[0]['details']['module'],'');self.assertEqual(jobs[2]['details']['module'],'')
    def test_quoted_comments_and_include_cycles_do_not_invent_routes(self):
        files={'/fixture/main.conf':b'# include ghost;\nhttp { server { set $text "include ignored; # quoted"; include child.conf; } }', '/fixture/child.conf':b'include main.conf;'}
        r=self.m.parse_nginx(files,'/fixture/main.conf');self.assertEqual(len([o for o in r['objects'] if o['kind']=='include']),2);self.assertIn('include-cycle',{e['code'] for e in r['errors']})
    def test_missing_glob_or_unsupported_nginx_syntax_blocks(self):
        for data in [b'include missing/*.conf;',b'http {',b'server { location / { proxy_pass "unterminated; } }']:
            self.assertTrue(self.m.parse_nginx({'/fixture/main.conf':data},'/fixture/main.conf')['errors'])
    def test_alternate_direct_ipv6_php_and_error_routes_are_inventory(self):
        data=b'http { server { listen 81 ssl; listen [::]:1500; server_name alternate.invalid; error_page 497 https://127.0.0.1:1500; location /Payment { fastcgi_pass 127.0.0.1:9000; } location /payment { proxy_pass https://127.0.0.1:1500; } } }'
        r=self.m.parse_nginx({'/fixture/main.conf':data},'/fixture/main.conf');self.assertFalse(r['errors']);d=[o['details'] for o in r['objects']]
        self.assertTrue(any(x['directive']=='error_page' for x in d));self.assertTrue(any(x['args']==['[::]:1500'] for x in d));self.assertTrue(any('/Payment' in x['args'] for x in d));self.assertTrue(any('/payment' in x['args'] for x in d))
    def test_inactive_supplemental_config_does_not_invent_active_routes(self):
        raw=raw_capture();raw['files'].append(file_record('/fixture/inactive.conf','nginx',b'server { listen 19999; }'))
        result=self.m.inventory(raw,{'controller_boot_id':'fixture-controller-boot','capture_start_ns':0,'capture_end_ns':1})
        self.assertFalse(any(o['details'].get('args')==['19999'] for o in result['objects']))

    def test_sql_objects_and_unexplained_privileged_sessions_are_preserved(self):
        raw=raw_capture();r=self.m.inventory(raw,{'controller_boot_id':'fixture-controller-boot','capture_start_ns':0,'capture_end_ns':1_000_000_000});validate_observation(r)
        kinds={o['kind'] for o in r['objects']};self.assertTrue({'sql-principal','sql-session','sql-object','process','listener','job','route'}.issubset(kinds))
        raw['operations']['database']['stdout_b64']=base64.b64encode(b'OBJECT\tBROKEN\n').decode();self.assertTrue(self.m.inventory(raw,{'controller_boot_id':'fixture-controller-boot','capture_start_ns':0,'capture_end_ns':1})['errors'])

    def test_unsupported_routing_directive_blocks_inventory(self):
        parsed=self.m.parse_nginx({'/fixture/main.conf':b'http { server { location / { uwsgi_pass unix:/tmp/app.sock; } } }'},'/fixture/main.conf')
        self.assertTrue(parsed['errors'])

    def test_include_globs_do_not_cross_directories_or_hidden_components(self):
        parsed=self.m.parse_nginx({'/fixture/main.conf':b'include /fixture/conf.d/*.conf;','/fixture/conf.d/inactive/hidden.conf':b'listen 9999;','/fixture/conf.d/.hidden.conf':b'listen 8888;'},'/fixture/main.conf')
        self.assertTrue(parsed['errors'])
        self.assertFalse(any(o['kind']=='listener' for o in parsed['objects']))

if __name__=='__main__':unittest.main()
