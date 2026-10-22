import dataclasses
import importlib.util
from pathlib import Path
import tempfile
import unittest
from fixtures import FixtureClock,profile,descriptor_data,write_json,sample_rows,ScriptedTransport

class AnalysisTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('analyze'),'analysis behavior missing')
        from analyze import parse_decimal,compare
        from models import AuditError,FieldSpec
        from descriptor import load_descriptor
        from query_plan import build_plan
        self.parse,self.compare,self.Error,self.Field=parse_decimal,compare,AuditError,FieldSpec
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        root=Path(self.tmp.name).resolve();root.chmod(0o700);out=root/'out';out.mkdir(mode=0o700)
        self.clock=FixtureClock();self.p=profile();self.d=load_descriptor(write_json(root/'d',descriptor_data(out,self.clock)),self.p,self.clock);self.plan=build_plan(self.d,self.p)
    def collection(self,rows=None):
        from collect import collect
        return collect(self.d,self.p,self.plan,ScriptedTransport(self.d,self.clock,rows),self.clock)
    def test_decimal_preserves_source_and_canonical_without_float(self):
        for raw,want in [('1.2300','1.2300'),('-0.00','0.0000'),('10','10.0000'),('-1.2','-1.2000')]:
            x=self.parse(raw,self.Field(18,4));self.assertEqual(x.original,raw);self.assertEqual(x.canonical,want)
        self.assertEqual(self.parse('2.35',self.Field(9,2)).canonical,'2.35')
    def test_invalid_decimals_refused(self):
        for raw in ['NaN','Infinity','1e2',' 1.00','+1.00','1.00001',1.2,True,'100000000000000.0000']:
            with self.subTest(raw=raw):
                with self.assertRaises(self.Error):self.parse(raw,self.Field(18,4))
    def test_old_low_id_status_change_without_timestamp_detected(self):
        old=self.collection();rows=sample_rows();rows['payments'][0]['status']='4';current=self.collection(rows)
        a=self.compare(current,old,self.d,self.p);c=a.changes['payments:7']
        self.assertEqual(c.kind,'changed');self.assertEqual(c.fields,('status',));self.assertNotEqual(c.previous_hash,c.current_hash)
        self.assertEqual(a.payment_authority['payments:7'],'unqualified')
        self.assertTrue(all(v=='not_evaluated' for v in a.provenance['payments:7'].values()))
        self.assertEqual(old.rows['payments'][0]['status'],'1')
    def test_first_seen_unchanged_and_missing_are_distinct(self):
        current=self.collection();a=self.compare(current,None,self.d,self.p)
        self.assertEqual(a.changes['payments:7'].kind,'first_seen')
        self.assertEqual(self.compare(current,current,self.d,self.p).changes['payments:7'].kind,'unchanged')
        rows=sample_rows();rows['payments']=[];rows['methods']=[]
        missing=self.collection(rows);self.assertEqual(missing.state,'complete')
        a=self.compare(missing,current,self.d,self.p)
        self.assertEqual(a.changes['payments:7'].kind,'missing_since_previous_complete')
        self.assertEqual(a.payment_authority['payments:7'],'unqualified')
    def test_incomplete_or_incompatible_previous_refused(self):
        c=self.collection()
        for prior in [dataclasses.replace(c,state='incomplete'),dataclasses.replace(c,origin='native'),dataclasses.replace(c,profile_binding={'other':'source'})]:
            with self.assertRaises(self.Error):self.compare(c,prior,self.d,self.p)
    def test_flags_unknown_status_and_holds_never_qualify(self):
        rows=sample_rows();rows['subaccounts'][0]['account']='102';rows['payments'][0].update(status='999',link_externalid='present',flag_refund='null')
        a=self.compare(self.collection(rows),None,self.d,self.p)
        self.assertTrue({'elected_hold','source_status_unmapped','link_present','link_unknown','provenance_not_evaluated'}<=set(a.exceptions['payments:7']))
        self.assertEqual(a.payment_authority['payments:7'],'unqualified')
    def test_changed_amount_and_representation_keep_original_hashes(self):
        old=self.collection();rows=sample_rows();rows['payments'][0]['subaccountamount']='11.0000'
        a=self.compare(self.collection(rows),old,self.d,self.p)
        self.assertIn('economics_changed',a.exceptions['payments:7'])
        rows['payments'][0]['subaccountamount']='10.00'
        a=self.compare(self.collection(rows),old,self.d,self.p)
        self.assertEqual(a.changes['payments:7'].kind,'changed')
    def test_bad_money_makes_collection_incomplete(self):
        rows=sample_rows();rows['payments'][0]['subaccountamount']='1e2'
        self.assertEqual(self.collection(rows).state,'incomplete')
