"""Bounded, closed document primitives. No filesystem, process or network I/O."""
from dataclasses import dataclass
from datetime import datetime, timezone
import json
from pathlib import PurePosixPath
import re

MAX_DOCUMENT = 65536
MAX_CASE = 16384
CASES = tuple('F' + str(n) for n in range(1, 6)) + tuple('M' + str(n) for n in range(1, 6))
PHASES = ('before', 'barrier', 'after', 'retired')


class AdmissionError(ValueError):
    """Fixed error codes only; never echo private document values."""


def require(condition, code):
    if not condition:
        raise AdmissionError(code)


def text(value, maximum=4096):
    require(type(value) is str and 0 < len(value) <= maximum, 'text')
    require(not any(ord(c) < 32 or ord(c) == 127 for c in value), 'text')
    try:
        value.encode('utf-8', errors='strict')
    except UnicodeError:
        raise AdmissionError('text') from None
    return value


def digest(value):
    require(type(value) is str and re.fullmatch('[0-9a-f]{64}', value) is not None, 'digest')
    return value


def utc(value):
    require(type(value) is str and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ', value, re.ASCII), 'utc')
    try:
        return datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)
    except ValueError:
        raise AdmissionError('utc') from None


def keys(value, expected):
    require(type(value) is dict and set(value) == set(expected.split()), 'evidence-fields')


def _pairs(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'duplicate-key')
        result[key] = value
    return result


def _integer(value):
    require(len(value.lstrip('-')) <= 19, 'json-number')
    result = int(value)
    require(-(2**63) <= result < 2**63, 'json-number')
    return result


def _no_number(unused):
    raise AdmissionError('json-number')


def document(raw, maximum=MAX_DOCUMENT):
    require(type(raw) is bytes and 0 < len(raw) <= maximum, 'document-size')
    # Bound nesting before the general-purpose decoder allocates nested objects.
    depth = 0
    quoted = escaped = False
    for char in raw:
        if quoted:
            if escaped:
                escaped = False
            elif char == 92:
                escaped = True
            elif char == 34:
                quoted = False
        elif char == 34:
            quoted = True
        elif char in (91, 123):
            depth += 1
            require(depth <= 8, 'json-depth')
        elif char in (93, 125):
            depth -= 1
            require(depth >= 0, 'json-shape')
    try:
        value = json.loads(raw.decode('utf-8', errors='strict'), object_pairs_hook=_pairs,
                           parse_int=_integer, parse_float=_no_number, parse_constant=_no_number)
    except (UnicodeError, json.JSONDecodeError, RecursionError):
        raise AdmissionError('json') from None
    require(type(value) is dict, 'document-root')
    stack = [value]
    entries = 0
    while stack:
        item = stack.pop()
        entries += 1
        require(entries <= 2048, 'document-entries')
        if type(item) is dict:
            require(len(item) <= 32, 'document-fields')
            for name in item:
                text(name, 128)
            stack.extend(item.values())
        elif type(item) is list:
            require(len(item) <= 128, 'document-items')
            stack.extend(item)
        elif type(item) is str:
            text(item)
    return value


@dataclass(frozen=True)
class EvidenceRef:
    path: str
    sha256: str
    bytes: int

    def __post_init__(self):
        text(self.path)
        require(self.path.startswith('/') and not self.path.startswith('//') and self.path != '/' and
                str(PurePosixPath(self.path)) == self.path and
                '..' not in PurePosixPath(self.path).parts and '\\' not in self.path,
                'reference-path')
        digest(self.sha256)
        require(type(self.bytes) is int and 1 <= self.bytes <= MAX_DOCUMENT, 'reference-size')


def reference(value):
    keys(value, 'path sha256 bytes')
    return EvidenceRef(value['path'], value['sha256'], value['bytes'])
