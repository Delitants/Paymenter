import copy
import hashlib
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from source_schema import canonical
from sql_fence_schema import (SqlFenceError,parse_plan,parse_seed,model_record,SqlDocuments,FixturePlan)
from sql_fence_fixtures import plan_record,seed_record,model,requirements

class SqlSchemaTests(unittest.TestCase):
    def test_closed_plan_and_seed(self):
        self.assertEqual(parse_plan(canonical(plan_record()))['purpose'],'fictional-sql-privilege-rehearsal')
        m=model();self.assertEqual({p.role for p in m.principals},{'writer','shared_writer','collector','keeper','administrator'})
        self.assertEqual(parse_seed(canonical(model_record(m))),m)
        for raw in (b'{"schema_version":1,"schema_version":1}',b'{"schema_version":1.0}',b'{"x":NaN}'):
            with self.assertRaises(SqlFenceError):parse_plan(raw)
        for val in (True,1.0,2):
            d=plan_record();d['schema_version']=val
            with self.assertRaises(SqlFenceError):parse_plan(canonical(d))
    def test_rejects_live_targets_commands_credentials_and_unknown_fields(self):
        for field in ('socket','host','sql','command','password','datadir','references'):
            d=plan_record();d[field]='unexpected'
            with self.assertRaises(SqlFenceError):parse_plan(canonical(d))
        for field in ('schemas','principals','grants','definitions'):
            d=seed_record();d[field].append({'unknown':True})
            with self.assertRaises(SqlFenceError):parse_seed(canonical(d))
        d=plan_record();d['runtime_manifest']['extra']=True
        with self.assertRaises(SqlFenceError):parse_plan(canonical(d))
    def test_legacy_principal_length_and_privilege_vocabulary(self):
        for user in ('real_user','fx_'+('x'*14)):
            d=seed_record();d['principals'][0]['user']=user
            with self.assertRaises(SqlFenceError):parse_seed(canonical(d))
        for privilege in ('ALL','DENY','SYSTEM_USER','BACKUP_ADMIN'):
            d=seed_record();d['grants'][0]['privileges'].append(privilege)
            with self.assertRaises(SqlFenceError):parse_seed(canonical(d))
        m=model();self.assertIn('CREATE TABLESPACE',m.grants[0].privileges)
    def test_normalization_detaches_mutable_inputs(self):
        d=seed_record();m=parse_seed(canonical(d));d['grants'].clear()
        self.assertGreater(len(m.grants),0)
        r=model_record(m);r['grants'].clear();self.assertGreater(len(m.grants),0)
        f=FixturePlan(plan_record(),m,requirements(),tuple(plan_record()['cases']))
        with self.assertRaises(TypeError):f.value['runtime_archive']['path']='other'
    def test_original_private_reference_limits_and_replacement(self):
        # Actual root filesystem admission is exercised on Linux; non-root must deny.
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);root.chmod(0o700)
            p=root/'plan';p.write_bytes(canonical(plan_record()));p.chmod(0o600)
            r={'path':str(p),'sha256':hashlib.sha256(p.read_bytes()).hexdigest()}
            if sys.platform!='linux' or os.geteuid()!=0:
                with self.assertRaises(SqlFenceError):SqlDocuments(root)
                return
            with SqlDocuments(root) as docs:
                self.assertEqual(docs._json(r),parse_plan(p.read_bytes()))
                self.assertEqual(len(docs.files),1)
                docs._json(r);self.assertEqual(len(docs.files),1)
                q=root/'replacement';q.write_bytes(p.read_bytes());q.chmod(0o600);q.replace(p)
                with self.assertRaises(SqlFenceError):docs.verify_unchanged()
            p.write_bytes(b' '* (4*1024**2+1));p.chmod(0o600)
            r['sha256']=hashlib.sha256(p.read_bytes()).hexdigest()
            with SqlDocuments(root) as docs:
                with self.assertRaises(SqlFenceError):docs._json(r)
            p.unlink();p.symlink_to('/etc/passwd')
            with SqlDocuments(root) as docs:
                with self.assertRaises(SqlFenceError):docs._json(r)
