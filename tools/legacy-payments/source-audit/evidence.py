"""Immutable private attempt receipts. No receipt establishes money authority."""
from dataclasses import dataclass,replace
import datetime as dt
import hashlib
import hmac
import os
from pathlib import Path
import re
import secrets
from collections import Counter
from collections.abc import Mapping
from models import AuditError
from descriptor import assert_current
from private_io import canonical,decode_json,open_private_root
from collect import Collection,plain,binding
from query_plan import fictional_profile
from analyze import compare

FILE_CAP=64*1024*1024
TOTAL_CAP=128*1024*1024


def digest(raw):return hashlib.sha256(raw).hexdigest()

def commitment(token):return digest(b'fictional-audit-completion-v1\0'+bytes.fromhex(token))

@dataclass(frozen=True)
class EvidenceRef:
    attempt_id:str
    manifest_hash:str
    origin:str
    collection_state:str
    completion_token:str|None

@dataclass(frozen=True)
class PublicSummary:
    payload:object
    def as_dict(self):return plain(self.payload)

class AttemptClaim:
    def __init__(self,root,d,clock):
        self.parent=root;self.descriptor=d;self.last_utc=assert_current(d,clock)
        if root.path!=Path(d.output_root):raise AuditError('claim-root')
        self.directory=root.mkdir(d.attempt_id);self.used=False
        try:
            self.directory.create('claim.json',canonical({'origin':'fictional','descriptor':plain(d),'claimed_at_utc':self.last_utc.isoformat()}))
            self.directory.fsync();self.parent.fsync()
        except BaseException:
            self.directory.__exit__(None,None,None);raise
    def __enter__(self):return self
    def __exit__(self,*args):self.directory.__exit__(*args)


def claim_attempt(root,descriptor,clock):return AttemptClaim(root,descriptor,clock)


def _seal_current(claim,collection,analysis,clock):
    claim.last_utc=assert_current(claim.descriptor,clock,claim.last_utc)
    claim.parent.check();claim.directory.check()
    if collection.origin!='fictional' or plain(collection.profile_binding)!=binding(claim.descriptor,fictional_profile()) or collection.state not in ('complete','incomplete'):raise AuditError('seal-binding')
    last=collection.lifecycle.get('authority_last_utc')
    if last:
        try:claim.last_utc=max(claim.last_utc,dt.datetime.fromisoformat(last))
        except (ValueError,TypeError):raise AuditError('seal-clock') from None
        claim.last_utc=assert_current(claim.descriptor,clock,claim.last_utc)
    if collection.state=='complete':
        compare(collection,None,claim.descriptor,fictional_profile())
        if analysis is None or any(x!='unqualified' for x in analysis.payment_authority.values()) or any(v!='not_evaluated' for values in analysis.provenance.values() for v in values.values()):raise AuditError('seal-authority')
        if any(collection.lifecycle.get(x)!='observed' for x in ('open','rollback','postflight','close')) or collection.reasons:raise AuditError('seal-lifecycle')
    elif analysis is not None:raise AuditError('seal-partial')
    token=secrets.token_hex(32) if collection.state=='complete' else None
    docs={'collection.json':canonical(plain(collection)),'analysis.json':canonical(plain(analysis))}
    if any(len(raw)>FILE_CAP for raw in docs.values()) or sum(map(len,docs.values()))>TOTAL_CAP:raise AuditError('evidence-cap')
    members={name:{'bytes':len(raw),'sha256':digest(raw)} for name,raw in docs.items()}
    manifest={'version':1,'origin':'fictional','attempt_id':claim.descriptor.attempt_id,'collection_state':collection.state,
              'descriptor_sha256':digest(canonical(plain(claim.descriptor))),'files':members,
              'completion_commitment':commitment(token) if token else None,'sealed_at_utc':clock.utc_now().isoformat()}
    raw=canonical(manifest)
    try:
        for name,data in docs.items():claim.directory.create(name,data)
        claim.directory.create('manifest.candidate.json',raw)
        claim.last_utc=assert_current(claim.descriptor,clock,claim.last_utc)
        os.link('manifest.candidate.json','manifest.json',src_dir_fd=claim.directory.fd,dst_dir_fd=claim.directory.fd,follow_symlinks=False)
        os.unlink('manifest.candidate.json',dir_fd=claim.directory.fd)
        claim.directory.fsync();claim.parent.fsync()
        claim.last_utc=assert_current(claim.descriptor,clock,claim.last_utc)
        claim.parent.check();claim.directory.check()
    except OSError:raise AuditError('evidence-finalization') from None
    return EvidenceRef(claim.descriptor.attempt_id,digest(raw),'fictional',collection.state,token)


AUTHORITY_FAILURES={'authority-clock','authority-expired','descriptor-changed','private-open','private-read','private-mode','private-identity','private-cap'}


def _retain_failure(claim,collection,reason):
    # Local retention only: never consult the revoked clock/descriptor or transport.
    claim.parent.check();claim.directory.check()
    failed=replace(collection,state='incomplete',reasons=tuple(dict.fromkeys((*collection.reasons,reason))))
    directory=claim.directory
    try:
        os.stat('collection.json',dir_fd=directory.fd,follow_symlinks=False)
    except FileNotFoundError:
        name='failure.collection.json';raw=canonical(plain(failed))
        if len(raw)>FILE_CAP:raise AuditError('evidence-cap')
        create_body=True
    else:
        name='collection.json';raw=directory.read(name,FILE_CAP)
        if raw!=canonical(plain(collection)):raise AuditError('evidence-existing')
        create_body=False
    manifest={'version':1,'origin':'fictional','attempt_id':claim.descriptor.attempt_id,
              'collection_state':'incomplete','completion_commitment':None,
              'reasons':list(failed.reasons),'coverage':plain(collection.coverage),'lifecycle':plain(collection.lifecycle),
              'files':{name:{'bytes':len(raw),'sha256':digest(raw)}}}
    encoded=canonical(manifest)
    try:
        used=sum(os.stat(n,dir_fd=directory.fd,follow_symlinks=False).st_size for n in os.listdir(directory.fd))
        if used+(len(raw) if create_body else 0)+len(encoded)>TOTAL_CAP:raise AuditError('evidence-cap')
        if create_body:directory.create(name,raw)
        directory.create('failure.candidate.json',encoded)
        os.link('failure.candidate.json','failure.manifest.json',src_dir_fd=directory.fd,dst_dir_fd=directory.fd,follow_symlinks=False)
        os.unlink('failure.candidate.json',dir_fd=directory.fd)
        directory.fsync();claim.parent.fsync();directory.check();claim.parent.check()
    except OSError:raise AuditError('evidence-finalization') from None
    return EvidenceRef(claim.descriptor.attempt_id,digest(encoded),'fictional','incomplete',None)


def seal_attempt(claim,collection,analysis,clock):
    if claim.used:raise AuditError('attempt-spent')
    claim.used=True
    if collection.origin!='fictional' or plain(collection.profile_binding)!=binding(claim.descriptor,fictional_profile()):raise AuditError('seal-binding')
    try:return _seal_current(claim,collection,analysis,clock)
    except AuditError as error:
        if error.code not in AUTHORITY_FAILURES:raise
        return _retain_failure(claim,collection,error.code)


def load_previous(root,ref,descriptor):
    if isinstance(ref,Mapping):
        try:ref=EvidenceRef(**ref)
        except TypeError:raise AuditError('prior-reference') from None
    if not isinstance(ref,EvidenceRef) or ref.origin!='fictional' or ref.collection_state!='complete' or not isinstance(ref.completion_token,str) or re.fullmatch('[0-9a-f]{64}',ref.completion_token) is None:raise AuditError('prior-completion')
    root.name(ref.attempt_id);root.check()
    with open_private_root(root.path/ref.attempt_id) as source:
        raw=source.read('manifest.json',256*1024)
        if digest(raw)!=ref.manifest_hash:raise AuditError('prior-hash')
        m=decode_json(raw)
        if not isinstance(m,dict) or set(m)!={'version','origin','attempt_id','collection_state','descriptor_sha256','files','completion_commitment','sealed_at_utc'} or canonical(m)!=raw or m['version']!=1 or m['origin']!='fictional' or m['attempt_id']!=ref.attempt_id or m['collection_state']!='complete' or not isinstance(m['completion_commitment'],str) or not hmac.compare_digest(m['completion_commitment'],commitment(ref.completion_token)):raise AuditError('prior-completion')
        if not isinstance(m['files'],dict) or set(m['files'])!={'collection.json','analysis.json'}:raise AuditError('prior-schema')
        contents={}
        for name,want in m['files'].items():
            if not isinstance(want,dict) or set(want)!={'bytes','sha256'} or type(want['bytes']) is not int or not 0<=want['bytes']<=FILE_CAP:raise AuditError('prior-schema')
            data=source.read(name,FILE_CAP)
            if len(data)!=want['bytes'] or digest(data)!=want['sha256']:raise AuditError('prior-hash')
            contents[name]=decode_json(data)
        c=contents['collection.json']
        if not isinstance(c,dict) or set(c)!={'state','origin','profile_binding','rows','coverage','lifecycle','reasons'}:raise AuditError('prior-schema')
        try:collection=Collection(**c)
        except TypeError:raise AuditError('prior-schema') from None
        if collection.state!='complete' or collection.origin!='fictional' or any(collection.lifecycle.get(x)!='observed' for x in ('open','rollback','postflight','close')) or collection.reasons:raise AuditError('prior-incomplete')
        compare(collection,None,descriptor,fictional_profile())
        if dict(collection.coverage)!={key:len(value) for key,value in collection.rows.items()}:raise AuditError('prior-coverage')
        source.check();root.check();return collection


def summarize(ref,collection,analysis):
    if ref.origin!='fictional' or collection.origin!='fictional':raise AuditError('summary-origin')
    counts=Counter()
    for reason in collection.reasons:counts[reason]+=1
    if ref.collection_state!='complete':
        counts['collection_incomplete']+=1
        if collection.state=='complete':counts['finalization_authority_lost']+=1
    if analysis and ref.collection_state=='complete':
        for values in analysis.exceptions.values():counts.update(values)
    return PublicSummary({'attempt_id':ref.attempt_id,'origin':'fictional','completion':ref.collection_state,
                          'coverage':dict(collection.coverage),'reason_counts':dict(sorted(counts.items())),
                          'evidence':plain(ref)})
