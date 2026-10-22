import dataclasses
import importlib.util
from pathlib import Path
import tempfile
import unittest
from fixtures import FixtureClock,profile,descriptor_data,write_json,sample_rows,ScriptedTransport

class CollectionTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('collect'),'collector behavior missing')
        from collect import collect
        from query_plan import build_plan
        from descriptor import load_descriptor
        self.collect=collect
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        root=Path(self.tmp.name).resolve();root.chmod(0o700);out=root/'out';out.mkdir(mode=0o700)
        self.clock=FixtureClock();self.p=profile();self.d=load_descriptor(write_json(root/'d',descriptor_data(out,self.clock)),self.p,self.clock)
        self.plan=build_plan(self.d,self.p)
    def run_case(self,rows=None,fault=None):
        t=ScriptedTransport(self.d,self.clock,rows,fault);r=self.collect(self.d,self.p,self.plan,t,self.clock)
        return r,t
    def test_complete_requires_full_coverage_and_lifecycle(self):
        r,t=self.run_case();self.assertEqual(r.state,'complete');self.assertEqual(r.origin,'fictional')
        self.assertEqual(r.coverage['payments'],1);self.assertEqual(t.calls[-3:],['rollback','postflight','close'])
        self.assertEqual(t.calls.count('open'),1)
    def test_cap_plus_one_stops_before_paging(self):
        import json
        def fault(op,r):
            if op=='count:payments':return dataclasses.replace(r,raw_bytes=b'{"count":"50001"}')
            return r
        r,t=self.run_case(fault=fault);self.assertEqual(r.state,'incomplete');self.assertNotIn('page:payments',t.calls)
    def test_exact_currency_cap_requires_empty_exhaustion_page(self):
        rows=sample_rows();rows['currencies']=[dict(id=str(n),iso='USD',active='on') for n in range(1,257)]
        # Unreferenced currencies are separately invalid; this test checks exhaustion first.
        r,t=self.run_case(rows);self.assertEqual(t.calls.count('page:currencies'),2)
        self.assertEqual(r.state,'incomplete')
    def test_missing_eof_and_bad_identity(self):
        for mode in ('eof','session','digest','schema'):
            with self.subTest(mode=mode):
                def fault(op,r):
                    if op=='page:payments':
                        if mode=='eof':return dataclasses.replace(r,eof=False)
                        if mode=='session':return dataclasses.replace(r,session_id='other')
                        if mode=='digest':return dataclasses.replace(r,query_hash='f'*64)
                        if mode=='schema':return dataclasses.replace(r,raw_bytes=b'{"rows":[]}')
                    return r
                result,_=self.run_case(fault=fault);self.assertEqual(result.state,'incomplete')
    def test_wrong_ownership_duplicates_and_missing_links(self):
        for mode in ('account','duplicate','missing','dangling','fields','amount'):
            with self.subTest(mode=mode):
                rows=sample_rows()
                if mode=='account':rows['subaccounts'][0]['account']='999'
                if mode=='duplicate':rows['payments'].append(dict(rows['payments'][0]))
                if mode=='missing':rows['accounts'].pop()
                if mode=='dangling':rows['payments'][0]['subaccount']='999'
                if mode=='fields':del rows['payments'][0]['flag_refund']
                if mode=='amount':rows['payments'][0]['usedamount']='NaN'
                r,_=self.run_case(rows);self.assertEqual(r.state,'incomplete')
    def test_session_schema_engine_and_isolation_must_match(self):
        for mode in ('engine','postflight','isolation'):
            def fault(op,r):
                if mode=='engine' and op=='open':return dataclasses.replace(r,engines=('MyISAM',)*5)
                if mode=='postflight' and op=='postflight':return dataclasses.replace(r,schema_hash='f'*64)
                if mode=='isolation' and op=='begin':return dataclasses.replace(r,read_only=False)
                return r
            r,_=self.run_case(fault=fault);self.assertEqual(r.state,'incomplete')
    def test_deadlines_without_retry_or_fabricated_retirement(self):
        for phase,delay in [('open',11),('begin',31),('page:payments',4),('rollback',31),('close',11)]:
            with self.subTest(phase=phase):
                self.clock=FixtureClock()
                def fault(op,r):
                    if op==phase:self.clock.advance(delay)
                    return r
                r,t=self.run_case(fault=fault);self.assertEqual(r.state,'incomplete');self.assertEqual(t.calls.count('open'),1)
    def test_cleanup_failure_always_closes_and_retains_partial(self):
        for phase in ('begin','page:payments','rollback','postflight'):
            def fault(op,r):
                if op==phase:raise OSError('private injected error')
                return r
            r,t=self.run_case(fault=fault);self.assertEqual(r.state,'incomplete');self.assertEqual(t.calls[-1],'close')
            self.assertNotIn('private',str(r.reasons))
    def test_unknown_close_is_incomplete(self):
        def fault(op,r):return dataclasses.replace(r,state='unknown') if op=='close' else r
        r,_=self.run_case(fault=fault);self.assertEqual(r.state,'incomplete')
    def test_row_and_metadata_caps(self):
        for phase,payload in [('page:payments',b'"'+b'x'*4097+b'"'),('open',None)]:
            def fault(op,r):
                if op==phase:
                    if phase=='open':return dataclasses.replace(r,socket_identity='x'*65537)
                    return dataclasses.replace(r,raw_bytes=payload)
                return r
            r,_=self.run_case(fault=fault);self.assertEqual(r.state,'incomplete')
    def test_refused_plan_makes_no_transport_calls(self):
        bad=dataclasses.replace(self.plan,profile_hash='f'*64)
        t=ScriptedTransport(self.d,self.clock)
        r=self.collect(self.d,self.p,bad,t,self.clock)
        self.assertEqual(r.state,'incomplete');self.assertEqual(t.calls,[])
    def test_exact_referenced_currency_cap_completes(self):
        rows=sample_rows();base=rows['subaccounts'][0]
        rows['subaccounts']=[dict(base,id=str(201+n),currency=str(1+n)) for n in range(256)]
        rows['currencies']=[dict(id=str(n),iso='USD',active='on') for n in range(1,257)]
        r,t=self.run_case(rows);self.assertEqual(r.state,'complete')
        self.assertEqual(t.calls.count('page:currencies'),2)
    def test_accumulated_business_bytes_enforced(self):
        from unittest.mock import patch
        with patch('collect.BUSINESS_CAP',1000):
            r,_=self.run_case();self.assertEqual(r.state,'incomplete');self.assertIn('result-cap',r.reasons)
