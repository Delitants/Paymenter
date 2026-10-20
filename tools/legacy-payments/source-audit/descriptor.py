"""Fixed cohort and time authority checks, not a human-approval issuer."""
import datetime as dt
from pathlib import Path
import re
import hashlib
from models import AuditError, Descriptor
from private_io import decode_json, open_private_root

KEYS={'version','attempt_id','approval_scope_ref','not_before_utc','expires_utc','source_instance','database_identity','socket_identity','server_profile_hash','query_profile_hash','schema_hash','cohort_ref','account_ids','held_account_ids','previous_ref','output_root'}


def positive_id(value):
    return isinstance(value,str) and re.fullmatch(r'[1-9][0-9]{0,18}',value) is not None and int(value)<=9223372036854775807


def assert_current(descriptor,clock,last_utc=None):
    if descriptor.source_path:
        p=Path(descriptor.source_path)
        with open_private_root(p.parent) as root: raw=root.read(p.name,256*1024)
        if hashlib.sha256(raw).hexdigest()!=descriptor.source_digest:raise AuditError('descriptor-changed')
    now=clock.utc_now()
    if now.tzinfo is None or (last_utc is not None and now<last_utc):raise AuditError('authority-clock')
    if now<descriptor.not_before_utc or now>=descriptor.expires_utc:raise AuditError('authority-expired')
    return now


def load_descriptor(path,profile,clock):
    p=Path(path)
    with open_private_root(p.parent) as root: raw=root.read(p.name,256*1024)
    x=decode_json(raw)
    if not isinstance(x,dict) or set(x)!=KEYS or type(x['version']) is not int or x['version']!=1:raise AuditError('descriptor-schema')
    for k in ('attempt_id','approval_scope_ref','source_instance','database_identity','socket_identity','cohort_ref'):
        if not isinstance(x[k],str) or re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,95}',x[k]) is None:raise AuditError('descriptor-identity')
    if profile.origin!='fictional':raise AuditError('descriptor-profile')
    for k,want in [('server_profile_hash',profile.server_hash),('query_profile_hash',profile.query_hash),('schema_hash',profile.schema_hash)]:
        if not isinstance(x[k],str) or not re.fullmatch('[0-9a-f]{64}',x[k]) or x[k]!=want:raise AuditError('descriptor-profile')
    for k in ('account_ids','held_account_ids'):
        ids=x[k]
        if not isinstance(ids,list) or len(ids)>2048 or not all(positive_id(i) for i in ids) or ids!=sorted(set(ids),key=int):raise AuditError('descriptor-cohort')
    if not x['account_ids'] or not set(x['held_account_ids'])<=set(x['account_ids']):raise AuditError('descriptor-cohort')
    try:
        start=dt.datetime.fromisoformat(x['not_before_utc']);end=dt.datetime.fromisoformat(x['expires_utc'])
        if start.utcoffset()!=dt.timedelta(0) or end.utcoffset()!=dt.timedelta(0) or not 0<(end-start).total_seconds()<=1800:raise ValueError()
    except (TypeError,ValueError):raise AuditError('descriptor-time') from None
    if not isinstance(x['output_root'],str):raise AuditError('private-path')
    with open_private_root(Path(x['output_root'])):pass
    if x['previous_ref'] is not None:
        ref=x['previous_ref']
        if not isinstance(ref,dict) or set(ref)!={'attempt_id','manifest_hash','origin','collection_state','completion_token'}:raise AuditError('descriptor-previous')
        if ref['attempt_id']==x['attempt_id']:raise AuditError('descriptor-previous')
        for k in ('manifest_hash','completion_token'):
            if not isinstance(ref[k],str) or not re.fullmatch('[0-9a-f]{64}',ref[k]):raise AuditError('descriptor-previous')
        if ref['origin']!='fictional' or ref['collection_state']!='complete' or not isinstance(ref['attempt_id'],str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,95}',ref['attempt_id']):raise AuditError('descriptor-previous')
    d=Descriptor(**{k:v for k,v in x.items() if k not in ('version','not_before_utc','expires_utc','account_ids','held_account_ids')},source_path=str(p),source_digest=hashlib.sha256(raw).hexdigest(),not_before_utc=start,expires_utc=end,account_ids=tuple(x['account_ids']),held_account_ids=tuple(x['held_account_ids']))
    assert_current(d,clock);return d
