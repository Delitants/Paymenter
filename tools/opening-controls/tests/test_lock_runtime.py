import copy
import hashlib
import importlib
import io
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from source_fixtures import runtime_archive
from source_schema import SourceError

class RuntimeTest(unittest.TestCase):
    def setUp(self):
        try:self.m=importlib.import_module('lock_runtime')
        except ModuleNotFoundError:self.fail('lock_runtime functionality missing')
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name).resolve();self.fixture=runtime_archive(self.root)
    def test_archive_rejects_paths_links_duplicates_and_replacement(self):
        for bad in ['../escape','/absolute','duplicate','symlink']:
            f=self.root/(bad.replace('/','_')+'.tar.gz')
            with tarfile.open(f,'w:gz') as tar:
                for _ in range(2 if bad=='duplicate' else 1):
                    m=tarfile.TarInfo('lib/loader' if bad in ['duplicate','symlink'] else bad);m.size=1
                    if bad=='symlink':m.type=tarfile.SYMTYPE;m.linkname='/outside'
                    tar.addfile(m,io.BytesIO(b'x') if m.isfile() else None)
            f.chmod(0o600);manifest=copy.deepcopy(self.fixture['manifest']);manifest['archive_sha256']=hashlib.sha256(f.read_bytes()).hexdigest()
            with self.assertRaises(SourceError):self.m.validate_archive(f,manifest)
        self.fixture['archive'].write_bytes(b'changed runtime')
        with self.assertRaises(SourceError):self.m.validate_archive(self.fixture['archive'],self.fixture['manifest'])
    def test_no_external_socket_or_existing_runtime_root_is_accepted(self):
        manifest=copy.deepcopy(self.fixture['manifest']);manifest['roles'].pop('mysqladmin')
        with self.assertRaises(SourceError):self.m.validate_archive(self.fixture['archive'],manifest)
        with self.assertRaises(SourceError):self.m.stage_runtime(self.fixture['archive'],self.fixture['manifest'],self.root)
    def test_staging_verifies_private_regular_bytes(self):
        runtime=self.m.stage_runtime(self.fixture['archive'],self.fixture['manifest'],self.root/'owned')
        self.assertTrue((Path(runtime['root'])/'usr/bin/mysqladmin').is_file())
        self.assertFalse(any(p.is_symlink() for p in Path(runtime['root']).rglob('*')))
        self.assertEqual((Path(runtime['root'])/'usr/bin/mysqladmin').read_bytes(),b'fictional runtime resource')

if __name__=='__main__':unittest.main()
