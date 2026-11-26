"""Verify a retained Authorize.net notification without assigning financial status."""

from datetime import datetime
from hashlib import sha512
import hmac
import json
import re

from custody import CustodyError, decode_frame, require
from dispatch import token


BODY_LIMIT = 65536
GUID = r'[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}'
PAYMENT_EVENTS = tuple('net.authorize.payment.' + name for name in (
    'authorization.created', 'authcapture.created', 'capture.created', 'priorAuthCapture.created',
    'refund.created', 'void.created', 'fraud.approved', 'fraud.declined', 'fraud.held',
))


def check_signature(headers, body, key):
    require(isinstance(key, bytes) and re.match(br'[a-fA-F0-9]{128}\Z', key), 'invalid Authorize.net key')
    require(0 < len(body) <= BODY_LIMIT and headers.endswith(b'\r\n\r\n'), 'invalid Authorize.net request framing')
    content_types = []
    lengths = []
    signatures = []
    for line in headers[:-4].split(b'\r\n'):
        name, separator, value = line.partition(b':')
        require(separator and re.match(br'[a-zA-Z0-9-]+\Z', name) and not re.search(br'[\r\n\x00]', value),
                'invalid Authorize.net header')
        name = name.lower()
        value = value.strip(b' \t')
        require(name not in (b'transfer-encoding', b'content-encoding'), 'expected an original buffered notification')
        if name == b'content-type':
            content_types.append(value.split(b';', 1)[0].strip().lower())
        elif name == b'content-length':
            lengths.append(value)
        elif name == b'x-anet-signature':
            signatures.append(value)
    require(content_types == [b'application/json'] and lengths == [str(len(body)).encode('ascii')],
            'invalid Authorize.net content framing')
    require(len(signatures) == 1, 'ambiguous Authorize.net signature')
    signature = re.match(br'sha512=([a-fA-F0-9]{128})\Z', signatures[0], re.I)
    expected = hmac.new(key, body, sha512).hexdigest().encode('ascii')
    require(signature and hmac.compare_digest(expected, signature.group(1).lower()),
            'invalid Authorize.net signature')


def unique_fields(pairs):
    result = dict(pairs)
    require(len(result) == len(pairs), 'duplicate Authorize.net notification field')
    return result


def reject_constant(unused):
    raise CustodyError('non-finite Authorize.net notification value')


def notification(frame, target, webhook_id, signature_key):
    require(isinstance(target, bytes) and target.startswith(b'/') and token(webhook_id, GUID),
            'an accepted Authorize.net endpoint is required')
    method, route, headers, body = decode_frame(frame)
    require(method == b'POST' and route == target, 'Authorize.net notification route differs')
    check_signature(headers, body, signature_key)
    try:
        event = json.loads(body.decode('utf-8'), object_pairs_hook=unique_fields, parse_constant=reject_constant)
    except (UnicodeError, ValueError, RuntimeError):
        raise CustodyError('invalid Authorize.net notification JSON')
    require(isinstance(event, dict), 'invalid Authorize.net notification')
    subscription = event.get('webhookId')
    notification_id = event.get('notificationId')
    event_type = event.get('eventType')
    event_date = event.get('eventDate')
    payload = event.get('payload')
    require(token(subscription, GUID) and subscription.lower() == webhook_id.lower()
            and token(notification_id, GUID) and event_type in PAYMENT_EVENTS and isinstance(payload, dict)
            and payload.get('entityName') == 'transaction', 'notification is outside the original Authorize.net webhook')
    require(token(event_date, r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,7})?Z?'),
            'invalid Authorize.net notification time')
    try:
        datetime.strptime(event_date[:19], '%Y-%m-%dT%H:%M:%S')
    except ValueError:
        raise CustodyError('invalid Authorize.net notification time')
    reference = payload.get('merchantReferenceId')
    transaction = payload.get('id')
    require(token(transaction, r'[1-9][0-9]{0,19}') and
            (reference is None or token(reference, r'[\x20-\x7e]{0,20}')),
            'invalid Authorize.net transaction reference')
    return dict(merchant_reference=reference or None, provider_transaction=transaction,
                notification_id=notification_id.lower(), webhook_id=subscription.lower(),
                event_type=event_type, event_date=event_date)


def authenticate(store, sequence, target, webhook_id, signature_key):
    receipt, frame = store.read(sequence)
    require(receipt['provider'] == 'authorizenet', 'retained notification belongs to another provider')
    verified = notification(frame, target, webhook_id, signature_key)
    verified.update(sequence=receipt['sequence'], receipt_sha256=receipt['receipt_sha256'],
                    raw_sha256=receipt['raw_sha256'], financially_processed=False)
    return verified
