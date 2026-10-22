import dataclasses
import importlib.util
from pathlib import Path
import tempfile
import unittest
from fixtures import FixtureClock,profile,descriptor_data,write_json

class PlannerTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('query_plan'),'planner behavior missing')
        from query_plan import build_plan
        from descriptor import load_descriptor
        from models import Dataset,AuditError
        self.D,self.Error=Dataset,AuditError
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        root=Path(self.tmp.name).resolve();root.chmod(0o700);out=root/'out';out.mkdir(mode=0o700)
        self.profile=profile();self.d=load_descriptor(write_json(root/'d',descriptor_data(out,FixtureClock())),self.profile,FixtureClock())
        self.plan=build_plan(self.d,self.profile)
    def test_cohort_is_bound_not_interpolated(self):
        q=self.plan.page(self.D.ACCOUNTS,None)
        self.assertEqual(q.sql,'SELECT a.`id` FROM `account` a WHERE a.`id` IN (%s,%s) ORDER BY a.`id` LIMIT %s')
        self.assertEqual(q.params,('101','102',256));self.assertEqual(q.limit,256)
    def test_keyset_uses_id_without_status_or_time_filter(self):
        q=self.plan.page(self.D.PAYMENTS,'7')
        self.assertEqual(q.params,('101','102','7',256))
        self.assertIn('AND p.`id` > %s ORDER BY p.`id` LIMIT %s',q.sql)
        self.assertNotIn('status` =',q.sql);self.assertNotIn('paydate` >',q.sql)
    def test_methods_count_is_scoped_and_distinct(self):
        q=self.plan.count(self.D.METHODS)
        self.assertEqual(q.sql,'SELECT COUNT(m.`id`) AS `count` FROM `paymethod` m WHERE m.`id` IN (SELECT DISTINCT p.`paymethod` FROM `payment` p JOIN `subaccount` s ON s.`id`=p.`subaccount` WHERE s.`account` IN (%s,%s))')
        self.assertEqual(q.params,('101','102'))
    def test_sensitive_links_are_flags_only(self):
        q=self.plan.page(self.D.PAYMENTS,None)
        self.assertIn("ELSE 'present' END AS `link_externalid`",q.sql)
        self.assertIn("ELSE 'invalid' END AS `flag_refund`",q.sql)
        for forbidden in ('SELECT *','history','email','password','p.`description`','p.`xmlparams`,'):
            self.assertNotIn(forbidden,q.sql)
    def test_count_and_pages_share_exact_scope(self):
        for dataset in self.D:
            with self.subTest(dataset=dataset):
                c=self.plan.count(dataset);p=self.plan.page(dataset,None)
                self.assertEqual(c.sql.split(' FROM ',1)[1],p.sql.split(' FROM ',1)[1].rsplit(' ORDER BY ',1)[0])
                self.assertEqual(c.params,p.params[:-1])
    def test_bad_cursor_dataset_and_profile_refused(self):
        from query_plan import build_plan
        for value in ('0','7;DROP',7,'01'):
            with self.assertRaises(self.Error):self.plan.page(self.D.PAYMENTS,value)
        with self.assertRaises(self.Error):self.plan.count('arbitrary')
        with self.assertRaises(self.Error):build_plan(self.d,dataclasses.replace(self.profile,query_hash='f'*64))
