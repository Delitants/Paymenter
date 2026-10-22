"""Authored v2 relational/core coverage; no MySQL, native issuer or live CLI."""
from pathlib import Path
import subprocess
import tempfile
import unittest
import test_manual_financial_reader as v1_tests

HERE=Path(__file__).resolve().parent
NATIVE=HERE.parent/'native'


class ManualFinancialV2Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='manual-v2-',dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary=Path(cls.temp.name)/'driver'
        cls.compiled=None
        if (NATIVE/'manual_financial_reader_v2_core.hpp').exists():
            cls.compiled=subprocess.run(['/usr/bin/c++','-std=c++11','-Wall','-Wextra','-Werror',
                str(HERE/'manual_financial_reader_v2_driver.cpp'),'-o',str(cls.binary)],capture_output=True,timeout=30)

    def setUp(self):
        self.assertIsNotNone(self.compiled,'v2 core is not implemented')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))

    def scenario(self,name):
        p=subprocess.run([str(self.binary),name],capture_output=True,timeout=5)
        self.assertEqual(0,p.returncode,p.stderr.decode(errors='replace'))

    def sql(self,max_users=False):
        return subprocess.run([str(self.binary),'emit-query8' if max_users else 'emit-query'],capture_output=True,check=True,timeout=5).stdout.decode()

    def database(self):
        # Reuse only fictional physical tables/data; no v1 implementation edits.
        db=v1_tests.ManualFinancialSQLTests.database(self)
        db.execute('ALTER TABLE paymethod ADD COLUMN allowrefund TEXT')
        db.execute("UPDATE paymethod SET xmlparams='<doc/>',allowrefund='off'")
        db.execute('ALTER TABLE paymethod2project ADD COLUMN project TEXT')
        db.execute("INSERT INTO paymethod2project (paymethod,project) VALUES ('501','301')")
        db.create_function('QA_BYTES',1,lambda x:None if x is None else x if isinstance(x,bytes) else x.encode())
        db.create_function('OCTET_LENGTH',1,lambda x:None if x is None else len(x if isinstance(x,bytes) else x.encode()))
        return db

    def rows(self,db,max_users=False):
        # Explicit test dialect adapter: SQLite BINARY cast is numeric, not bytes.
        query=self.sql(max_users).replace('CAST(paymethod.xmlparams AS BINARY)','QA_BYTES(paymethod.xmlparams)')
        return db.execute(query).fetchall()

    def validate(self,rows,max_users=False):
        payload=''.join('\t'.join(str(c) for c in row)+'\n' for row in rows).encode()
        return subprocess.run([str(self.binary),'validate-input8' if max_users else 'validate-input'],input=payload,capture_output=True,timeout=5).returncode==0

    def test_exact_profile_and_owned_project(self):
        for xml,want in (('<doc/>','N|X:authored-empty-doc-v0|N'),(None,'N|N|N'),('','N|E|N')):
            with self.subTest(xml=xml), self.database() as db:
                db.execute('UPDATE paymethod SET xmlparams=?',(xml,))
                rows=self.rows(db)
                self.assertTrue(self.validate(rows))
                self.assertEqual(want,next(r[3] for r in rows if r[:3]==('method','501','links')))

    def test_eight_user_request_is_observed_and_accepted(self):
        self.scenario('eight-users')
        with self.database() as db:
            for n in range(803,809):
                db.execute("INSERT INTO user (id,account,enabled,remotelevel,level) VALUES (?, '101','off','0','29')",(str(n),))
            rows=self.rows(db,max_users=True)
            self.assertTrue(self.validate(rows,max_users=True))
            self.assertEqual(8,len({row[1] for row in rows if row[0]=='user'}))
            self.assertLess(len(rows),128)

    def test_metadata_and_session_drift_after_financial_statement_refuses(self):
        for mode in ('late-schema-drift','late-fk-drift','late-session-drift'):
            with self.subTest(mode=mode):self.scenario(mode)

    def test_xml_byte_variants_and_foreign_project(self):
        variants=(' <doc/>','<doc/>\n','<doc />','<DOC/>','<doc></doc>',
            '<?xml version="1.0"?><doc/>','<!--x--><doc/>','<doc a="x"/>',
            '<doc><child/></doc>','<!DOCTYPE doc><doc/>','<!ENTITY x "x"><doc/>',
            '<?probe x?><doc/>','<doc/>\0','<doc/','<doc>é</doc>',b'<doc/> ',b'<doc/>\x00')
        for xml in variants:
            with self.subTest(xml=xml), self.database() as db:
                db.execute('UPDATE paymethod SET xmlparams=?',(xml,))
                rows=self.rows(db)
                self.assertFalse(self.validate(rows))
                self.assertEqual('N|P|N',next(r[3] for r in rows if r[:3]==('method','501','links')))
                self.assertNotIn(str(xml),str(rows))
        mutations=("DELETE FROM paymethod2project", "UPDATE paymethod2project SET project='999'",
            'UPDATE paymethod2project SET project=NULL',
            "INSERT INTO paymethod2project VALUES ('501','301')",
            "INSERT INTO paymethod2project VALUES ('501','999')")
        for sql in mutations:
            with self.subTest(sql=sql), self.database() as db:
                db.execute(sql);self.assertFalse(self.validate(self.rows(db)))

    def test_refund_and_other_fields_remain_closed(self):
        for value in ('on',None,''):
            with self.subTest(value=value), self.database() as db:
                db.execute('UPDATE paymethod SET allowrefund=?',(value,))
                self.assertFalse(self.validate(self.rows(db)))
        for table,field in (('payment','xmlparams'),('paymethod','profiletype'),('paymethod','receipt_profiletype')):
            with self.subTest(table=table,field=field), self.database() as db:
                db.execute('UPDATE '+table+' SET '+field+'=?',('sensitive-not-exported',))
                rows=self.rows(db);self.assertFalse(self.validate(rows))
                self.assertNotIn('sensitive-not-exported',str(rows))
        for table,column in (('paymethod2company','paymethod'),('paymethod2group','paymethod'),
                             ('paymethodparam','paymethod'),('payment2payment','payment_base')):
            with self.subTest(table=table), self.database() as db:
                db.execute('INSERT INTO '+table+' ('+column+') VALUES (?)',('1001' if column=='payment_base' else '501',))
                self.assertFalse(self.validate(self.rows(db)))
        with self.database() as db:
            db.execute('ALTER TABLE paymethod RENAME COLUMN allowrefund TO absent')
            with self.assertRaises(Exception):self.rows(db)

    def test_version_cannot_downgrade_or_activate_native(self):
        for mode in ('v1-version','unknown-version','unknown-profile'):
            with self.subTest(mode=mode):self.scenario(mode)

    def test_same_view_and_limits(self):
        for mode in v1_tests.SCENARIOS+('refund','foreign-project','missing-project','xml-presence'):
            with self.subTest(mode=mode):self.scenario(mode)
        with self.database() as db:
            db.execute("UPDATE payment SET subaccount='999' WHERE id='1001'")
            rows=self.rows(db)
            self.assertFalse(any(r[0]=='payment' and r[1]=='1001' for r in rows))
            self.assertFalse(self.validate(rows))
