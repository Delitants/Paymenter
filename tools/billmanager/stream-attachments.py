#!/usr/bin/env python
"""Stream only manifest-authorized, hash-verified files from an account storage root."""
from __future__ import print_function
import hashlib, json, os, sys, tarfile
root = os.path.realpath(sys.argv[1])
manifest = json.load(sys.stdin)
verified = []
seen = {}
for row in manifest:
    relative = row['path']
    account = str(row['account'])
    parts = relative.split('/')
    disk_relative = relative.encode('utf-8') if sys.version_info[0] == 2 else relative
    path = os.path.realpath(os.path.join(root, disk_relative))
    if not account.isdigit() or parts[0] != account or '..' in parts or '.' in parts or not path.startswith(root + '/' + account + '/') or not os.path.isfile(path):
        raise ValueError('Invalid account attachment path')
    signature = (row['size'], row['sha256'])
    if relative in seen:
        if seen[relative] != signature: raise ValueError('Conflicting shared attachment')
        continue
    seen[relative] = signature
    with open(path, 'rb') as src:
        digest = hashlib.sha256()
        while True:
            chunk = src.read(1024 * 1024)
            if not chunk: break
            digest.update(chunk)
    if digest.hexdigest() != row['sha256'] or os.path.getsize(path) != row['size']:
        raise ValueError('Attachment checksum changed')
    verified.append((path, relative))
output = getattr(sys.stdout, 'buffer', sys.stdout)
archive = tarfile.open(fileobj=output, mode='w|', encoding='utf-8', format=tarfile.PAX_FORMAT)
for path, relative in verified:
    archive.add(path, arcname=relative, recursive=False)
archive.close()
