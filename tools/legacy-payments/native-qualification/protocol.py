"""QA-only receipts. Parsing a reference does not read or qualify its contents."""
from dataclasses import dataclass

from _schema import AdmissionError, CASES, PHASES, MAX_CASE, EvidenceRef, digest, document, keys, reference, require


@dataclass(frozen=True)
class CaseReceipt:
    purpose: str
    run_id: str
    case_id: str
    phase: str
    evidence: tuple[EvidenceRef, ...]

    def __post_init__(self):
        require(self.purpose == 'source-payment-qa-v1', 'case-purpose')
        digest(self.run_id)
        require(type(self.case_id) is str and self.case_id in CASES, 'case-id')
        require(type(self.phase) is str and self.phase in PHASES, 'case-phase')
        require(type(self.evidence) is tuple and 1 <= len(self.evidence) <= 8 and
                all(type(ref) is EvidenceRef for ref in self.evidence), 'case-evidence')
        require(len({ref.path for ref in self.evidence}) == len(self.evidence), 'reference-alias')
        for ref in self.evidence:
            ref.__post_init__()


@dataclass(frozen=True)
class Qualification:
    framework_atomicity: bool
    manual_atomicity: bool
    provider_validation: bool
    financial_posting_ready: bool
    blockers: tuple[str, ...]

    def __post_init__(self):
        require(all(type(value) is bool for value in (self.framework_atomicity,
                    self.manual_atomicity, self.provider_validation, self.financial_posting_ready)),
                'qualification-type')
        require(not self.provider_validation and not self.financial_posting_ready,
                'qa-not-financial-authority')
        require(not self.manual_atomicity or self.framework_atomicity, 'qualification-order')
        require(type(self.blockers) is tuple and
                self.blockers == tuple(sorted(set(self.blockers))), 'qualification-blockers')


def parse_case(raw: bytes) -> CaseReceipt:
    value = document(raw, MAX_CASE)
    keys(value, 'purpose run_id case_id phase evidence')
    require(type(value['evidence']) is list, 'case-evidence')
    return CaseReceipt(value['purpose'], value['run_id'], value['case_id'], value['phase'],
                       tuple(reference(ref) for ref in value['evidence']))
