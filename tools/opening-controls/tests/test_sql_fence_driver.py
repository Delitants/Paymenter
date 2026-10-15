from pathlib import Path
import sys
import tempfile
import hashlib
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import sql_fence_driver as d
from sql_fence_schema import SqlFenceError
from sql_fence_fixtures import model

class SqlDriverTests(unittest.TestCase):
    def test_context_cannot_be_constructed_from_profile_paths(self):
        with self.assertRaises(SqlFenceError):d.OwnedSqlContext({'socket':'/tmp/a'})
        class Denied:
            def assert_admitted(self,r):raise SqlFenceError('not-admitted')
        with self.assertRaisesRegex(SqlFenceError,'not-admitted'):d.create_fixture({'socket':'/tmp/a'},Denied())
    def test_actual_server_namespace_socket_and_marker_guard_precedes_sql(self):
        for ctx in (None,{},object()):
            with self.assertRaises(SqlFenceError):d.attempt(ctx,'protected_dml_ddl_denied','writer')
        c=object.__new__(d.OwnedSqlContext);c._seal=None
        with patch('subprocess.Popen',side_effect=AssertionError('must not reach transport')):
            with self.assertRaises(SqlFenceError):d.snapshot(c)
    def test_wrong_sql_error_dead_actor_or_missing_object_is_not_denial(self):
        for code in (None,1064,1045,1146,1205,2002):
            with self.assertRaises(SqlFenceError):d.assert_denial(d.AttemptResult('protected_dml_ddl_denied',1,code,()),frozenset({1044,1142}))
        d.assert_denial(d.AttemptResult('protected_dml_ddl_denied',1,1142,(('fx_writer@localhost',),)),frozenset({1044,1142}))
        with self.assertRaises(SqlFenceError):d.assert_denial(d.AttemptResult('protected_dml_ddl_denied',0,1142,()),frozenset({1044,1142}))
    def test_active_unowned_and_cached_sessions_block_admission(self):
        for rows,expected in (([{'id':2,'user':'fx_writer','command':'Query'}],()),
                              ([{'id':2,'user':'unknown','command':'Sleep'}],()),
                              ([{'id':2,'user':'fx_writer','command':'Sleep'}],()),
                              ([{'id':2,'user':'fx_writer','command':'Sleep'}],({'id':3,'user':'fx_writer'},))):
            with self.assertRaises(SqlFenceError):d._validate_sessions(rows,expected)
        d._validate_sessions([],())
        d._validate_sessions([{'id':2,'user':'fx_writer','command':'Sleep'}],({'id':2,'user':'fx_writer'},))
    def test_snapshot_preserves_raw_grant_and_nonprivilege_bytes(self):
        row={'table':'user','cells':tuple('H'+x.encode().hex().upper() for x in ('localhost','fx_writer','secret'))}
        self.assertEqual(d._cell(row['cells'][2]),b'secret')
        self.assertIsNone(d._cell('N'))
        with self.assertRaises(SqlFenceError):d._cell('Xabc')
        s=d.SqlSnapshot(model(),(row,),'a'*64,'b'*64,'c'*64)
        r=d.snapshot_record(s);r['raw_grant_rows'][0]['cells'][0]='N'
        self.assertEqual(s.raw_grant_rows[0]['cells'][0],'H6C6F63616C686F7374')

    def test_client_option_replacement_or_content_drift_denies_transport(self):
        with tempfile.TemporaryDirectory() as td:
            p=Path(td)/'client.cnf';p.write_bytes(b'[client]\nuser=fx_writer\n');p.chmod(0o600)
            token=(p.stat().st_dev,p.stat().st_ino);h=hashlib.sha256(p.read_bytes()).hexdigest()
            d.guard_option(p,token,h)
            p.write_bytes(b'[client]\nuser=fx_admin\n')
            with self.assertRaises(SqlFenceError):d.guard_option(p,token,h)

    def test_batched_grant_codec_preserves_column_metadata_and_definition_census(self):
        cells=('H6C6F63616C686F7374','H666978747572655F736F75726365','H66785F777269746572','H74303031','H76616C7565','H323032362D30312D30312030303A30303A3030','H557064617465')
        c=object()
        with patch.object(d,'_query',return_value=d.AttemptResult('',0,None,(('columns_priv',*cells),('__definitions__','0')))):
            raw,definitions=d._raw_rows(c)
        self.assertEqual(raw,({'table':'columns_priv','cells':cells},));self.assertEqual(definitions,'0')
        with patch.object(d,'_query',return_value=d.AttemptResult('',0,None,(('unknown',*cells),('__definitions__','0')))):
            with self.assertRaises(SqlFenceError):d._raw_rows(c)

    def test_native_unescaped_database_pattern_is_not_a_literal_schema(self):
        self.assertEqual(d._unpattern(r'fixture\_demo'),'fixture_demo')
        with self.assertRaises(SqlFenceError):d._unpattern('fixture_demo')
