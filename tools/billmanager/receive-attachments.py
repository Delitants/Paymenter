#!/usr/bin/env python3
"""Receive a scoped tar stream into a private directory; never use extractall."""
import hashlib, json, os, sys, tarfile, tempfile
os.umask(0o077)
manifest = json.load(open(sys.argv[1]))
root = os.path.realpath(sys.argv[2])
os.makedirs(root, mode=0o700, exist_ok=True)
expected = {row['path']: row for row in manifest}
for row in manifest:
    other = expected[row['path']]
    if (row['size'], row['sha256'], row['account']) != (other['size'], other['sha256'], other['account']):
        raise ValueError('Conflicting shared attachment')
seen = set()
archive = tarfile.open(fileobj=sys.stdin.buffer, mode='r|')
for member in archive:
    row = expected.get(member.name)
    if not row or member.name in seen or not member.isfile() or member.size != row['size']:
        raise ValueError('Unexpected attachment member')
    parts = member.name.split('/')
    if parts[0] != str(row['account']) or any(p in ('', '.', '..') for p in parts):
        raise ValueError('Invalid account directory')
    destination = os.path.realpath(os.path.join(root, member.name))
    if not destination.startswith(root + '/' + str(row['account']) + '/'):
        raise ValueError('Attachment escaped account directory')
    os.makedirs(os.path.dirname(destination), mode=0o700, exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=os.path.dirname(destination), prefix='.receive-')
    try:
        digest = hashlib.sha256()
        with os.fdopen(fd, 'wb') as out:
            source = archive.extractfile(member)
            while True:
                chunk = source.read(1024 * 1024)
                if not chunk: break
                digest.update(chunk)
                out.write(chunk)
        if digest.hexdigest() != row['sha256'] or os.path.getsize(temporary) != row['size']:
            raise ValueError('Received attachment checksum mismatch')
        if os.path.exists(destination):
            with open(destination, 'rb') as existing:
                if hashlib.sha256(existing.read()).hexdigest() != row['sha256']:
                    raise ValueError('Existing destination attachment differs')
        else:
            os.link(temporary, destination)
        seen.add(member.name)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)
if seen != set(expected): raise ValueError('Attachment stream incomplete')
print(json.dumps({'verified_files': len(seen), 'attachment_records': len(manifest), 'bytes': sum(row['size'] for row in expected.values())}))
