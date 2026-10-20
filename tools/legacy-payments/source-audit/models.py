"""Closed audit values. No source adapter or financial authority."""
from dataclasses import dataclass
from datetime import datetime, timezone
from enum import Enum
from typing import Mapping, Protocol
import time

class AuditError(Exception):
    def __init__(self, code):
        # Never reflect input, SQL or exception text into diagnostics.
        import re
        self.code = code if isinstance(code,str) and re.fullmatch(r'[a-z][a-z0-9-]{0,63}',code) else 'audit-refused'
        super().__init__(self.code)

class Clock(Protocol):
    def utc_now(self) -> datetime: ...
    def monotonic(self) -> float: ...

class SystemClock:
    def utc_now(self): return datetime.now(timezone.utc)
    def monotonic(self): return time.monotonic()

class Dataset(str, Enum):
    ACCOUNTS='accounts'; SUBACCOUNTS='subaccounts'; PAYMENTS='payments'; METHODS='methods'; CURRENCIES='currencies'

CAPS={Dataset.ACCOUNTS:2048,Dataset.SUBACCOUNTS:4096,Dataset.PAYMENTS:50000,Dataset.METHODS:256,Dataset.CURRENCIES:256}

@dataclass(frozen=True)
class Profile:
    profile_id: str
    origin: str
    server_hash: str
    query_hash: str
    schema_hash: str

@dataclass(frozen=True)
class Descriptor:
    attempt_id: str
    approval_scope_ref: str
    not_before_utc: datetime
    expires_utc: datetime
    source_instance: str
    database_identity: str
    socket_identity: str
    server_profile_hash: str
    query_profile_hash: str
    schema_hash: str
    cohort_ref: str
    account_ids: tuple[str,...]
    held_account_ids: tuple[str,...]
    previous_ref: object
    output_root: str
    source_path: str=""
    source_digest: str=""
    def __post_init__(self):
        from types import MappingProxyType
        if self.previous_ref is not None:object.__setattr__(self,"previous_ref",MappingProxyType(dict(self.previous_ref)))

@dataclass(frozen=True)
class FieldSpec:
    precision: int
    scale: int
    nullable: bool=False

Row=Mapping[str,str|None]

@dataclass(frozen=True)
class ExactValue:
    original:str
    canonical:str


def parse_decimal(raw,field):
    import re
    if not isinstance(raw,str) or len(raw)>128 or not isinstance(field,FieldSpec) or type(field.precision) is not int or type(field.scale) is not int or not 0<=field.scale<=field.precision<=65:raise AuditError('row-decimal')
    match=re.fullmatch(r'(-?)([0-9]+)(?:\.([0-9]+))?',raw)
    if match is None:raise AuditError('row-decimal')
    sign,whole,fraction=match.groups();fraction=fraction or ''
    if len(fraction)>field.scale or len(whole.lstrip('0'))>field.precision-field.scale:raise AuditError('row-decimal')
    whole=str(int(whole));fraction=fraction.ljust(field.scale,'0')
    if whole=='0' and not fraction.strip('0'):sign=''
    return ExactValue(raw,sign+whole+('.'+fraction if field.scale else ''))


class AuthorityClock:
    """One sticky, nondecreasing UTC history for the entire attempt."""
    def __init__(self,clock):self.clock=clock;self.last=None;self.failed=False
    def utc_now(self):
        if self.failed:raise AuditError('authority-clock')
        now=self.clock.utc_now()
        if not isinstance(now,datetime) or now.tzinfo is None or (self.last is not None and now<self.last):
            self.failed=True;raise AuditError('authority-clock')
        self.last=now;return now
    def monotonic(self):return self.clock.monotonic()
