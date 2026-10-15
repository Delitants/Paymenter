"""Pure scheduler projection/preflight. Physical Cronie operations are unqualified.

No file is opened, replaced or restored here. A review digest and byte projection
are planning inputs, never proof of installed ownership or noticed generation.
"""
from dataclasses import dataclass
import re
from containment_schema import (ContainmentError, MAX_PART, MAX_GRAPH, MAX_ENTRIES,
    digest, integer, require, sha, text)


@dataclass(frozen=True)
class ReviewedEntry:
    start: int
    end: int
    sha256: str
    object_id: str
    writer_id: str
    shared: bool
    unresolved: tuple[str, ...]

    def __post_init__(self):
        integer(self.start, 0, MAX_PART); integer(self.end, self.start+1, MAX_PART)
        sha(self.sha256); text(self.object_id); text(self.writer_id)
        require(type(self.shared) is bool and type(self.unresolved) is tuple)
        require(len(self.unresolved) <= MAX_ENTRIES)
        for code in self.unresolved: text(code)


def _bytes(raw):
    require(type(raw) is bytes and len(raw) <= MAX_PART, 'scheduler-document-size')


def _entry_syntax(raw):
    # Deliberately narrow lexical subset. Even accepted literals do not prove
    # what a wrapper dispatches. No command token is executed or rewritten.
    line = raw[:-2] if raw.endswith(b'\r\n') else raw[:-1] if raw.endswith(b'\n') else raw
    require(line and not re.search(rb'[^\x20-\x7e\t]', line), 'scheduler-entry-syntax')
    fields = line.split()
    require(fields and not fields[0].startswith(b'#'), 'scheduler-entry-syntax')
    if fields[0].startswith(b'@'):
        require(fields[0] in (b'@reboot', b'@yearly', b'@annually', b'@monthly',
                b'@weekly', b'@daily', b'@midnight', b'@hourly'), 'scheduler-entry-syntax')
        command = fields[1:]
    else:
        require(len(fields) >= 6 and all(re.fullmatch(rb'[A-Za-z0-9*,/\-]+', f)
                for f in fields[:5]), 'scheduler-entry-syntax')
        command = fields[5:]
    require(command and all(re.fullmatch(rb'[A-Za-z0-9_./:=+\-]+', f)
            for f in command), 'scheduler-entry-syntax')


def suppress_entries(original: bytes, entries: tuple[tuple[int, int, str], ...]) -> bytes:
    """Delete exact whole single-line spans; neither ownership nor native proof."""
    _bytes(original)
    require(type(entries) is tuple and len(entries) <= MAX_ENTRIES, 'scheduler-spans')
    previous = 0
    output = []
    for span in entries:
        require(type(span) is tuple and len(span) == 3, 'scheduler-spans')
        start, end, expected = span
        integer(start, previous, len(original)); integer(end, start+1, len(original))
        sha(expected)
        require((start == 0 or original[start-1:start] == b'\n') and
                not original[:start].endswith((b'\\\n', b'\\\r\n')) and
                (end == len(original) or original[end-1:end] == b'\n') and
                not (end < len(original) and original[end-1:end+1] == b'\r\n'),
                'scheduler-entry-boundary')
        selected = original[start:end]
        require(digest(selected) == expected, 'scheduler-entry-hash')
        _entry_syntax(selected)
        output.append(original[previous:start]); previous = end
    output.append(original[previous:])
    return b''.join(output)


def compile_suppression(original, expected_sha256, entries, *,
                        exclusive_maintenance, review_sha256):
    """Reject unresolved/shared entries before producing a candidate byte string.

    Caller-supplied review/authority flags cannot admit physical operations.
    """
    _bytes(original); sha(expected_sha256); sha(review_sha256)
    require(digest(original) == expected_sha256, 'scheduler-original-drift')
    require(exclusive_maintenance is True, 'maintenance-unaccepted')
    require(type(entries) is tuple and len(entries) <= MAX_ENTRIES, 'scheduler-spans')
    require(all(type(e) is ReviewedEntry for e in entries), 'scheduler-spans')
    require(len({e.object_id for e in entries}) == len(entries), 'scheduler-duplicate-object')
    require(all(not e.shared and not e.unresolved for e in entries),
            'scheduler-scope-unproved')
    return suppress_entries(original, tuple((e.start, e.end, e.sha256) for e in entries))


def classify_scheduler_files(originals, suppressed, current):
    """Pure comparison only. Partial/competing results never restore bytes."""
    for group in (originals, suppressed, current):
        require(type(group) is tuple and len(group) <= MAX_ENTRIES, 'scheduler-files')
    require(len(originals) == len(suppressed) == len(current), 'scheduler-files')
    require(len(originals+suppressed+current) <= MAX_ENTRIES, 'scheduler-graph-size')
    for raw in originals+suppressed+current: _bytes(raw)
    require(sum(map(len, originals+suppressed+current)) <= MAX_GRAPH, 'scheduler-graph-size')
    result = []
    for before, after, observed in zip(originals, suppressed, current):
        for raw in (before, after, observed): _bytes(raw)
        result.append('original' if observed == before else
                      'suppressed' if observed == after else 'competing')
    return tuple(result)


def establish_scheduler(ctx, originals):
    raise ContainmentError('scheduler-native-runtime-unqualified')


def recover_scheduler(ctx):
    raise ContainmentError('scheduler-native-runtime-unqualified')


def release_scheduler(ctx, retirement):
    raise ContainmentError('scheduler-native-runtime-unqualified')
