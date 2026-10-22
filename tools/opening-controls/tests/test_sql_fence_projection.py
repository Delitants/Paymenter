from dataclasses import replace
from pathlib import Path
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from sql_fence_schema import SqlFenceError,GrantRow,Principal,DefinerObject,BusinessRequirement
from sql_fence_projection import compile_projection,apply_operation,identifier,database_pattern,semantic_hash
from sql_fence_fixtures import model,preserved,requirements

class SqlProjectionTests(unittest.TestCase):
    def projection(self,m=None,req=None):
        return compile_projection(m or model(),'fixture_source',preserved(),req or requirements())
    def test_denies_protected_and_global_escape_capabilities(self):
        p=self.projection();roles={(x.user,x.host):x.role for x in p.held.principals}
        for g in p.held.grants:
            if roles[g.principal]=='administrator':continue
            self.assertNotEqual(g.level,'global')
            self.assertFalse(set(g.privileges)&{'FILE','SUPER','CREATE USER','GRANT OPTION','CREATE TABLESPACE'})
            if g.target[0]=='fixture_source':self.assertEqual(g.privileges,('SELECT',))
        self.assertEqual(p.before,model());self.assertNotEqual(semantic_hash(p.before),semantic_hash(p.held))
    def test_preserves_exact_named_business_privileges(self):
        p=self.projection()
        expected={('fx_shared','fixture_legacy'):('ALTER','CREATE','DELETE','DROP','INDEX','INSERT','SELECT','UPDATE'),
                  ('fx_shared','fixture_web'):('DELETE','INSERT','SELECT','UPDATE'),
                  ('fx_writer','fixture_tracking'):('DELETE','INSERT','SELECT','UPDATE')}
        actual={(g.principal[0],g.target[0]):g.privileges for g in p.held.grants if g.target and g.target[0] in preserved()}
        self.assertEqual(actual,expected)
        bad=requirements()+(BusinessRequirement('fixture_tracking','writer',('FILE',)),)
        with self.assertRaises(SqlFenceError):self.projection(req=bad)
    def test_unsafe_proxy_definer_mysql_admin_and_unknown_graph_rejected(self):
        m=model()
        for extra in (GrantRow(('fx_writer','localhost'),'proxy',('fx_admin','localhost'),('PROXY',)),
                      GrantRow(('fx_writer','localhost'),'table',('mysql','user'),('SELECT',)),
                      GrantRow(('fx_writer','localhost'),'global',(),('SYSTEM_USER',))):
            with self.assertRaises(SqlFenceError):self.projection(replace(m,grants=m.grants+(extra,)))
        with self.assertRaises(SqlFenceError):self.projection(replace(m,definitions=(DefinerObject('fixture_web','view',('fx_admin','localhost'),'0'*64),)))
    def test_host_pattern_override_and_missing_scope_rejected(self):
        m=model()
        bad=replace(m,principals=m.principals+(Principal('fx_writer','%','administrator'),))
        with self.assertRaises(SqlFenceError):self.projection(bad)
        with self.assertRaises(SqlFenceError):compile_projection(m,'fixture_source',('fixture_absent',*preserved()[1:]),requirements())
        weak=replace(m,grants=tuple(g for g in m.grants if g.principal[0]!='fx_shared'))
        with self.assertRaises(SqlFenceError):self.projection(weak)
    def test_identifier_and_database_pattern_escape_are_distinct(self):
        self.assertEqual(identifier('a`b'),'`a``b`')
        self.assertEqual(database_pattern('fixture_pct%'),r'fixture\_pct\%')
        self.assertEqual(database_pattern(r'a\_b%'),r'a\\\_b\%')
        self.assertEqual(identifier('fixture_pct%'),'`fixture_pct%`')
        with self.assertRaises(SqlFenceError):identifier('bad\0')
    def test_restore_model_is_exact_and_idempotent(self):
        p=self.projection();state=p.before
        for op in p.establish:state=apply_operation(state,op)
        self.assertEqual(state,p.held)
        for op in p.establish:state=apply_operation(state,op)
        self.assertEqual(state,p.held)
        for op in p.restore:state=apply_operation(state,op)
        self.assertEqual(state,p.before)
        for op in p.restore:state=apply_operation(state,op)
        self.assertEqual(state,p.before)
