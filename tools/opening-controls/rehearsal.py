"""Root-only Linux rehearsal on newly owned RAM fixtures; no application imports."""
import fcntl
import hashlib
import hmac
import http.client
from http.server import BaseHTTPRequestHandler
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
import socket
import socketserver
import sqlite3
import subprocess
import sys
import tempfile
import time

from control import ControlError, ControlSession, operator_alive, process_identity
from custody import Custody, strict_json


MANIFEST = {'schema_version': 1, 'purpose': 'opening-control-rehearsal-manifest',
            'freeze_id': 'disposable-fixture', 'controls': ['source-writer', 'target-writer', 'ingress']}


def wait_for(predicate, timeout=5):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if predicate():
            return
        time.sleep(0.025)
    raise AssertionError('Disposable rehearsal condition timed out')


def save(path, value):
    with path.open('xb') as stream:
        os.fchmod(stream.fileno(), 0o600)
        stream.write(json.dumps(value, sort_keys=True).encode())
        stream.flush()
        os.fsync(stream.fileno())


def writer(root, actor):
    db = sqlite3.connect(str(root / 'ledger.sqlite'), timeout=5)
    while True:
        with (root / 'writer-interlock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            try:
                state = root / 'control' / 'control-state.json'
                gates = list((root / 'control').glob('*.gate'))
                blocked = bool(gates)
                if state.exists():
                    try:
                        blocked = blocked or strict_json(state.read_bytes())['status'] != 'released'
                    except (OSError, ValueError, KeyError):
                        blocked = True
                with db:
                    db.execute('INSERT INTO attempts(actor,blocked) VALUES (?,?)', (actor, int(blocked)))
                    if not blocked:
                        db.execute('INSERT INTO bookings(actor) VALUES (?)', (actor,))
            finally:
                fcntl.flock(lock, fcntl.LOCK_UN)
        time.sleep(0.04)


class UnixHTTPServer(socketserver.UnixStreamServer):
    allow_reuse_address = False


def callback_server(root):
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass  # Raw callbacks never enter access logs.

        def do_POST(self):
            try:
                lengths = self.headers.get_all('Content-Length', [])
                if len(lengths) != 1 or not lengths[0].isdigit() or not 0 <= int(lengths[0]) <= 1048576:
                    self.send_error(400)
                    return
                body = self.rfile.read(int(lengths[0]))
                if len(body) != int(lengths[0]):
                    self.send_error(400)
                    return
                envelope = {'method': 'POST', 'target': self.path,
                            'headers': [[k, v] for k, v in self.headers.raw_items()], 'body': body}
                with Custody(root / 'custody') as journal:
                    if self.path == '/authorizenet':
                        receipt = journal.authorizenet(envelope, (root / 'fixture.key').read_bytes())
                        status = 200 if receipt['delivery_acknowledged'] else 409
                        reply = b'Received for reconciliation' if status == 200 else b'Not acknowledged'
                    else:
                        provider = self.path[1:] if self.path in {'/webmoney', '/klarna', '/wave'} else 'unknown'
                        journal.retain(provider, envelope)
                        status, reply = (200, b'NO') if provider == 'webmoney' else (503, b'Not acknowledged')
                self.send_response(status)
                self.send_header('Content-Length', str(len(reply)))
                self.end_headers()
                self.wfile.write(reply)
            except Exception:
                self.send_error(503, 'Durable custody unavailable')

    with UnixHTTPServer(str(root / 'callback.sock'), Handler) as server:
        os.chmod(root / 'callback.sock', 0o600)
        server.serve_forever(poll_interval=0.05)


def custodian(root):
    identity = strict_json((root / 'operator.json').read_bytes())
    with ControlSession(root / 'control', MANIFEST, identity) as session:
        while True:
            permit = session.renew(int(time.time()))
            target = root / 'permit.json'
            temporary = root / 'permit-next.json'
            save(temporary, permit)
            os.replace(temporary, target)
            time.sleep(0.1)


def rehearse(evidence=None):
    if sys.platform != 'linux' or os.geteuid() != 0:
        raise RuntimeError('Native acceptance requires an isolated root Linux process')
    if shutil.disk_usage('/').free < 1024 ** 3 or shutil.disk_usage('/dev/shm').free < 512 * 1024 ** 2:
        raise RuntimeError('Preserve root and RAM reserve floors')
    started = time.monotonic()
    root = Path(tempfile.mkdtemp(prefix='opening-controls-rehearsal-', dir='/dev/shm')).resolve()
    owned = []
    checks = {}
    result = {'purpose': 'opening-controls-disposable-acceptance', 'real_execution_ready': False}
    def spawn(kind):
        process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--worker', kind, str(root)],
                                   stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        owned.append(process)
        return process
    def stop(process, sig=signal.SIGTERM):
        if process.poll() is None:
            process.send_signal(sig)
        process.wait(timeout=5)
    try:
        for name in ('control', 'custody'):
            (root / name).mkdir(mode=0o700)
        dbpath = root / 'ledger.sqlite'
        dbpath.touch(mode=0o600)
        db = sqlite3.connect(str(dbpath))
        db.executescript('CREATE TABLE bookings(actor TEXT NOT NULL); CREATE TABLE attempts(actor TEXT NOT NULL,blocked INTEGER NOT NULL);')
        db.close()
        lockpath = root / 'writer-interlock'
        lockpath.touch(mode=0o600)
        key = secrets.token_bytes(64)
        with (root / 'fixture.key').open('xb') as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(key)
        with Custody(root / 'custody'):
            pass
        source = spawn('source-writer')
        target = spawn('target-writer')
        def count(query):
            with sqlite3.connect(str(dbpath)) as conn:
                return conn.execute(query).fetchone()[0]
        wait_for(lambda: count('SELECT COUNT(DISTINCT actor) FROM bookings') == 2)
        operator = spawn('operator')
        identity = process_identity(operator.pid)
        save(root / 'operator.json', identity)
        with lockpath.open('r') as interlock:
            fcntl.flock(interlock, fcntl.LOCK_EX)
            with ControlSession(root / 'control', MANIFEST, identity) as session:
                session.establish()
            baseline = count('SELECT COUNT(*) FROM bookings')
        heartbeat = spawn('custodian')
        wait_for(lambda: (root / 'permit.json').exists() and strict_json((root / 'permit.json').read_bytes())['sequence'] >= 2)
        callbacks = spawn('callbacks')
        wait_for(lambda: (root / 'callback.sock').exists())
        def deliver(path, body, valid=True):
            conn = http.client.HTTPConnection('fixture.invalid', timeout=5)
            conn.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            conn.sock.connect(str(root / 'callback.sock'))
            signature = hmac.new(key, body, hashlib.sha512).hexdigest() if valid else '0' * 128
            conn.request('POST', path, body=body, headers={'X-ANET-Signature': 'sha512=' + signature})
            response = conn.getresponse()
            status, reply = response.status, response.read()
            conn.close()
            return status, reply
        body = b'{"notificationId":"fixture-event","legacy_reference":"source-owned"}'
        checks['late_notification_durable_ack'] = deliver('/authorizenet', body)[0] == 200
        checks['duplicate_ack_one_event'] = deliver('/authorizenet', body)[0] == 200
        changed = body.replace(b'source-owned', b'changed-reference')
        checks['conflicting_notification_denied'] = deliver('/authorizenet', changed)[0] == 409
        checks['invalid_signature_denied'] = deliver('/authorizenet', body, False)[0] == 409
        checks['unknown_alias_retained_and_denied'] = deliver('/alternate-manager', body)[0] == 503
        checks['webmoney_checkout_denied'] = deliver('/webmoney', b'LMI_PREREQUEST=1') == (200, b'NO')
        checks['unaccepted_provider_protocol_denied'] = all(deliver('/' + name, body)[0] == 503 for name in ('klarna', 'wave'))
        stop(heartbeat, signal.SIGKILL)
        permit = strict_json((root / 'permit.json').read_bytes())
        checks['custodian_crash_keeps_all_gates'] = len(list((root / 'control').glob('*.gate'))) == 3
        with ControlSession(root / 'control', MANIFEST, identity) as recovered:
            checks['expiry_denies_without_release'] = not recovered.valid(permit, permit['expires_at'])
            fresh = recovered.renew(int(time.time()))
            checks['fresh_process_sequence_continues'] = fresh['sequence'] > permit['sequence']
            try:
                recovered.release('RELEASE DISPOSABLE REHEARSAL')
            except ControlError:
                checks['live_operator_blocks_release'] = True
            stop(operator)
            checks['operator_exit_denies_permit'] = not recovered.valid(fresh, int(time.time()))
            checks['operator_exit_keeps_gates'] = len(list((root / 'control').glob('*.gate'))) == 3
            checks['recurring_source_and_target_writes_stayed_frozen'] = count('SELECT COUNT(*) FROM bookings') == baseline
            result['writer_attempts_while_frozen'] = count('SELECT COUNT(*) FROM attempts WHERE blocked=1')
            with Custody(root / 'custody') as journal:
                result['custody_counts'] = journal.counts()
            recovered.release('RELEASE DISPOSABLE REHEARSAL')
        wait_for(lambda: count('SELECT COUNT(*) FROM bookings') > baseline)
        checks['explicit_release_after_exit_resumes_fixture'] = True
        for process in (source, target, callbacks):
            stop(process)
        checks['custody_not_financial_settlement'] = result['custody_counts'] == {'arrivals': 8, 'events': 1, 'quarantined': 6}
        if evidence is not None:
            from custody import private_directory
            evidence = private_directory(evidence)
            for src, name in ((dbpath, 'fixture-ledger.sqlite'), (root / 'custody/custody.sqlite', 'fixture-custody.sqlite'),
                              (root / 'control/control-state.json', 'fixture-control-state.json')):
                with (evidence / name).open('xb') as stream:
                    os.fchmod(stream.fileno(), 0o600)
                    stream.write(src.read_bytes())
        result['checks'] = checks
        result['elapsed_seconds'] = round(time.monotonic() - started, 3)
        assert all(checks.values()) and result['writer_attempts_while_frozen'] > 0
    finally:
        for process in owned:
            stop(process)
            process.stderr.close()
        result['remaining_owned_processes'] = [p.pid for p in owned if p.poll() is None]
        shutil.rmtree(root)
        result['owned_root_removed'] = not root.exists()
    return result


if __name__ == '__main__':
    if len(sys.argv) == 4 and sys.argv[1] == '--worker':
        kind, root = sys.argv[2], Path(sys.argv[3])
        if os.geteuid() != 0 or root.parent != Path('/dev/shm') or not root.name.startswith('opening-controls-rehearsal-'):
            raise RuntimeError('Worker accepts only owned disposable RAM fixtures')
        if kind in {'source-writer', 'target-writer'}:
            writer(root, kind)
        elif kind == 'custodian':
            custodian(root)
        elif kind == 'callbacks':
            callback_server(root)
        elif kind == 'operator':
            time.sleep(60)
        else:
            raise RuntimeError('Unknown disposable worker')
    elif len(sys.argv) in {1, 3}:
        evidence = Path(sys.argv[2]) if len(sys.argv) == 3 and sys.argv[1] == '--evidence' else None
        if len(sys.argv) == 3 and evidence is None:
            raise RuntimeError('Only --evidence PRIVATE_DIRECTORY is supported')
        print(json.dumps(rehearse(evidence), sort_keys=True))
    else:
        raise RuntimeError('Unknown rehearsal command')
