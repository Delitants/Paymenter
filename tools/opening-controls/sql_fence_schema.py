"""Closed fictional SQL contracts. These records confer no production authority."""
from dataclasses import dataclass
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
from types import MappingProxyType
from typing import Mapping
from boundary_refs import ReferenceSet, entry_count
from boundary_schema import BoundaryError
from custody import strict_json
from source_schema import SourceError, validate_runtime_manifest

CASE_IDS = ('broader_grant_and_super_bypass','protected_dml_ddl_denied',
            'unrelated_work_preserved','cached_session_admission','host_pattern_proxy_grants',
            'admin_definer_escape_denied','keeper_controller_loss','owned_server_restart',
            'partial_operation_recovery','exact_restore_and_retirement')
ROLES = frozenset(('writer','shared_writer','collector','keeper','administrator'))
PRIVILEGES = frozenset(('SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','RELOAD',
    'SHUTDOWN','PROCESS','FILE','GRANT OPTION','REFERENCES','INDEX','ALTER','SHOW DATABASES',
    'SUPER','CREATE TEMPORARY TABLES','LOCK TABLES','EXECUTE','REPLICATION SLAVE',
    'REPLICATION CLIENT','CREATE VIEW','SHOW VIEW','CREATE ROUTINE','ALTER ROUTINE',
    'CREATE USER','EVENT','TRIGGER','CREATE TABLESPACE'))
DATABASE_PRIVILEGES = PRIVILEGES - {'RELOAD','SHUTDOWN','PROCESS','FILE','SHOW DATABASES',
    'SUPER','REPLICATION SLAVE','REPLICATION CLIENT','CREATE USER','CREATE TABLESPACE'}
LEVEL_PRIVILEGES = {'global': PRIVILEGES, 'database': DATABASE_PRIVILEGES,
    'table':frozenset(('SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','GRANT OPTION',
                      'REFERENCES','INDEX','ALTER','CREATE VIEW','SHOW VIEW','TRIGGER')),
    'column':frozenset(('SELECT','INSERT','UPDATE','REFERENCES')),
    'routine':frozenset(('EXECUTE','ALTER ROUTINE','GRANT OPTION')),
    'proxy':frozenset(('PROXY','GRANT OPTION'))}

class SqlFenceError(RuntimeError):
    def __init__(self, code: str):
        self.code=code
        super().__init__(code)


def require(condition, code='sql-contract'):
    if not condition:raise SqlFenceError(code)


def canonical(value):
    try:return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()
    except (TypeError,ValueError):raise SqlFenceError('sql-json') from None


def sha(raw):return hashlib.sha256(raw).hexdigest()


def keys(v, names):require(type(v) is dict and set(v)==set(names.split()))


def name(v):
    require(type(v) is str and re.fullmatch(r'fixture[A-Za-z0-9_%]{1,56}',v) is not None)
    return v


def object_name(v):
    require(type(v) is str and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,63}',v) is not None)
    return v


def user(v):
    require(type(v) is str and re.fullmatch(r'fx_[A-Za-z0-9_]{1,13}',v) is not None)
    return v


def host(v):
    require(v in ('localhost','127.0.0.1','%','127.0.0.%'),'sql-host')
    return v


def reference(v):
    keys(v,'path sha256');p=v['path'];h=v['sha256']
    require(type(p) is str and '\0' not in p and len(p)<=4096,'sql-reference')
    path=PurePosixPath(p)
    require(path.is_absolute() and '..' not in path.parts and str(path)==p and p!='/','sql-reference')
    require(type(h) is str and re.fullmatch('[a-f0-9]{64}',h) is not None,'sql-reference')
    return dict(v)


def privileges(v, level):
    require(type(v) is list and v and len(v)==len(set(v)) and all(type(x) is str for x in v))
    require(set(v)<=LEVEL_PRIVILEGES[level],'sql-privilege')
    return tuple(sorted(v))


def frozen(v):
    if type(v) is dict:return MappingProxyType({k:frozen(x) for k,x in v.items()})
    if type(v) in (list,tuple):return tuple(frozen(x) for x in v)
    return v


def detached(v):
    if isinstance(v,Mapping):return {k:detached(x) for k,x in v.items()}
    if type(v) in (list,tuple):return [detached(x) for x in v]
    return v

@dataclass(frozen=True)
class Principal:
    user: str
    host: str
    role: str

@dataclass(frozen=True)
class GrantRow:
    principal: tuple[str,str]
    level: str
    target: tuple[str,...]
    privileges: tuple[str,...]

@dataclass(frozen=True)
class DefinerObject:
    schema: str
    kind: str
    definer: tuple[str,str]
    definition_sha256: str

@dataclass(frozen=True)
class GrantModel:
    schemas: tuple[str,...]
    principals: tuple[Principal,...]
    grants: tuple[GrantRow,...]
    definitions: tuple[DefinerObject,...]

@dataclass(frozen=True)
class BusinessRequirement:
    schema: str
    role: str
    privileges: tuple[str,...]

@dataclass(frozen=True)
class FixturePlan:
    value: Mapping[str,object]
    seed: GrantModel
    required_business: tuple[BusinessRequirement,...]
    cases: tuple[str,...]
    def __post_init__(self):object.__setattr__(self,'value',frozen(detached(self.value)))


def _json(raw):
    require(type(raw) is bytes and len(raw)<=4*1024**2,'sql-document-size')
    try:v=strict_json(raw)
    except (ValueError,TypeError,UnicodeError,RecursionError):raise SqlFenceError('sql-json') from None
    stack=[(v,1)];entries=0
    while stack:
        x,depth=stack.pop();require(depth<=8,'sql-document-depth')
        require(type(x) in (dict,list,str,int,bool,type(None)),'sql-json')
        if type(x) is dict:stack.extend((y,depth+1) for y in x.values())
        if type(x) is list:
            entries+=len(x);stack.extend((y,depth+1) for y in x)
        require(entries<=10000,'sql-document-entries')
    return v


def header(v,purpose):
    require(type(v.get('schema_version')) is int and v['schema_version']==1)
    require(v.get('purpose')==purpose,'sql-purpose')


def parse_plan(raw: bytes) -> dict:
    v=_json(raw)
    keys(v,'schema_version purpose runtime_archive runtime_manifest grant_seed protected_schema preserved_schemas required_business cases')
    header(v,'fictional-sql-privilege-rehearsal')
    for slot in ('runtime_archive','runtime_manifest','grant_seed'):reference(v[slot])
    require(len({v[s]['path'] for s in ('runtime_archive','runtime_manifest','grant_seed')})==3,'sql-reference-alias')
    name(v['protected_schema']);p=v['preserved_schemas']
    require(type(p) is list and len(p)==3 and len(set(p))==3)
    for s in p:name(s)
    require(v['protected_schema'] not in p)
    require(type(v['cases']) is list and len(v['cases'])==10 and set(v['cases'])==set(CASE_IDS),'sql-cases')
    require(type(v['required_business']) is list and v['required_business'])
    seen=set()
    for r in v['required_business']:
        keys(r,'schema role privileges');require(r['schema'] in p and r['role'] in ('writer','shared_writer'))
        privileges(r['privileges'],'database');k=(r['schema'],r['role']);require(k not in seen);seen.add(k)
    require({r['schema'] for r in v['required_business']}==set(p),'sql-business-scope')
    return v


def model_record(m: GrantModel) -> dict:
    return {'schema_version':1,'purpose':'fictional-sql-grant-seed','schemas':list(m.schemas),
            'principals':[{'user':p.user,'host':p.host,'role':p.role} for p in m.principals],
            'grants':[{'principal':list(g.principal),'level':g.level,'target':list(g.target),'privileges':list(g.privileges)} for g in m.grants],
            'definitions':[{'schema':d.schema,'kind':d.kind,'definer':list(d.definer),'definition_sha256':d.definition_sha256} for d in m.definitions]}


def parse_seed(raw: bytes) -> GrantModel:
    v=_json(raw);keys(v,'schema_version purpose schemas principals grants definitions');header(v,'fictional-sql-grant-seed')
    require(all(type(v[k]) is list for k in ('schemas','principals','grants','definitions')))
    schemas=tuple(sorted(name(x) for x in v['schemas']));require(schemas and len(set(schemas))==len(schemas))
    principals=[];identities=set()
    for p in v['principals']:
        keys(p,'user host role');user(p['user']);host(p['host']);require(p['role'] in ROLES)
        i=(p['user'],p['host']);require(i not in identities,'sql-principal');identities.add(i)
        principals.append(Principal(p['user'],p['host'],p['role']))
    require({p.role for p in principals}==ROLES,'sql-roles')
    require(sum(p.role=='administrator' for p in principals)==1,'sql-administrator')
    grants=[];seen=set()
    for g in v['grants']:
        keys(g,'principal level target privileges')
        require(type(g['principal']) is list and len(g['principal'])==2 and tuple(g['principal']) in identities)
        level=g['level'];require(level in LEVEL_PRIVILEGES);target=g['target']
        require(type(target) is list and len(target)=={'global':0,'database':1,'table':2,'column':3,'routine':3,'proxy':2}[level])
        if level=='proxy':
            user(target[0]);host(target[1]);require(tuple(target) in identities)
        elif level!='global':
            require(target[0] in schemas)
            if len(target)>1:object_name(target[1])
            if level=='column':object_name(target[2])
            if level=='routine':require(target[2] in ('FUNCTION','PROCEDURE'))
        row=GrantRow(tuple(g['principal']),level,tuple(target),privileges(g['privileges'],level))
        k=(row.principal,level,row.target);require(k not in seen,'sql-grant-duplicate');seen.add(k);grants.append(row)
    definitions=[];seen=set()
    for d in v['definitions']:
        keys(d,'schema kind definer definition_sha256');require(d['schema'] in schemas and d['kind'] in ('view','routine','trigger','event'))
        require(type(d['definer']) is list and len(d['definer'])==2 and tuple(d['definer']) in identities)
        require(type(d['definition_sha256']) is str and re.fullmatch('[a-f0-9]{64}',d['definition_sha256']) is not None)
        k=(d['schema'],d['kind'],d['definition_sha256']);require(k not in seen);seen.add(k)
        definitions.append(DefinerObject(d['schema'],d['kind'],tuple(d['definer']),d['definition_sha256']))
    return GrantModel(schemas,tuple(sorted(principals,key=lambda p:(p.user,p.host))),
                      tuple(sorted(grants,key=lambda g:(g.principal,g.level,g.target))),
                      tuple(sorted(definitions,key=lambda d:(d.schema,d.kind,d.definition_sha256))))

class SqlDocuments(ReferenceSet):
    def __init__(self,root: Path):
        try:super().__init__(root)
        except BoundaryError as e:raise SqlFenceError('sql-'+str(e)) from None
        self.sql_cache={};self.plan=None;self.manifest=None
    def _json(self,r):
        reference(r);path=r['path']
        if path in self.sql_cache:
            h,v=self.sql_cache[path];require(h==r['sha256'],'sql-reference-hash');return detached(v)
        try:raw=super()._read(r)
        except BoundaryError as e:raise SqlFenceError('sql-'+str(e)) from None
        v=_json(raw);self.bytes+=len(raw);self.entries+=entry_count(v)
        require(self.bytes<=32*1024**2 and self.entries<=10000,'sql-reference-budget')
        self.sql_cache[path]=(r['sha256'],frozen(v));return detached(v)
    def load(self,plan_ref: dict) -> FixturePlan:
        require(self.plan is None,'sql-reference-reuse')
        p=parse_plan(canonical(self._json(plan_ref)))
        seed=parse_seed(canonical(self._json(p['grant_seed'])))
        manifest=self._json(p['runtime_manifest'])
        try:validate_runtime_manifest(manifest)
        except SourceError:raise SqlFenceError('sql-runtime-manifest') from None
        archive=reference(p['runtime_archive'])
        require(self.root in Path(archive['path']).parents,'sql-reference-path')
        # Archive is validated only inside the admitted memory domain, never as JSON.
        require(archive['sha256']==manifest['archive_sha256'],'sql-runtime-archive')
        require(p['protected_schema'] in seed.schemas and set(p['preserved_schemas'])<=set(seed.schemas),'sql-schema-scope')
        self.plan=p;self.plan_reference=dict(plan_ref);self.manifest=frozen(manifest);self.verify_unchanged()
        return FixturePlan(p,seed,tuple(BusinessRequirement(r['schema'],r['role'],tuple(sorted(r['privileges']))) for r in p['required_business']),tuple(p['cases']))
    def verify_unchanged(self):
        try:super().verify_unchanged()
        except BoundaryError as e:raise SqlFenceError('sql-'+str(e)) from None
    def runtime_manifest_record(self):
        require(self.manifest is not None);return detached(self.manifest)
    def archive_reference(self):
        require(self.plan is not None);return dict(self.plan['runtime_archive'])
