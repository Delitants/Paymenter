"""Local byte/identity checks only; no spawn, signal, SSH, SQL or native cleanup.

Neither the collector nor a process-identity record grants execution authority.
"""
from dataclasses import dataclass

from _schema import AdmissionError, CASES, PHASES, MAX_CASE, digest, require, text
from protocol import parse_case


class BoundedCollector:
    """Single terminal capture; preserves bounded private bytes on every failure.

    Callers supply pipe data/EOF/exit/deadline facts. This class cannot establish
    their native provenance or supervise a process. Its per-capture 1 MiB limit
    does not implement the future issuer's aggregate per-operation budget.
    """
    def __init__(self, run_id, case_id, phase):
        digest(run_id)
        require(type(case_id) is str and case_id in CASES, 'collector-case')
        require(type(phase) is str and phase in PHASES, 'collector-phase')
        self._expected = (run_id, case_id, phase)
        self._stdout = bytearray()
        self._stderr = bytearray()
        self._closed = False
        self._failure = None

    @property
    def stdout(self):
        return bytes(self._stdout)

    @property
    def stderr(self):
        return bytes(self._stderr)

    @property
    def failure(self):
        return self._failure

    def _open(self):
        require(not self._closed, self._failure or 'collector-closed')

    def _fail(self, code):
        self._closed = True
        self._failure = code
        raise AdmissionError(code)

    def feed(self, stream, chunk):
        self._open()
        if type(stream) is not str or stream not in ('stdout', 'stderr') or type(chunk) is not bytes:
            self._fail('collector-input')
        buffer = self._stdout if stream == 'stdout' else self._stderr
        available = 1048576 - len(self._stdout) - len(self._stderr)
        if stream == 'stdout':
            available = min(available, MAX_CASE - len(buffer))
        buffer.extend(chunk[:available])
        if len(chunk) > available:
            self._fail('collector-output-limit')

    def finish(self, exitcode, *, stdout_eof, stderr_eof, deadline_expired):
        self._open()
        self._closed = True
        try:
            require(type(exitcode) is int and exitcode == 0 and stdout_eof is True and
                    stderr_eof is True and deadline_expired is False and not self._stderr,
                    'collector-incomplete')
            receipt = parse_case(bytes(self._stdout))
            require((receipt.run_id, receipt.case_id, receipt.phase) == self._expected,
                    'collector-binding')
            return receipt
        except AdmissionError as exc:
            self._failure = str(exc)
            raise


@dataclass(frozen=True)
class ProcessIdentity:
    """Candidate metadata only; not an owned-process handle or signal permit."""
    run_id: str
    boot_id: str
    pid: int
    start_ticks: int
    role: str

    def __post_init__(self):
        digest(self.run_id)
        text(self.boot_id, 128)
        require(type(self.pid) is int and 1 < self.pid <= 2147483647, 'process-pid')
        require(type(self.start_ticks) is int and 0 < self.start_ticks < 2**63, 'process-start')
        require(type(self.role) is str and self.role in ('caller', 'observer'), 'process-role')


def same_process(registered: ProcessIdentity, observed: ProcessIdentity) -> bool:
    """Compare supplied metadata only. Never authorizes signalling either PID."""
    require(type(registered) is ProcessIdentity and type(observed) is ProcessIdentity,
            'process-identity')
    registered.__post_init__()
    observed.__post_init__()
    return registered == observed


class Operator:
    """Reserved native interface; partial install/cleanup claims confer nothing."""

    def execute(self, run):
        raise AdmissionError('native-issuer-unavailable')
