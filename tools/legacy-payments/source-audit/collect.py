"""Bounded one-attempt protocol; includes no database or network implementation."""
from dataclasses import dataclass, asdict
import datetime as dt
import re
from types import MappingProxyType
from typing import Protocol
from models import AuditError, Dataset, CAPS, FieldSpec, parse_decimal
from descriptor import assert_current, positive_id
from private_io import canonical, decode_json
from query_plan import build_plan, fields

BUSINESS_CAP=32*1024*1024
META_CAP=64*1024
CAPABILITIES=('repeatable-read','read-only','bounded','base-tables','unique-ids')


def freeze(value):
    if isinstance(value,dict):return MappingProxyType({k:freeze(v) for k,v in value.items()})
    if isinstance(value,(list,tuple)):return tuple(freeze(v) for v in value)
    return value


def plain(value):
    from collections.abc import Mapping
    import dataclasses
    if dataclasses.is_dataclass(value):return {f.name:plain(getattr(value,f.name)) for f in dataclasses.fields(value)}
    if isinstance(value,dt.datetime):return value.isoformat()
    if isinstance(value,Mapping):return {k:plain(v) for k,v in value.items()}
    if isinstance(value,(tuple,list)):return [plain(v) for v in value]
    return value

@dataclass(frozen=True)
class Collection:
    state:str
    origin:str
    profile_binding:object
    rows:object
    coverage:object
    lifecycle:object
    reasons:tuple[str,...]
    def __post_init__(self):
        for name in ('profile_binding','rows','coverage','lifecycle'):object.__setattr__(self,name,freeze(getattr(self,name)))

@dataclass(frozen=True)
class SessionFacts:
    origin:str;session_id:str;database_identity:str;socket_identity:str;server_profile_hash:str;schema_hash:str;engines:tuple;capabilities:tuple
@dataclass(frozen=True)
class SnapshotFacts:
    origin:str;session_id:str;schema_hash:str;snapshot_id:str;captured_at_utc:str;database_timezone:str;isolation:str;read_only:bool
@dataclass(frozen=True)
class LifecycleFact:
    operation:str;session_id:str;state:str
@dataclass(frozen=True)
class ResultFrame:
    raw_bytes:bytes;query_hash:str;session_id:str;eof:bool

class AuditTransport(Protocol):
    def open(self,timeout_s:float)->SessionFacts:...
    def begin_snapshot(self,timeout_s:float)->SnapshotFacts:...
    def execute(self,query,timeout_s:float)->ResultFrame:...
    def rollback(self,timeout_s:float)->LifecycleFact:...
    def postflight(self,timeout_s:float)->SessionFacts:...
    def close(self,timeout_s:float)->LifecycleFact:...


def binding(d,p):
    return dict(source_instance=d.source_instance,database_identity=d.database_identity,socket_identity=d.socket_identity,cohort_ref=d.cohort_ref,account_ids=list(d.account_ids),held_account_ids=list(d.held_account_ids),server_profile_hash=p.server_hash,query_profile_hash=p.query_hash,schema_hash=p.schema_hash,origin=p.origin)


def validate_row(dataset,row):
    if not isinstance(row,dict) or set(row)!=set(fields(dataset)) or any(v is not None and not isinstance(v,str) for v in row.values()):raise AuditError('row-schema')
    if len(canonical(row))>4096 or not positive_id(row['id']):raise AuditError('row-cap')
    ids={Dataset.SUBACCOUNTS:('account','project','currency'),Dataset.PAYMENTS:('subaccount','paymethod','currency'),Dataset.METHODS:('currency',)}.get(dataset,())
    for name in ids:
        if row[name] is not None and not positive_id(row[name]):raise AuditError('row-reference')
    if dataset==Dataset.SUBACCOUNTS:
        if row['account'] is None or row['currency'] is None:raise AuditError('row-reference')
    if dataset==Dataset.PAYMENTS:
        if row['subaccount'] is None:raise AuditError('row-reference')
        for name in row:
            if name.startswith('link_') and row[name] not in ('null','empty','present','invalid'):raise AuditError('row-flag')
            if name.startswith('flag_') and row[name] not in ('null','on','off','invalid'):raise AuditError('row-flag')
    money=('balance','creditlimit') if dataset==Dataset.SUBACCOUNTS else ('subaccountamount','paymethodamount','usedamount','commissionamount','taxrate','taxamount') if dataset==Dataset.PAYMENTS else ()
    for name in money:
        parse_decimal(row[name],FieldSpec(18,4))


def validate_coverage(rows,d):
    accounts={r['id'] for r in rows['accounts']}
    if accounts!=set(d.account_ids):raise AuditError('coverage-accounts')
    subs={r['id']:r for r in rows['subaccounts']};methods={r['id']:r for r in rows['methods']};currencies={r['id'] for r in rows['currencies']}
    if any(r['account'] not in accounts for r in subs.values()):raise AuditError('coverage-owner')
    if any(r['subaccount'] not in subs for r in rows['payments']):raise AuditError('coverage-reference')
    wanted_methods={r['paymethod'] for r in rows['payments'] if r['paymethod'] is not None}
    if set(methods)!=wanted_methods:raise AuditError('coverage-methods')
    wanted_currencies={r['currency'] for source in (subs.values(),methods.values(),rows['payments']) for r in source if r['currency'] is not None}
    if currencies!=wanted_currencies:raise AuditError('coverage-currencies')


def collect(descriptor,profile,plan,transport,clock):
    rows={d.value:[] for d in Dataset};coverage={};lifecycle={};reasons=[];session=None;begun=False;ended=False;start=None;last=None;meta=0;business=0;attempted_open=False
    def note(code):
        if code not in reasons:reasons.append(code)
    def current():
        nonlocal last
        last=assert_current(descriptor,clock,last)
    def timed(fn,limit,*args):
        current();t=clock.monotonic();result=fn(*args,timeout_s=limit)
        if clock.monotonic()<t or clock.monotonic()-t>limit:raise AuditError('collection-deadline')
        current()
        if start is not None and not ended and clock.monotonic()-start>30:raise AuditError('snapshot-deadline')
        return result
    def metadata(fact):
        nonlocal meta
        meta+=len(canonical(asdict(fact)))
        if meta>META_CAP:raise AuditError('metadata-cap')
    def check_session(fact):
        metadata(fact)
        if not isinstance(fact,SessionFacts) or fact.origin!='fictional' or not isinstance(fact.session_id,str) or not fact.session_id or len(fact.session_id)>96 or fact.database_identity!=descriptor.database_identity or fact.socket_identity!=descriptor.socket_identity or fact.server_profile_hash!=profile.server_hash or fact.schema_hash!=profile.schema_hash or fact.engines!=('InnoDB',)*5 or fact.capabilities!=CAPABILITIES:raise AuditError('session-identity')
    def query(q):
        nonlocal business,meta
        remaining=30-(clock.monotonic()-start)
        if remaining<=0:raise AuditError('snapshot-deadline')
        frame=timed(transport.execute,min(3,remaining),q)
        if not isinstance(frame,ResultFrame) or type(frame.raw_bytes) is not bytes or frame.query_hash!=q.digest or frame.session_id!=session.session_id or frame.eof is not True:raise AuditError('frame-identity')
        if q.kind=='count':meta+=len(frame.raw_bytes)
        else:business+=len(frame.raw_bytes)
        if business>BUSINESS_CAP or meta>META_CAP or len(frame.raw_bytes)>256*4096+4096:raise AuditError('result-cap')
        result=decode_json(frame.raw_bytes)
        if canonical(result)!=frame.raw_bytes:raise AuditError('frame-canonical')
        if not isinstance(result,dict):raise AuditError('frame-schema')
        count=result.get('count')
        if not isinstance(count,str) or not re.fullmatch(r'0|[1-9][0-9]{0,18}',count):raise AuditError('frame-count')
        if q.kind=='count':
            if set(result)!={'count'}:raise AuditError('frame-schema')
            return int(count)
        if set(result)!={'fields','rows','count'} or result['fields']!=list(fields(q.dataset)) or not isinstance(result['rows'],list) or len(result['rows'])>256 or len(result['rows'])!=int(count):raise AuditError('frame-schema')
        return result['rows']
    try:
        if plan!=build_plan(descriptor,profile):raise AuditError('query-profile')
        current();attempted_open=True;session=timed(transport.open,10);check_session(session);lifecycle['open']='observed'
        start=clock.monotonic();snapshot=timed(transport.begin_snapshot,3);begun=True;metadata(snapshot)
        if not isinstance(snapshot,SnapshotFacts) or snapshot.origin!='fictional' or snapshot.session_id!=session.session_id or snapshot.schema_hash!=profile.schema_hash or snapshot.isolation!='REPEATABLE READ' or snapshot.read_only is not True or not snapshot.snapshot_id:raise AuditError('snapshot-identity')
        try:
            stamp=dt.datetime.fromisoformat(snapshot.captured_at_utc)
            if stamp.utcoffset()!=dt.timedelta(0) or not isinstance(snapshot.database_timezone,str) or len(snapshot.database_timezone)>96:raise ValueError()
        except (ValueError,TypeError):raise AuditError('snapshot-time') from None
        lifecycle['snapshot']=asdict(snapshot)
        counts={d:query(plan.count(d)) for d in Dataset}
        if any(counts[d]>CAPS[d] for d in Dataset):raise AuditError('count-cap')
        for dataset in Dataset:
            after=None
            while True:
                page=query(plan.page(dataset,after))
                if not page:break
                for row in page:
                    validate_row(dataset,row)
                    if after is not None and int(row['id'])<=int(after):raise AuditError('row-order')
                    after=row['id'];rows[dataset.value].append(row)
                    if len(rows[dataset.value])>CAPS[dataset] or len(rows[dataset.value])>counts[dataset]:raise AuditError('count-mismatch')
            if len(rows[dataset.value])!=counts[dataset]:raise AuditError('count-mismatch')
            coverage[dataset.value]=len(rows[dataset.value])
        validate_coverage(rows,descriptor)
    except AuditError as e:note(e.code)
    except Exception:note('collection-error')
    finally:
        cleanup_start=clock.monotonic()
        if begun:
            try:
                result=timed(transport.rollback,min(3,max(0,30-(clock.monotonic()-start))));metadata(result)
                if not isinstance(result,LifecycleFact) or result.operation!='rollback' or result.session_id!=session.session_id or result.state!='observed':raise AuditError('rollback-unknown')
                lifecycle['rollback']='observed'
            except AuditError as e:note(e.code);lifecycle['rollback']='unknown'
            except Exception:note('rollback-unknown');lifecycle['rollback']='unknown'
        ended=True
        if session is not None:
            try:
                fact=timed(transport.postflight,3);check_session(fact)
                if fact!=session:raise AuditError('postflight-identity')
                lifecycle['postflight']='observed'
            except AuditError as e:note(e.code);lifecycle['postflight']='unknown'
            except Exception:note('postflight-unknown');lifecycle['postflight']='unknown'
        if attempted_open:
            # Closing remains necessary even after authority expiry; it grants no new reads.
            try:
                before=clock.monotonic();fact=transport.close(timeout_s=max(0,10-(before-cleanup_start)));metadata(fact)
                if clock.monotonic()-cleanup_start>10 or clock.monotonic()<before or not isinstance(fact,LifecycleFact) or fact.operation!='close' or fact.state!='observed' or session is None or fact.session_id!=session.session_id:raise AuditError('close-unknown')
                lifecycle['close']='observed'
            except Exception:note('close-unknown');lifecycle['close']='unknown'
    if last is not None:lifecycle['authority_last_utc']=last.isoformat()
    return Collection('incomplete' if reasons else 'complete','fictional',binding(descriptor,profile),rows,coverage,lifecycle,tuple(reasons))


class FictionalTransport:
    """Replays declared fictional rows in memory. It cannot execute its SQL plan."""
    def __init__(self,descriptor,profile,clock,rows):
        if profile.origin!='fictional' or set(rows)!={d.value for d in Dataset} or any(not isinstance(v,list) for v in rows.values()):raise AuditError('fixture-schema')
        for source in rows.values():
            if any(not isinstance(r,dict) or not positive_id(r.get('id')) for r in source):raise AuditError('fixture-rows')
        self.d=descriptor;self.profile=profile;self.clock=clock
        self.rows={name:tuple(sorted((freeze(r) for r in data),key=lambda r:int(r['id']))) for name,data in rows.items()}
    def session(self):
        return SessionFacts('fictional','fictional-session',self.d.database_identity,self.d.socket_identity,self.profile.server_hash,self.profile.schema_hash,('InnoDB',)*5,CAPABILITIES)
    def open(self,timeout_s):return self.session()
    def begin_snapshot(self,timeout_s):return SnapshotFacts('fictional','fictional-session',self.profile.schema_hash,'fictional-snapshot',self.clock.utc_now().isoformat(),'UTC','REPEATABLE READ',True)
    def execute(self,query,timeout_s):
        source=self.rows[query.dataset.value]
        if query.kind=='count':result={'count':str(len(source))}
        else:
            cursor=query.params[-2] if ' > %s' in query.sql else None
            page=[plain(r) for r in source if cursor is None or int(r['id'])>int(cursor)][:256]
            result={'fields':list(fields(query.dataset)),'rows':page,'count':str(len(page))}
        return ResultFrame(canonical(result),query.digest,'fictional-session',True)
    def rollback(self,timeout_s):return LifecycleFact('rollback','fictional-session','observed')
    def postflight(self,timeout_s):return self.session()
    def close(self,timeout_s):return LifecycleFact('close','fictional-session','observed')
