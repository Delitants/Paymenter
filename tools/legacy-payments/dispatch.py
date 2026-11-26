"""Sign committed BILLmanager journal rows and deliver them over private SSH."""
from __future__ import print_function

import argparse
import base64
import binascii
from datetime import datetime
from hashlib import sha256
import json
import os
import re
import stat
import subprocess
import sys
import tempfile


DOCUMENT_LIMIT = 65536
ACK_LIMIT = 2048
HEADER = b'PAYMENTER-LEGACY-V1\n'
STATUS_HEADER = b'PAYMENTER-LEGACY-STATUS-V1\n'
TERMINAL = ('included', 'applied', 'applied_zero')
IDENTITY_FIELDS = {'source_instance', 'merchant_binding', 'source_payment', 'effect_kind', 'economic_effect_id'}
ECONOMIC_FIELDS = {'source_account', 'source_subaccount', 'parent_effect_key', 'wallet_currency', 'wallet_delta',
                   'wallet_components', 'provider_currency', 'provider_components', 'allocation_rule',
                   'allocation_evidence_sha256'}
OBSERVATION_FIELDS = {'custody_id', 'journal_id', 'raw_sha256', 'validation_ref', 'validator_sha256',
                      'credential_version', 'commit_binding_ref'}
try:
    INTEGER_TYPES = (int, long)
    STRING_TYPES = (basestring,)
except NameError:
    INTEGER_TYPES = (int,)
    STRING_TYPES = (str,)


class DispatchError(Exception):
    pass


def require(condition, reason):
    if not condition:
        raise DispatchError(reason)


def canonical(value):
    encoded = json.dumps(value, ensure_ascii=False, separators=(',', ':'), sort_keys=True, allow_nan=False)
    return encoded.encode('utf-8').replace(b'\xe2\x80\xa8', b'\\u2028').replace(b'\xe2\x80\xa9', b'\\u2029')


def parse_document(raw, limit):
    require(isinstance(raw, bytes) and 0 < len(raw) <= limit, 'document size')
    try:
        parsed = json.loads(raw.decode('utf-8'))
        require(isinstance(parsed, dict) and canonical(parsed) == raw, 'noncanonical document')
        return parsed
    except (ValueError, TypeError, UnicodeError, RuntimeError):
        raise DispatchError('invalid document')


def digest(raw):
    return sha256(raw).hexdigest()


def token(value, pattern):
    return isinstance(value, STRING_TYPES) and re.match(pattern + r'\Z', value) is not None


def utc(value):
    require(token(value, r'[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z'), 'invalid UTC time')
    try:
        return datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ')
    except ValueError:
        raise DispatchError('invalid UTC time')


def inspect(row):
    envelope = parse_document(row['payload'], DOCUMENT_LIMIT)
    require(set(envelope) == {'schema_version', 'purpose', 'issuer', 'not_before', 'expires_at',
                             'nonce', 'identity', 'economic', 'observation'}
            and type(envelope['schema_version']) in INTEGER_TYPES and envelope['schema_version'] in (1, 2)
            and envelope['purpose'] == 'legacy-payment-observation', 'wrong receipt envelope')
    identity, economic, observation = (envelope[name] for name in ('identity', 'economic', 'observation'))
    require(isinstance(identity, dict) and set(identity) == IDENTITY_FIELDS
            and isinstance(economic, dict) and set(economic) == ECONOMIC_FIELDS
            and isinstance(observation, dict) and set(observation) == OBSERVATION_FIELDS, 'wrong journal shape')
    for field in ('raw_sha256', 'validator_sha256'):
        require(token(observation[field], r'[a-f0-9]{64}'), 'wrong observation digest')
    for group, field in (('wallet_components', 'delta'), ('provider_components', 'amount')):
        components = economic[group]
        require(isinstance(components, list) and 0 < len(components) <= 16 and
                all(isinstance(part, dict) and set(part) == {'component_id', field} for part in components),
                'wrong monetary component shape')
    require(token(row['key_id'], r'[a-zA-Z][a-zA-Z0-9_.-]{0,127}')
            and token(observation['commit_binding_ref'], r'sha256:[a-f0-9]{64}')
            and digest(row['payload']) == row['payload_sha256']
            and digest(b'legacy-economic-effect-v1\0' + canonical(identity)) == row['effect_key']
            and digest(b'legacy-economic-facts-v1\0' + canonical(economic)) == row['economic_sha256'],
            'wrong committed journal binding')
    start, end = utc(envelope['not_before']), utc(envelope['expires_at'])
    require(0 < (end - start).total_seconds() <= 86400, 'invalid receipt validity')
    return start, end


def frame(payload, signature, status_only=False):
    require(0 < len(payload) <= DOCUMENT_LIMIT and 0 < len(signature) <= DOCUMENT_LIMIT, 'invalid frame lengths')
    header = STATUS_HEADER if status_only else HEADER
    return header + ('%d\n%d\n' % (len(payload), len(signature))).encode('ascii') + payload + signature


def check_signature(signature, row):
    record = parse_document(signature, DOCUMENT_LIMIT)
    require(set(record) == {'schema_version', 'algorithm', 'key_id', 'signature_b64'} and
            type(record['schema_version']) in INTEGER_TYPES and record['schema_version'] == 1 and
            record['algorithm'] == 'rsa-sha256' and record['key_id'] == row['key_id'] and
            token(record['signature_b64'], r'[A-Za-z0-9+/]+={0,2}'), 'wrong signed frame metadata')
    try:
        raw = base64.b64decode(record['signature_b64'].encode('ascii'))
    except (TypeError, ValueError, binascii.Error):
        raise DispatchError('invalid signature encoding')
    require(bool(raw) and base64.b64encode(raw).decode('ascii') == record['signature_b64'], 'invalid signature encoding')


def positive_identifier(value):
    return type(value) in INTEGER_TYPES and 0 < value <= 9223372036854775807


def check_ack(raw, row, signature):
    require(isinstance(raw, bytes) and raw.endswith(b'\n'), 'acknowledgement must be one complete line')
    ack = parse_document(raw[:-1], ACK_LIMIT - 1)
    sig_hash = digest(signature)
    observation = digest(b'legacy-observation-v1\0' + (row['payload_sha256'] + sig_hash).encode('ascii'))
    expected = dict(effect_key=row['effect_key'], economic_sha256=row['economic_sha256'],
                    payload_sha256=row['payload_sha256'], signature_sha256=sig_hash, observation_key=observation)
    require(set(ack) == set(expected) | {'disposition', 'result_id', 'result_observation_key', 'movement_id', 'receipt_sha256'}
            and all(ack[name] == value for name, value in expected.items()), 'wrong acknowledgement binding')
    state = ack['disposition']
    if state in TERMINAL:
        require(positive_identifier(ack['result_id']) and token(ack['receipt_sha256'], r'[a-f0-9]{64}')
                and token(ack['result_observation_key'], r'[a-f0-9]{64}')
                and (positive_identifier(ack['movement_id']) if state == 'applied' else ack['movement_id'] is None),
                'wrong durable result')
    else:
        require(state in ('stored_pending', 'quarantined') and
                all(ack[name] is None for name in ('result_id', 'result_observation_key', 'movement_id', 'receipt_sha256')),
                'wrong pending result')
    return state


def deliver(store, row, signer, send, now, retry_seconds, attempt_limit):
    try:
        start, end = inspect(row)
    except DispatchError:
        return 'invalid' if store.hold(row, 'invalid') else 'busy'
    if now < start:
        delay = min(86400, max(1, int((start - now).total_seconds())))
        return 'not_yet_current' if store.defer(row, delay) else 'busy'
    signature = row['signature']
    if signature is None:
        if now >= end:
            return 'expired' if store.hold(row, 'expired') else 'busy'
        try:
            signature = signer(row['payload'], row['key_id'])
        except (DispatchError, EnvironmentError):
            signature = None
        if signature is None:
            return 'signer_unavailable' if store.defer(row, retry_seconds) else 'busy'
    try:
        check_signature(signature, row)
    except DispatchError:
        return 'invalid' if store.hold(row, 'invalid') else 'busy'
    if not store.reserve(row, signature, retry_seconds, attempt_limit):
        return 'busy'
    try:
        raw = send(frame(row['payload'], signature, status_only=now >= end))
        state = check_ack(raw, row, signature)
    except DispatchError:
        return 'unknown'
    return state if store.finish(row, row['attempts'] + 1, state, raw) else 'unknown'


def exchange(command, data, seconds, limit):
    with tempfile.TemporaryFile() as request, open(os.devnull, 'wb') as diagnostics:
        request.write(data)
        request.seek(0)
        child = subprocess.Popen(['/usr/bin/timeout', '--kill-after=2s', '%ds' % seconds] + command,
                                 stdin=request, stdout=subprocess.PIPE, stderr=diagnostics, close_fds=True)
        try:
            output = child.stdout.read(limit + 1)
        finally:
            child.stdout.close()
            status = child.wait()
    require(status == 0 and len(output) <= limit, 'delivery subprocess failed')
    return output


def sql_bytes(value):
    return "X'%s'" % binascii.hexlify(value).decode('ascii')


class MysqlStore(object):
    def __init__(self, command):
        # A lost connection cannot continue with a different transaction or
        # different session variables. Options in a client file must not force
        # execution past an SQL error.
        self.command = command + ['--skip-reconnect', '--skip-force']

    def run(self, script, limit=100):
        return exchange(self.command, script.encode('ascii'), 20, limit)

    def pending(self, limit, attempts):
        require(type(limit) in INTEGER_TYPES and 1 <= limit <= 32 and
                type(attempts) in INTEGER_TYPES and 1 <= attempts <= 1000, 'invalid delivery bounds')
        rows = self.run("""
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
SELECT j.id, HEX(j.effect_key), HEX(e.economic_sha256), HEX(j.payload_sha256), HEX(j.key_id),
       IF(OCTET_LENGTH(j.payload) <= 65536, HEX(j.payload), ''),
       CASE WHEN d.signature IS NULL THEN 'NULL'
            WHEN OCTET_LENGTH(d.signature) <= 65536 THEN HEX(d.signature)
            ELSE '' END,
       COALESCE(d.attempts, 0)
FROM paymenter_legacy_journal j
JOIN paymenter_legacy_effects e ON e.effect_key = j.effect_key
LEFT JOIN paymenter_legacy_dispatch d ON d.journal_id = j.id
WHERE d.journal_id IS NULL OR (d.state IN ('queued', 'stored_pending')
  AND d.next_attempt_at <= UTC_TIMESTAMP() AND d.attempts < %d)
ORDER BY COALESCE(d.next_attempt_at, '1000-01-01 00:00:00'), j.id LIMIT %d;
""" % (attempts, limit), limit * (4 * DOCUMENT_LIMIT + 1024))
        for line in rows.splitlines():
            number, effect, economic, payload_hash, key_id, payload, signature, attempted = line.split(b'\t')
            try:
                effect, economic, payload_hash, signing_key = (
                    binascii.unhexlify(field).decode('ascii') for field in (effect, economic, payload_hash, key_id)
                )
            except UnicodeDecodeError:
                effect = economic = payload_hash = signing_key = None
            yield dict(id=int(number), effect_key=effect, economic_sha256=economic,
                       payload_sha256=payload_hash, key_id=signing_key,
                       payload=binascii.unhexlify(payload),
                       signature=None if signature == b'NULL' else binascii.unhexlify(signature), attempts=int(attempted))

    @staticmethod
    def initialize(row):
        return ("INSERT INTO paymenter_legacy_dispatch (journal_id, next_attempt_at) VALUES (%d, UTC_TIMESTAMP()) "
                "ON DUPLICATE KEY UPDATE journal_id=VALUES(journal_id);") % int(row['id'])

    def reserve(self, row, signature, delay, attempt_limit):
        source = int(row['id'])
        signed = sql_bytes(signature)
        output = self.run("""
START TRANSACTION;
%s
UPDATE paymenter_legacy_dispatch
SET attempts=attempts+1, next_attempt_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
    signature=COALESCE(signature, %s)
WHERE journal_id=%d AND attempts=%d AND attempts<%d AND next_attempt_at<=UTC_TIMESTAMP()
  AND state IN ('queued', 'stored_pending') AND (signature IS NULL OR signature=%s);
SET @reserved=ROW_COUNT();
INSERT INTO paymenter_legacy_dispatch_attempts (journal_id, attempt, started_at)
SELECT %d, %d, UTC_TIMESTAMP() FROM DUAL WHERE @reserved=1;
COMMIT;
SELECT @reserved;
""" % (self.initialize(row), int(delay), signed, source, int(row['attempts']), int(attempt_limit),
       signed, source, int(row['attempts']) + 1))
        require(output in (b'0\n', b'1\n'), 'invalid attempt reservation result')
        return output == b'1\n'

    def finish(self, row, attempt, state, acknowledgement):
        require(state in TERMINAL + ('stored_pending', 'quarantined'), 'invalid acknowledgement state')
        source = int(row['id'])
        sequence = int(attempt)
        output = self.run("""
START TRANSACTION;
UPDATE paymenter_legacy_dispatch d JOIN paymenter_legacy_dispatch_attempts a ON a.journal_id=d.journal_id
SET d.state='%s', d.acknowledgement=%s, d.completed_attempt=%d,
    a.finished_at=UTC_TIMESTAMP(), a.acknowledgement_sha256='%s'
WHERE d.journal_id=%d AND d.attempts=%d AND d.completed_attempt<%d
  AND d.state IN ('queued', 'stored_pending') AND a.attempt=%d AND a.finished_at IS NULL;
SET @finished=ROW_COUNT();
COMMIT;
SELECT @finished;
""" % (state, sql_bytes(acknowledgement), sequence, digest(acknowledgement), source, sequence, sequence, sequence))
        require(output in (b'0\n', b'2\n'), 'invalid acknowledgement persistence result')
        return output == b'2\n'

    def hold(self, row, state):
        require(state in ('expired', 'invalid'), 'invalid hold state')
        return self.defer_or_hold(row, "state='%s'" % state)

    def defer(self, row, delay):
        return self.defer_or_hold(row, 'next_attempt_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)' % int(delay))

    def defer_or_hold(self, row, change):
        output = self.run("""
START TRANSACTION;
%s
UPDATE paymenter_legacy_dispatch SET %s
WHERE journal_id=%d AND attempts=%d AND next_attempt_at<=UTC_TIMESTAMP() AND state IN ('queued', 'stored_pending');
SET @saved=ROW_COUNT();
COMMIT;
SELECT @saved;
""" % (self.initialize(row), change, int(row['id']), int(row['attempts'])))
        require(output in (b'0\n', b'1\n'), 'invalid held delivery result')
        return output == b'1\n'

    def unresolved(self, attempt_limit):
        row = self.run("""
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
SELECT COALESCE(SUM(state IN ('queued', 'stored_pending') AND attempts >= %d), 0),
       COALESCE(SUM(state='quarantined'),0), COALESCE(SUM(state='expired'),0), COALESCE(SUM(state='invalid'),0)
FROM paymenter_legacy_dispatch;
""" % int(attempt_limit))
        counts = tuple(map(int, row.strip().split(b'\t')))
        require(len(counts) == 4 and all(count >= 0 for count in counts), 'invalid unresolved delivery counts')
        return dict(zip(('exhausted', 'quarantined', 'expired', 'invalid'), counts))


def owned_file(name, private):
    absolute = os.path.abspath(name)
    info = os.lstat(absolute)
    require(os.path.realpath(absolute) == absolute and stat.S_ISREG(info.st_mode) and
            info.st_uid in (0, os.geteuid()) and not info.st_mode & (0o077 if private else 0o022),
            'unexpected credential or trust file permissions')
    return absolute


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mysql-options-file', required=True)
    parser.add_argument('--database', required=True)
    parser.add_argument('--signing-key', required=True)
    parser.add_argument('--signing-key-id', required=True)
    parser.add_argument('--ssh-key', required=True)
    parser.add_argument('--known-hosts', required=True)
    parser.add_argument('--target', required=True)
    parser.add_argument('--retry-seconds', type=int, required=True)
    parser.add_argument('--max-attempts', type=int, required=True)
    parser.add_argument('--limit', type=int, default=8)
    args = parser.parse_args(argv)
    require(token(args.target, r'[a-zA-Z][a-zA-Z0-9_.-]{0,63}@[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}')
            and token(args.database, r'[a-zA-Z_][a-zA-Z0-9_]{0,63}') and 25 <= args.retry_seconds <= 86400,
            'invalid delivery configuration')
    os.umask(0o077)
    options = owned_file(args.mysql_options_file, True)
    key = owned_file(args.signing_key, True)
    store = MysqlStore(['/usr/bin/mysql', '--defaults-file=' + options, '--protocol=socket', '--batch', '--raw',
                        '--skip-column-names', '--database=' + args.database])
    ssh = ['/usr/bin/ssh', '-F', '/dev/null', '-T', '-oBatchMode=yes', '-oStrictHostKeyChecking=yes',
           '-oGlobalKnownHostsFile=/dev/null', '-oUserKnownHostsFile=' + owned_file(args.known_hosts, False),
           '-oIdentitiesOnly=yes', '-oConnectTimeout=10', '-i', owned_file(args.ssh_key, True),
           args.target, 'receive-legacy-payment']

    def sign(payload, key_id):
        if args.signing_key_id != key_id:
            return None
        signature = exchange(['/usr/bin/openssl', 'dgst', '-sha256', '-sign', key], payload, 10, 8192)
        return canonical(dict(schema_version=1, algorithm='rsa-sha256', key_id=key_id,
                              signature_b64=base64.b64encode(signature).decode('ascii')))

    def send(wire):
        return exchange(ssh, wire, 20, ACK_LIMIT)

    results = {}
    for row in store.pending(args.limit, args.max_attempts):
        state = deliver(store, row, sign, send, datetime.utcnow(), args.retry_seconds, args.max_attempts)
        results[state] = results.get(state, 0) + 1
    unresolved = store.unresolved(args.max_attempts)
    print(json.dumps(dict(outcomes=results, unresolved=unresolved), sort_keys=True))
    return 1 if results.get('unknown') or results.get('signer_unavailable') or any(unresolved.values()) else 0


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv[1:]))
    except (DispatchError, EnvironmentError, TypeError, ValueError):
        sys.stderr.write('Legacy delivery stopped.\n')
        sys.exit(1)
