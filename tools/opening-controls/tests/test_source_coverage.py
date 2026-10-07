import copy
import importlib
from pathlib import Path
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from source_fixtures import observation,coverage_profile
from source_schema import SourceError,digest

class CoverageTest(unittest.TestCase):
    def setUp(self):
        try:self.m=importlib.import_module('source_coverage')
        except ModuleNotFoundError:self.fail('source_coverage functionality missing')
    def evaluate(self,obs=None,profile=None,boot='fixture-controller-boot',now=2_000_000_000):
        obs=obs or observation();return self.m.evaluate(obs,coverage_profile(obs) if profile is None else profile,boot,now)
    def test_complete_inventory_still_has_no_enforcement(self):
        r=self.evaluate();self.assertTrue(r['inventory_complete']);self.assertFalse(r['enforcement_complete']);self.assertFalse(r['real_execution_ready']);self.assertEqual(len([b for b in r['blockers'] if b['code']=='enforcement-unproved']),7)
    def test_unknown_duplicate_missing_and_changed_assignments_block(self):
        for change in ['missing','changed','unknown']:
            p=coverage_profile(observation())
            if change=='missing':p['assignments'].pop()
            elif change=='changed':p['assignments'][0]['sha256']='0'*64
            else:p['assignments'][0]['object_id']='job:unknown'
            self.assertFalse(self.evaluate(profile=p)['inventory_complete'])
        p=coverage_profile(observation());p['assignments'].append(copy.deepcopy(p['assignments'][0]))
        with self.assertRaises(SourceError):self.evaluate(profile=p)
    def test_stale_skewed_or_restarted_receipt_blocks(self):
        for boot,now in [('new-controller-boot',2_000_000_000),('fixture-controller-boot',62_000_000_000),('fixture-controller-boot',-1),('fixture-controller-boot',0)]:
            self.assertFalse(self.evaluate(boot=boot,now=now)['inventory_complete'])
        obs=observation();obs['capture_interval']={'start_uptime':99999999.0,'end_uptime':100000000.0};obs['receipt']['payload_sha256']=digest({k:v for k,v in obs.items() if k!='receipt'})
        self.assertTrue(self.evaluate(obs=obs)['inventory_complete'])
    def test_unaccepted_profile_is_not_generated_implicitly(self):
        r=self.m.evaluate(observation(),None,'fixture-controller-boot',2_000_000_000);self.assertFalse(r['inventory_complete']);self.assertIn('profile-unaccepted',{b['code'] for b in r['blockers']})

if __name__=='__main__':unittest.main()
