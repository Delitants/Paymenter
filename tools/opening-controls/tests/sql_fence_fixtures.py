"""Fresh fictional input helpers. No native deployment identities."""
import hashlib
import os
from pathlib import Path
from source_schema import canonical
from sql_fence_schema import CASE_IDS, parse_seed, BusinessRequirement, PRIVILEGES


def preserved():
    return ('fixture_legacy', 'fixture_tracking', 'fixture_web')


def requirements():
    return (BusinessRequirement('fixture_legacy', 'shared_writer', ('SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX')),
            BusinessRequirement('fixture_tracking', 'writer', ('SELECT','INSERT','UPDATE','DELETE')),
            BusinessRequirement('fixture_web', 'shared_writer', ('SELECT','INSERT','UPDATE','DELETE')))


def seed_record():
    principals = [{'user':u,'host':'localhost','role':r} for u,r in (
        ('fx_writer','writer'),('fx_shared','shared_writer'),('fx_collect','collector'),
        ('fx_keeper','keeper'),('fx_admin','administrator'))]
    return {'schema_version':1,'purpose':'fictional-sql-grant-seed',
            'schemas':['fixture_source', *preserved(), 'fixture_demo','fixtureXsource','fixture_pct%','fixture_pctX'],
            'principals':principals,
            'grants':[
                {'principal':['fx_admin','localhost'],'level':'global','target':[],
                 'privileges':sorted(PRIVILEGES)},
                {'principal':['fx_writer','localhost'],'level':'global','target':[],
                 'privileges':['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX','SUPER','FILE','GRANT OPTION','CREATE USER','CREATE TABLESPACE']},
                {'principal':['fx_shared','localhost'],'level':'global','target':[],
                 'privileges':['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX']},
                {'principal':['fx_writer','localhost'],'level':'table','target':['fixture_source','t000'], 'privileges':['SELECT','UPDATE']},
                {'principal':['fx_writer','localhost'],'level':'column','target':['fixture_source','t001','value'], 'privileges':['UPDATE']},
                {'principal':['fx_collect','localhost'],'level':'database','target':['fixture_source'], 'privileges':['SELECT']},
                {'principal':['fx_keeper','localhost'],'level':'database','target':['fixture_source'], 'privileges':['SELECT']}],
            'definitions':[]}


def model():
    return parse_seed(canonical(seed_record()))


def plan_record():
    return {'schema_version':1,'purpose':'fictional-sql-privilege-rehearsal',
            **{s:{'path':'/fixture/private/'+s,'sha256':'0'*64} for s in ('runtime_archive','runtime_manifest','grant_seed')},
            'protected_schema':'fixture_source','preserved_schemas':list(preserved()),
            'required_business':[{'schema':r.schema,'role':r.role,'privileges':list(r.privileges)} for r in requirements()],
            'cases':list(CASE_IDS)}


def make_private_plan(root: Path, archive: Path, manifest: Path) -> Path:
    root=Path(root)
    def ref(p):
        return {'path':str(p),'sha256':hashlib.sha256(p.read_bytes()).hexdigest()}
    def write(p, v):
        fd=os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'wb') as f:
            f.write(canonical(v));f.flush();os.fsync(f.fileno())
    seed=root/'grant-seed.json';write(seed,seed_record())
    plan=plan_record();plan.update(runtime_archive=ref(archive),runtime_manifest=ref(manifest),grant_seed=ref(seed))
    p=root/'plan.json';write(p,plan);return p
