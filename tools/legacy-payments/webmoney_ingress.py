"""Apply the BILLmanager cutoff to one nginx WebMoney callback connection."""

import argparse
import os
import re
import sys

from custody import Custody, CustodyError, PART_LIMITS, VERSION, decode_frame, require
from dispatch import DispatchError, MysqlStore, owned_file, token
from webmoney_receipt import IDENTIFIER, form_fields, matches


NATIVE = (
    b'HTTP/1.1 200 OK\r\n'
    b'X-Accel-Redirect: @paymenter_legacy_webmoney_native\r\n'
    b'Content-Length: 0\r\nConnection: close\r\n\r\n'
)
UNAVAILABLE = (
    b'HTTP/1.1 503 Service Unavailable\r\n'
    b'Content-Length: 0\r\nConnection: close\r\n\r\n'
)
MAX_PAYMENT_ID = 2147483647


def read_request(stream, path):
    line = stream.readline(16 + PART_LIMITS[1] + 13)
    require(line.endswith(b'\r\n'), 'unexpected callback request line')
    request = line[:-2].split(b' ')
    require(len(request) == 3, 'unexpected callback request fields')
    method, target, protocol = request
    require(protocol in (b'HTTP/1.0', b'HTTP/1.1') and target.partition(b'?')[0] == path,
            'unexpected callback route')

    lines = []
    available = PART_LIMITS[2]
    content_length = None
    while True:
        header = stream.readline(available + 1)
        require(len(header) <= available and header.endswith(b'\r\n'), 'callback headers are incomplete')
        available -= len(header)
        lines.append(header)
        if header == b'\r\n':
            break
        name, separator, raw = header[:-2].partition(b':')
        require(separator and matches(br'[A-Za-z0-9-]+\Z', name) and not re.search(br'[\x00\r\n]', raw),
                'unexpected callback header')
        name = name.lower()
        require(name != b'transfer-encoding', 'callback proxy must provide a buffered body')
        if name == b'content-length':
            value = raw.strip(b' \t')
            require(content_length is None and matches(br'(0|[1-9][0-9]{0,6})\Z', value),
                    'callback length is ambiguous')
            content_length = int(value)

    size = content_length or 0
    require(size <= PART_LIMITS[3], 'callback body exceeds the limit')
    body = stream.read(size)
    require(len(body) == size, 'callback body is incomplete')
    parts = (method, target, b''.join(lines), body)
    lengths = b''.join(b'%d\n' % len(part) for part in parts)
    frame = VERSION + b'\n' + lengths + b''.join(parts)
    decode_frame(frame)
    return frame, parts


def source_route(store, payment):
    require(0 <= payment <= MAX_PAYMENT_ID, 'callback payment identifier is invalid')
    result = store.run("""
SELECT CASE
         WHEN state.armed = 0 THEN 'native'
         WHEN state.armed <> 1 OR p.id IS NULL OR wallet.account IS NULL
           OR m.module IS NULL OR m.module NOT IN ('pmwebmoney', 'pmwebmoneyas')
           OR (p.billorder IS NOT NULL AND (order_wallet.account IS NULL
               OR order_wallet.account <> wallet.account)) THEN 'unknown'
         WHEN migrated.id IS NOT NULL THEN 'retired'
         ELSE 'native'
       END
FROM migration_route_state state
LEFT JOIN payment p ON p.id = %d
LEFT JOIN paymethod m ON m.id = p.paymethod
LEFT JOIN subaccount wallet ON wallet.id = p.subaccount
LEFT JOIN billorder parent_order ON parent_order.id = p.billorder
LEFT JOIN subaccount order_wallet ON order_wallet.id = parent_order.subaccount
LEFT JOIN migration_route_accounts migrated ON migrated.id = wallet.account
WHERE state.id = 1 LIMIT 1;
""" % payment, 16)
    require(result in (b'native\n', b'retired\n', b'unknown\n'), 'source cutoff status is unavailable')
    return result[:-1].decode('ascii')


def serve(incoming, outgoing, path, lookup, custody):
    response = UNAVAILABLE
    disposition = 'request_refused'
    try:
        frame, (method, _, headers, body) = read_request(incoming, path)
        payment = 0
        if method == b'POST' and body:
            value = form_fields(headers, body).get(b'LMI_PAYMENT_NO')
            if matches(IDENTIFIER, value) and int(value) <= MAX_PAYMENT_ID:
                payment = int(value)
        disposition = 'lookup_unavailable'
        route = lookup(payment)
        disposition = 'unclassified'
        if route == 'native':
            response = NATIVE
            disposition = 'native'
        elif route == 'retired' and payment:
            disposition = 'custody_unavailable'
            custody.retain('webmoney', frame)
            disposition = 'retained'
    except (CustodyError, DispatchError, EnvironmentError):
        pass
    outgoing.write(response)
    outgoing.flush()
    return disposition


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--path', required=True)
    parser.add_argument('--mysql-options-file', required=True)
    parser.add_argument('--database', required=True)
    parser.add_argument('--root', required=True)
    parser.add_argument('--max-records', type=int, required=True)
    parser.add_argument('--max-bytes', type=int, required=True)
    parser.add_argument('--reserve-bytes', type=int, required=True)
    args = parser.parse_args(argv)
    require(token(args.path, r'/[A-Za-z0-9_./-]{1,1024}') and
            not any(piece in ('', '.', '..') for piece in args.path.split('/')[1:]) and
            token(args.database, r'[A-Za-z_][A-Za-z0-9_]{0,63}'), 'invalid ingress configuration')
    os.umask(0o077)
    options = owned_file(args.mysql_options_file, True)
    store = MysqlStore(['/usr/bin/mysql', '--defaults-file=' + options, '--protocol=socket', '--batch',
                        '--raw', '--skip-column-names', '--database=' + args.database])
    custody = Custody(args.root, args.max_records, args.max_bytes, args.reserve_bytes)
    result = serve(getattr(sys.stdin, 'buffer', sys.stdin), getattr(sys.stdout, 'buffer', sys.stdout),
                   args.path.encode('ascii'), lambda payment: source_route(store, payment), custody)
    if result in ('lookup_unavailable', 'custody_unavailable'):
        sys.stderr.write('Legacy callback ingress: %s.\n' % result)


if __name__ == '__main__':
    try:
        main(sys.argv[1:])
    except (CustodyError, DispatchError, EnvironmentError):
        sys.stderr.write('Legacy callback ingress stopped.\n')
        sys.exit(1)
