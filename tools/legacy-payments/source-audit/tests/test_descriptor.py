import dataclasses
import datetime as dt
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from fixtures import FixtureClock, profile, descriptor_data, write_json

class DescriptorTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('descriptor'), 'descriptor behavior is missing')
        from descriptor import load_descriptor, assert_current
        from models import AuditError
        self.load, self.current, self.Error = load_descriptor, assert_current, AuditError
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name).resolve(); self.root.chmod(0o700)
        self.out = self.root/'out'; self.out.mkdir(mode=0o700)
        self.clock=FixtureClock(); self.data=descriptor_data(self.out,self.clock)
        self.path=self.root/'descriptor.json'; self.save()
    def save(self): write_json(self.path,self.data)
    def test_valid_descriptor_preserves_inputs(self):
        original=self.path.read_bytes(); d=self.load(self.path,profile(),self.clock)
        self.assertEqual(d.account_ids,('101','102')); self.assertEqual(self.path.read_bytes(), original)
        with self.assertRaises(dataclasses.FrozenInstanceError): d.attempt_id='changed'
    def test_cohort_rejects_ambiguous_membership(self):
        for ids in ([],['101','101'],['102','101'],[101],['01'],['0'],['1;DROP'],[str(i) for i in range(1,2050)]):
            with self.subTest(ids=str(ids)[:40]):
                self.data['account_ids']=ids; self.save()
                with self.assertRaises(self.Error): self.load(self.path,profile(),self.clock)
    def test_held_accounts_are_subset(self):
        self.data['held_account_ids']=['999']; self.save()
        with self.assertRaisesRegex(self.Error,'descriptor-cohort'):self.load(self.path,profile(),self.clock)
    def test_json_rejects_duplicate_unknown_and_non_utf8(self):
        valid=self.path.read_bytes()
        for raw in [valid[:-1]+b',"version":1}',valid[:-1]+b',"secret":"never-print"}',b'\xef\xbb\xbf'+valid,b'\xff',b'{"x":NaN}']:
            self.path.write_bytes(raw)
            with self.assertRaises(self.Error) as err:self.load(self.path,profile(),self.clock)
            self.assertNotIn('never-print',str(err.exception))
    def test_profile_hash_mismatch(self):
        self.data['schema_hash']='d'*64;self.save()
        with self.assertRaisesRegex(self.Error,'descriptor-profile'):self.load(self.path,profile(),self.clock)
    def test_insecure_modes_and_symlinks(self):
        self.path.chmod(0o644)
        with self.assertRaises(self.Error):self.load(self.path,profile(),self.clock)
        self.path.chmod(0o600); link=self.root/'link';link.symlink_to(self.path)
        with self.assertRaises(self.Error):self.load(link,profile(),self.clock)
        self.out.chmod(0o755)
        with self.assertRaises(self.Error):self.load(self.path,profile(),self.clock)
    def test_authority_interval_and_expiry(self):
        for seconds in (0,1801):
            self.data['not_before_utc']=self.clock.utc.isoformat()
            self.data['expires_utc']=(self.clock.utc+dt.timedelta(seconds=seconds)).isoformat();self.save()
            with self.assertRaises(self.Error):self.load(self.path,profile(),self.clock)
    def test_final_expiry_and_clock_rollback(self):
        d=self.load(self.path,profile(),self.clock);last=self.current(d,self.clock,None)
        self.clock.advance(301)
        with self.assertRaisesRegex(self.Error,'authority-expired'):self.current(d,self.clock,last)
        self.clock.advance(-302)
        with self.assertRaises(self.Error):self.current(d,self.clock,last)
    def test_descriptor_size_cap(self):
        self.path.write_bytes(b' '*(256*1024+1))
        with self.assertRaises(self.Error):self.load(self.path,profile(),self.clock)
    def test_private_root_pins_open_directory(self):
        from private_io import open_private_root
        with open_private_root(self.out) as root:
            self.out.rename(self.root/'old'); self.out.mkdir(mode=0o700)
            with self.assertRaisesRegex(self.Error,'private-identity'):root.create('owned', b'x')
        self.assertFalse((self.out/'owned').exists())
    def test_exclusive_private_file_creation(self):
        from private_io import open_private_root
        with open_private_root(self.out) as root:
            root.create('one',b'first')
            with self.assertRaises(self.Error):root.create('one',b'second')
            self.assertEqual(root.read('one',10),b'first')
            with self.assertRaises(self.Error):root.read('../descriptor.json',10000)
