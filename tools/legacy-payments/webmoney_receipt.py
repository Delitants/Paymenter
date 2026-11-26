"""Authenticate a retained live WebMoney result against an accepted source payment."""

from datetime import datetime
from decimal import Decimal
from hashlib import sha256
import hmac
import re

try:
    from urllib.parse import unquote_to_bytes as unquote_bytes
except ImportError:
    from urllib import unquote as unquote_bytes

from custody import decode_frame, require


SIGNED_FIELDS = (
    b'LMI_PAYEE_PURSE', b'LMI_PAYMENT_AMOUNT', b'LMI_PAYMENT_NO', b'LMI_MODE', b'LMI_SYS_INVS_NO',
    b'LMI_SYS_TRANS_NO', b'LMI_SYS_TRANS_DATE', b'LMI_PAYER_PURSE', b'LMI_PAYER_WM',
)
IDENTIFIER = br'[1-9][0-9]{0,19}\Z'
AMOUNT = br'[0-9]{1,16}(?:\.[0-9]{1,4})?\Z'
CURRENCIES = {b'Z': 'USD', b'E': 'EUR'}


def matches(pattern, value):
    return isinstance(value, bytes) and re.match(pattern, value) is not None


def signature_matches(fields, secret):
    """Check the concatenated-field SHA-256 format against the local merchant key."""
    signature = fields.get(b'LMI_HASH')
    if (not isinstance(secret, bytes) or not 0 < len(secret) <= 4096 or
            not matches(br'[A-Fa-f0-9]{64}\Z', signature) or
            not all(isinstance(fields.get(name), bytes) and fields[name] for name in SIGNED_FIELDS)):
        return False
    signed = b''.join(fields[name] for name in SIGNED_FIELDS[:7]) + secret
    signed += b''.join(fields[name] for name in SIGNED_FIELDS[7:])
    return hmac.compare_digest(sha256(signed).hexdigest().encode('ascii'), signature.lower())


def form_fields(headers, payload):
    require(headers.endswith(b'\r\n\r\n') and b'\r\n\r\n' not in headers[:-4], 'WebMoney headers are incomplete')
    content_types = []
    for line in headers[:-4].split(b'\r\n'):
        name, separator, value = line.partition(b':')
        require(separator and matches(br'[A-Za-z0-9-]+\Z', name) and not re.search(br'[\x00\r\n]', value),
                'invalid original header line')
        if name.lower() == b'content-type':
            content_types.append(value.split(b';', 1)[0].strip().lower())
    require(content_types == [b'application/x-www-form-urlencoded'], 'unexpected WebMoney content type')
    parts = payload.split(b'&', 256)
    require(0 < len(parts) <= 256, 'too many WebMoney form fields')
    values = {}
    for part in parts:
        name, separator, raw = part.partition(b'=')
        require(separator and not re.search(br'%(?![A-Fa-f0-9]{2})', part), 'malformed WebMoney form field')
        key = unquote_bytes(name.replace(b'+', b' '))
        require(matches(br'[A-Za-z0-9_.-]{1,80}\Z', key) and key not in values, 'invalid or duplicate WebMoney field')
        values[key] = unquote_bytes(raw.replace(b'+', b' '))
    return values


def authenticate(store, sequence, expected, secret):
    """Use a trusted source payment binding supplied independently of the callback."""
    require(isinstance(expected, dict) and set(expected) ==
            {'target', 'purse', 'payment', 'amount', 'source_account', 'source_subaccount'} and
            isinstance(expected['target'], bytes) and expected['target'].startswith(b'/') and
            matches(br'[ZE][0-9]{12}\Z', expected['purse']) and matches(AMOUNT, expected['amount']) and
            all(matches(IDENTIFIER, expected[name]) for name in ('payment', 'source_account', 'source_subaccount')),
            'an accepted source payment binding is required')
    receipt, original = store.read(sequence)
    method, target, headers, payload = decode_frame(original)
    require(receipt['provider'] == 'webmoney' and method == b'POST' and target == expected['target'],
            'WebMoney result route differs from the accepted binding')
    fields = form_fields(headers, payload)
    amount = fields.get(b'LMI_PAYMENT_AMOUNT')
    require(b'LMI_PREREQUEST' not in fields and b'LMI_HOLD' not in fields and not fields.get(b'LMI_HASH2') and
            fields.get(b'LMI_MODE') == b'0' and fields.get(b'LMI_PAYEE_PURSE') == expected['purse'] and
            fields.get(b'LMI_PAYMENT_NO') == expected['payment'] and matches(AMOUNT, amount) and
            matches(br'[A-Z][0-9]{12}\Z', fields.get(b'LMI_PAYER_PURSE')) and
            matches(br'[0-9]{12}\Z', fields.get(b'LMI_PAYER_WM')) and
            all(matches(IDENTIFIER, fields.get(name)) for name in (b'LMI_SYS_INVS_NO', b'LMI_SYS_TRANS_NO')),
            'WebMoney payment differs from the admitted live result')
    date = fields.get(b'LMI_SYS_TRANS_DATE')
    require(matches(br'[0-9]{8} [0-9]{2}:[0-9]{2}:[0-9]{2}\Z', date), 'invalid WebMoney result time')
    try:
        datetime.strptime(date.decode('ascii'), '%Y%m%d %H:%M:%S')
    except ValueError:
        require(False, 'invalid WebMoney result time')
    decimal_amount = Decimal(amount.decode('ascii'))
    require(decimal_amount > 0 and decimal_amount == Decimal(expected['amount'].decode('ascii')) and
            signature_matches(fields, secret), 'WebMoney amount or signature failed verification')
    return dict(sequence=receipt['sequence'], receipt_sha256=receipt['receipt_sha256'], raw_sha256=receipt['raw_sha256'],
                source_payment=expected['payment'].decode('ascii'), source_account=expected['source_account'].decode('ascii'),
                source_subaccount=expected['source_subaccount'].decode('ascii'),
                provider_reference=fields[b'LMI_SYS_TRANS_NO'].decode('ascii'), provider_amount=amount.decode('ascii'),
                provider_currency=CURRENCIES[expected['purse'][:1]], delivery_acknowledged=False, financially_processed=False)
