"""Fictional configurations exercise inventory, never live nginx validation."""
import sys
from pathlib import Path
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from source_inventory import parse_nginx


class NginxContextTest(unittest.TestCase):
    def parse(self, body, extra=None):
        files = {'/fixture/main.conf': body}
        files.update(extra or {})
        return parse_nginx(files, '/fixture/main.conf')

    def test_mime_rows_are_recorded_only_inside_valid_types_context(self):
        parsed = self.parse(b'http { types { text/html html htm; application/json json; } }')
        self.assertEqual(parsed['errors'], [])
        rows = [o['details'] for o in parsed['objects'] if o['details'].get('role') == 'mime-entry']
        self.assertEqual([(r['directive'], r['args']) for r in rows], [('text/html', ['html', 'htm']), ('application/json', ['json'])])

    def test_known_directives_cannot_masquerade_as_mime_rows(self):
        parsed = self.parse(b'http { types { listen 8080; } }')
        self.assertIn('unsupported-nginx-context', {e['code'] for e in parsed['errors']})
        self.assertFalse(any(o['kind'] == 'listener' for o in parsed['objects']))

    def test_invalid_types_parent_and_arguments_are_blocked(self):
        for body in [b'types { text/html html; }', b'http { types extra { text/html html; } }', b'http { types { text/html; } }']:
            with self.subTest(body=body):
                parsed = self.parse(body)
                self.assertIn('unsupported-nginx-context', {e['code'] for e in parsed['errors']})

    def test_static_root_alias_and_index_preserve_filesystem_route_evidence(self):
        parsed = self.parse(b'http { server { root /fixture/www; location /assets/ { alias /fixture/static/; index home.html fallback.html; } } }')
        self.assertEqual(parsed['errors'], [])
        rows = [o['details'] for o in parsed['objects'] if o['details']['directive'] in ('root', 'alias', 'index')]
        self.assertEqual([(r['directive'], r['args']) for r in rows], [('root', ['/fixture/www']), ('alias', ['/fixture/static/']), ('index', ['home.html', 'fallback.html'])])
        self.assertEqual(rows[1]['context'], [['http'], ['server'], ['location', '/assets/']])

    def test_dynamic_filesystem_paths_remain_unproved(self):
        parsed = self.parse(b'http { server { root /fixture/$tenant; location / { alias /fixture/${tenant}/; index $page; } } }')
        self.assertEqual([e['code'] for e in parsed['errors']], ['routing-expression-unproved'] * 3)
        self.assertEqual(len([o for o in parsed['objects'] if o['details']['directive'] in ('root', 'alias', 'index')]), 3)

    def test_path_directives_reject_wrong_context_and_arity(self):
        for body in [b'http { alias /fixture/data; }', b'http { root /fixture/a /fixture/b; }', b'http { server { location / { index; } } }']:
            with self.subTest(body=body):
                self.assertIn('unsupported-nginx-context', {e['code'] for e in self.parse(body)['errors']})

    def test_empty_quoted_header_value_is_not_lost(self):
        parsed = self.parse(b'http { server { location / { proxy_set_header Connection ""; } } }')
        self.assertEqual(parsed['errors'], [])
        rows = [o['details'] for o in parsed['objects'] if o['details']['directive'] == 'proxy_set_header']
        self.assertEqual([r['args'] for r in rows], [['Connection', '']])

    def test_quoted_punctuation_remains_data_instead_of_block_syntax(self):
        parsed = self.parse(b'http { map $host $value { default ";"; "}" "{"; } server { location / { proxy_set_header Example "}"; } } }')
        self.assertEqual([e['code'] for e in parsed['errors']], ['routing-expression-unproved'])
        rows = [o['details'] for o in parsed['objects'] if o['details'].get('role') == 'map-entry']
        self.assertEqual([(r['directive'], r['args']) for r in rows], [('default', [';']), ('}', ['{'])])

    def test_request_headers_and_access_policy_are_route_evidence(self):
        parsed = self.parse(b'http { server { location / { proxy_set_header Host fixed.invalid; allow 192.0.2.0/24; deny all; } } }')
        self.assertEqual(parsed['errors'], [])
        self.assertEqual([o['details']['directive'] for o in parsed['objects'] if o['details']['directive'] in ('proxy_set_header', 'allow', 'deny')], ['proxy_set_header', 'allow', 'deny'])

    def test_dynamic_header_values_and_invalid_access_rules_are_blocked(self):
        parsed = self.parse(b'http { server { location / { proxy_set_header Host $host; allow not-an-address; } } }')
        self.assertEqual([e['code'] for e in parsed['errors']], ['routing-expression-unproved', 'unsupported-nginx-context'])

    def test_map_and_geo_rows_keep_parent_context_and_dynamic_denial(self):
        parsed = self.parse(b'http { map $host $backend { default fallback.invalid; "" empty.invalid; special.invalid chosen.invalid; } geo $blocked { default 0; 192.0.2.0/24 1; } }')
        self.assertEqual([e['code'] for e in parsed['errors']], ['routing-expression-unproved'] * 2)
        rows = [o['details'] for o in parsed['objects'] if o['details'].get('role') in ('map-entry', 'geo-entry')]
        self.assertEqual([(r['directive'], r['args']) for r in rows], [('default', ['fallback.invalid']), ('', ['empty.invalid']), ('special.invalid', ['chosen.invalid']), ('default', ['0']), ('192.0.2.0/24', ['1'])])
        self.assertEqual(rows[-1]['context'], [['http'], ['geo', '$blocked']])

    def test_mapping_includes_use_same_context_and_only_captured_files(self):
        parsed = self.parse(b'http { types { include rows.conf; } map $host $route { include missing.conf; } }', {'/fixture/rows.conf': b'text/html html;'})
        self.assertEqual([e['code'] for e in parsed['errors']], ['routing-expression-unproved', 'include-unavailable'])
        rows = [o['details'] for o in parsed['objects'] if o['details'].get('role') == 'mime-entry']
        self.assertEqual(rows[0]['context'], [['http'], ['types']])

    def test_reviewed_tls_and_transport_settings_are_retained(self):
        parsed = self.parse(b'http { sendfile on; proxy_buffers 8 16k; server { ssl_certificate /fixture/public.pem; ssl_certificate_key /fixture/key.pem; location / { proxy_read_timeout 30s; proxy_buffering off; } } }')
        self.assertEqual(parsed['errors'], [])
        names = {o['details']['directive'] for o in parsed['objects']}
        self.assertTrue({'sendfile', 'proxy_buffers', 'ssl_certificate', 'ssl_certificate_key', 'proxy_read_timeout', 'proxy_buffering'}.issubset(names))

    def test_invalid_block_forms_known_contexts_and_unknown_upstream_remain_blocked(self):
        for body in [b'http;', b'http { server; }', b'http { server { listen 80 { } } }', b'http { server { upstream fixture { server 127.0.0.1:8080; } } }', b'http { upstream fixture { mystery_route opaque; } }', b'http { server { location / { root /fixture; } }']:
            with self.subTest(body=body):
                self.assertTrue(self.parse(body)['errors'])

    def test_compact_regex_locations_remain_unproved(self):
        for selector in [b'~^/private', b'~*^/private']:
            with self.subTest(selector=selector):
                parsed = self.parse(b'http { server { location ' + selector + b' { root /fixture/www; } } }')
                self.assertEqual([e['code'] for e in parsed['errors']], ['routing-pattern-unproved'])

    def test_rewrite_and_return_argument_counts_are_bounded(self):
        for line in [b'rewrite ^/old;', b'rewrite ^/old /new last extra;', b'return 301 /new extra;']:
            with self.subTest(line=line):
                parsed = self.parse(b'http { server { ' + line + b' } }')
                self.assertEqual([e['code'] for e in parsed['errors']], ['unsupported-nginx-context'])

    def test_location_modifier_must_match_a_reviewed_form(self):
        parsed = self.parse(b'http { server { location wrong /private { root /fixture/www; } } }')
        self.assertTrue(parsed['errors'])
        self.assertFalse(any(o['details']['directive'] == 'root' for o in parsed['objects']))

    def test_map_key_named_listen_does_not_create_a_listener(self):
        parsed = self.parse(b'http { map $host $out { listen backend.invalid; } }')
        self.assertEqual([e['code'] for e in parsed['errors']], ['routing-expression-unproved'])
        self.assertFalse(any(o['kind'] == 'listener' for o in parsed['objects']))
        rows = [o for o in parsed['objects'] if o['details'].get('role') == 'map-entry']
        self.assertEqual(rows[0]['details']['args'], ['backend.invalid'])

    def test_inventory_preserves_nginx_escape_values(self):
        parsed = self.parse(b'http { server { root "/fixture/dir\\q"; location / { proxy_set_header Example "a\\nb\\tc\\rd\\q"; } } }')
        self.assertEqual(parsed['errors'], [])
        rows = [o['details'] for o in parsed['objects'] if o['details']['directive'] in ('root', 'proxy_set_header')]
        self.assertEqual([r['args'] for r in rows], [['/fixture/dir\\q'], ['Example', 'a\nb\tc\rd\\q']])


if __name__ == '__main__':
    unittest.main()
