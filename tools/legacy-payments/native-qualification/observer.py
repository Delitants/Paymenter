"""Decode framework observations only. No DB channel, live issuer or commit proof.

All metadata below is supplied data. A future protected collector must establish
it from the actual source connection and retain the original bytes. A nonce,
timestamp or claimed isolation value in JSON does not establish that custody.
"""
from dataclasses import dataclass
import re

from _schema import (AdmissionError, CASES, PHASES, digest, document, keys,
                     require, text, utc)


def connection_id(value):
    require(type(value) is str and re.fullmatch(r'[1-9][0-9]{0,19}', value, re.ASCII),
            'observer-connection')
    return value


@dataclass(frozen=True)
class ReadExpectation:
    run_id: str
    runtime_id: str
    boot_id: str
    request_id: str
    case_id: str
    phase: str
    writer_connection: str
    facts_hash: str
    not_before: str
    deadline: str
    previous_readers: tuple[str, ...]

    def __post_init__(self):
        for value in (self.run_id, self.request_id, self.facts_hash):
            digest(value)
        text(self.runtime_id, 128)
        text(self.boot_id, 128)
        require(type(self.case_id) is str and self.case_id in CASES[:5],
                'observer-framework-only')
        require(type(self.phase) is str and self.phase in PHASES, 'observer-phase')
        connection_id(self.writer_connection)
        require(0 < (utc(self.deadline) - utc(self.not_before)).total_seconds() <= 30,
                'observer-window')
        require(type(self.previous_readers) is tuple and len(self.previous_readers) <= 64,
                'observer-history')
        for value in self.previous_readers:
            connection_id(value)
        require(len(set(self.previous_readers)) == len(self.previous_readers), 'observer-history')


@dataclass(frozen=True)
class BusinessRow:
    run_id: str
    case_id: str
    amount: str
    facts_hash: str


@dataclass(frozen=True)
class JournalRow:
    run_id: str
    case_id: str
    business_case_id: str
    facts_hash: str


@dataclass(frozen=True)
class Observation:
    run_id: str
    runtime_id: str
    boot_id: str
    request_id: str
    case_id: str
    phase: str
    started_at: str
    finished_at: str
    isolation: str
    reader_connection: str
    writer_connection: str
    business_rows: tuple[BusinessRow, ...]
    journal_rows: tuple[JournalRow, ...]
    payment_rows: tuple
    subaccount_rows: tuple


def decode_observation(raw: bytes, expected: ReadExpectation) -> Observation:
    """Validate a supplied document against supplied context; never qualify it.

    Pair/split/absent QA rows are preserved for later outcome analysis. Native
    payment/subaccount rows are deliberately unsupported until their original
    schema and read channel are qualified. No guessed native IDs are accepted.
    """
    require(type(expected) is ReadExpectation, 'observer-expectation')
    expected.__post_init__()
    value = document(raw)
    keys(value, 'purpose run_id runtime_id boot_id request_id case_id phase started_at '
         'finished_at isolation reader_connection writer_connection autocommit '
         'transaction_open business_rows journal_rows payment_rows subaccount_rows')
    require(value['purpose'] == 'source-payment-qa-observation-v1', 'observer-purpose')
    for field in ('run_id', 'runtime_id', 'boot_id', 'request_id', 'case_id', 'phase',
                  'writer_connection'):
        require(value[field] == getattr(expected, field), 'observer-binding')
    reader = connection_id(value['reader_connection'])
    require(reader != expected.writer_connection and reader not in expected.previous_readers,
            'observer-independent-reader')
    require(value['isolation'] == 'READ COMMITTED' and value['autocommit'] is True and
            value['transaction_open'] is False, 'observer-isolation')
    require(utc(expected.not_before) <= utc(value['started_at']) <=
            utc(value['finished_at']) <= utc(expected.deadline), 'observer-window')
    require(value['payment_rows'] == [] and value['subaccount_rows'] == [],
            'observer-framework-only')
    rows = {}
    for slot, row_type, columns in (
            ('business_rows', BusinessRow, 'run_id case_id amount facts_hash'),
            ('journal_rows', JournalRow, 'run_id case_id business_case_id facts_hash')):
        require(type(value[slot]) is list and len(value[slot]) <= 1, 'observer-rows')
        decoded = []
        for row in value[slot]:
            keys(row, columns)
            require(row['run_id'] == expected.run_id and row['case_id'] == expected.case_id and
                    row['facts_hash'] == expected.facts_hash, 'observer-row-binding')
            if slot == 'business_rows':
                require(row['amount'] == '0.0100', 'observer-amount')
            else:
                require(row['business_case_id'] == expected.case_id, 'observer-row-binding')
            decoded.append(row_type(**row))
        rows[slot] = tuple(decoded)
    return Observation(*(value[field] for field in ('run_id', 'runtime_id', 'boot_id',
                       'request_id', 'case_id', 'phase', 'started_at', 'finished_at',
                       'isolation', 'reader_connection', 'writer_connection')),
                       rows['business_rows'], rows['journal_rows'], (), ())


class CommittedObserver:
    """Reserved live interface. JSON decoding cannot supply its native issuer."""

    def read(self, run, case_id, phase):
        raise AdmissionError('native-issuer-unavailable')
