import copy
import importlib
from pathlib import Path
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from source_fixtures import observation, capture_profile, coverage_profile,raw_capture

class SchemaTest(unittest.TestCase):
    def setUp(self):
        try:self.s=importlib.import_module('source_schema')
        except ModuleNotFoundError:self.fail('source_schema functionality missing')

    def test_documents_reject_duplicate_unknown_and_invalid_fields(self):
        for raw in [b'{"schema_version":1,"schema_version":1}', b'{"value":NaN}']:
            with self.assertRaises(self.s.SourceError):self.s.parse_document(raw,'source-control-observation')
        for changes in [{'schema_version':True},{'extra':True},{'purpose':'account-opening-fence'}]:
            with self.assertRaises(self.s.SourceError):self.s.validate_observation(observation()|changes)
        self.s.validate_observation(observation())

    def test_serialized_raw_capture_does_not_depend_on_json_key_order(self):
        parsed=self.s.parse_document(self.s.canonical(raw_capture()),'source-control-raw-capture')
        self.assertEqual(parsed['capture_interval']['end_uptime'],11.0)

    def test_object_identity_preserves_duplicate_jobs(self):
        obs=self.s.validate_observation(observation())
        self.assertEqual(len(obs['objects']),2);self.assertNotEqual(obs['objects'][0]['id'],obs['objects'][1]['id'])
        bad=copy.deepcopy(obs);bad['objects'][1]['id']=bad['objects'][0]['id']
        with self.assertRaises(self.s.SourceError):self.s.validate_observation(bad)

    def test_profile_cannot_supply_commands(self):
        for change in [{'shell_command':'must not run'},{'database_schema':"fixture'; DROP TABLE x"},{'read_paths':[{'path':'/outside/conf','kind':'nginx'}]}]:
            with self.assertRaises(self.s.SourceError):self.s.validate_capture_profile(capture_profile()|change)
        self.s.validate_capture_profile(capture_profile())
        profile=coverage_profile(observation());profile['required_controls'].pop()
        with self.assertRaises(self.s.SourceError):self.s.validate_coverage_profile(profile)

    def test_receipt_requires_exact_hash_boot_and_integer_times(self):
        for changes in [{'capture_start_ns':True},{'capture_end_ns':-1},{'controller_boot_id':''},{'payload_sha256':'0'*64},{'raw_capture_sha256':'0'*64}]:
            bad=observation();bad['receipt'].update(changes)
            with self.assertRaises(self.s.SourceError):self.s.validate_observation(bad)
        bad=observation();bad['objects'][0]['details']['module']='changed'
        with self.assertRaises(self.s.SourceError):self.s.validate_observation(bad)

if __name__=='__main__':unittest.main()
