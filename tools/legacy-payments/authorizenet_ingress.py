"""Retain the original Authorize.net notification before native processing."""

import argparse
import os
import sys

from authorizenet_receipt import GUID, notification
from custody import Custody, CustodyError, require
from dispatch import DispatchError, owned_file, token
from webmoney_ingress import UNAVAILABLE, paymenter_reference, read_request, redirect


NATIVE = redirect(b'@paymenter_legacy_authorizenet_native')
PAYMENTER = redirect(b'@paymenter_authorizenet_target')


def serve(incoming, outgoing, path, webhook_id, signature_key, custody, forward_paymenter=False):
    response = UNAVAILABLE
    disposition = 'request_refused'
    try:
        frame, _ = read_request(incoming, path)
        try:
            event = notification(frame, path, webhook_id, signature_key)
        except CustodyError:
            # The original endpoint is shared. Its own validation still
            # decides how to handle requests outside this subscription.
            disposition = 'native'
            response = NATIVE
        else:
            disposition = 'custody_unavailable'
            custody.retain('authorizenet', frame)
            reference = (event['merchant_reference'] or '').encode('ascii')
            if (forward_paymenter and event['event_type'] == 'net.authorize.payment.authcapture.created'
                    and reference.startswith(b'PMT-') and paymenter_reference(reference[4:])):
                disposition = 'retained_paymenter'
                response = PAYMENTER
            else:
                disposition = 'retained_native'
                response = NATIVE
    except (CustodyError, DispatchError, EnvironmentError):
        pass
    outgoing.write(response)
    outgoing.flush()
    return disposition


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--path', required=True)
    parser.add_argument('--webhook-id', required=True)
    parser.add_argument('--signature-key-file', required=True)
    parser.add_argument('--root', required=True)
    parser.add_argument('--max-records', type=int, required=True)
    parser.add_argument('--max-bytes', type=int, required=True)
    parser.add_argument('--reserve-bytes', type=int, required=True)
    parser.add_argument('--forward-paymenter', action='store_true')
    args = parser.parse_args(argv)
    require(token(args.path, r'/[A-Za-z0-9_./-]{1,1024}') and
            not any(piece in ('', '.', '..') for piece in args.path.split('/')[1:]) and
            token(args.webhook_id, GUID), 'invalid Authorize.net ingress configuration')
    os.umask(0o077)
    key_path = owned_file(args.signature_key_file, True)
    with os.fdopen(os.open(key_path, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        raw = stream.read(130)
    require(token(raw.decode('ascii'), r'[a-fA-F0-9]{128}\n?'), 'invalid Authorize.net signature key')
    key = raw[:128]
    custody = Custody(args.root, args.max_records, args.max_bytes, args.reserve_bytes)
    decision = serve(getattr(sys.stdin, 'buffer', sys.stdin), getattr(sys.stdout, 'buffer', sys.stdout),
                     args.path.encode('ascii'), args.webhook_id, key, custody, args.forward_paymenter)
    if decision == 'custody_unavailable':
        sys.stderr.write('Authorize.net callback custody unavailable.\n')


if __name__ == '__main__':
    try:
        main(sys.argv[1:])
    except (CustodyError, DispatchError, EnvironmentError, UnicodeError):
        sys.stderr.write('Authorize.net callback ingress stopped.\n')
        sys.exit(1)
