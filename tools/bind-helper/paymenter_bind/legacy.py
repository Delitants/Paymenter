"""Opt-in BIND 9.11.4-P2 proof. Missing fields alone never authorize a write."""
import hashlib
import os
import re
import stat
from pathlib import Path
from .custody import RootDirectory, identity
from .protocol import need

PROFILE = 'bind-9.11.4-p2'
PIN_NAMES = {'named', 'rndc', 'checkconf', 'checkzone', 'compilezone'}
TOKEN = re.compile(r'\s+|//[^\n]*|\#[^\n]*|/\*.*?\*/|"(?:\\.|[^"\\])*"|[{};]|[^\s{};"#]+', re.S)


def parse(text):
    need(type(text) is str and len(text.encode()) <= 524288)
    tokens = []
    end = 0
    for match in TOKEN.finditer(text):
        need(match.start() == end)
        end = match.end()
        value = match.group()
        if value.isspace() or value.startswith(('//', '#', '/*')):
            continue
        need(len(value) <= 16384 and len(tokens) < 65536)
        # BIND names/paths in this profile must not require escape interpretation.
        if value.startswith('"'):
            need('\\' not in value)
            value = value[1:-1]
        tokens.append(value)
    need(end == len(text))
    cursor = 0

    def block(nested=False, depth=0):
        nonlocal cursor
        need(depth <= 32)
        nodes = []
        while cursor < len(tokens):
            if tokens[cursor] == '}':
                need(nested)
                cursor += 1
                return nodes
            head = []
            while cursor < len(tokens) and tokens[cursor] not in '{};':
                head.append(tokens[cursor])
                cursor += 1
            need(bool(head) and cursor < len(tokens))
            delimiter = tokens[cursor]
            cursor += 1
            need(delimiter in '{;')
            children = block(True, depth + 1) if delimiter == '{' else None
            if delimiter == '{':
                need(cursor < len(tokens) and tokens[cursor] == ';')
                cursor += 1
            if head[0].lower() == 'secret':
                need(len(head) == 2 and re.fullmatch(r'\?+', head[1]) is not None)
            nodes.append((head, children))
        need(not nested)
        return nodes

    return block()


def one(nodes, key):
    matches = [node for node in nodes if node[0][0].lower() == key]
    need(len(matches) <= 1)
    return matches[0] if matches else None


def policy(text, binding):
    """Select only the exact explicit IN view/zone; reject ambiguous policy."""
    nodes = parse(text)
    options = one(nodes, 'options')
    options = options[1] if options else []
    views = [node for node in nodes if node[0][0].lower() == 'view' and len(node[0]) in (2, 3) and node[0][1] == binding.view]
    need(len(views) == 1)
    view = views[0]
    need(len(view[0]) == 2 or view[0][2].upper() == 'IN')
    zones = [node for node in view[1] if node[0][0].lower() == 'zone' and len(node[0]) in (2, 3) and node[0][1].lower().rstrip('.') == binding.zone.rstrip('.')]
    need(len(zones) == 1)
    zone = zones[0]
    need(len(zone[0]) == 2 or zone[0][2].upper() == 'IN')
    body = zone[1]
    kind, file = one(body, 'type'), one(body, 'file')
    need(kind is not None and kind[0] == ['type', 'master' if binding.role == 'primary' else 'slave'])
    need(file is not None and len(file[0]) == 2 and file[1] is None and one(body, 'in-view') is None)
    directory = one(options, 'directory')
    need(file[0][1].startswith('/') or (directory is not None and len(directory[0]) == 2))
    location = Path(file[0][1]) if file[0][1].startswith('/') else Path(directory[0][1]) / file[0][1]
    need(str(location) == str(Path(binding.zone_root) / binding.file))
    for key, expected in [('inline-signing', 'no'), ('auto-dnssec', 'off')]:
        # The exact pinned 9.11.4-P2 profile supplies documented defaults only
        # after fresh disk policy AND safe loaded-state evidence agree.
        effective = None
        for context in [options, view[1], body]:
            entry = one(context, key)
            if entry is not None:
                need(entry[1] is None and len(entry[0]) == 2)
                effective = entry[0][1]
        need(effective in (None, expected))
    for key in ['allow-update', 'update-policy', 'allow-update-forwarding']:
        effective = None
        for context in [options, view[1], body]:
            entry = one(context, key)
            if entry is not None:
                effective = entry
        if effective is not None:
            need(effective == ([key], [(['none'], None)]))
    need(all(one(context, 'dnssec-policy') is None for context in [options, view[1], body]))
    return hashlib.sha256(text.encode()).hexdigest()


def executable(path, digest, uid):
    """Pin root-controlled executable aliases/targets; data files stay single-link."""
    need(type(path) is str and path.startswith('/') and '..' not in path.split('/'))
    links = []
    current = Path(path)
    for _ in range(16):
        with RootDirectory(str(current.parent), uid) as parent:
            before = os.stat(current.name, dir_fd=parent.fd, follow_symlinks=False)
            need(before.st_uid == uid)
            if stat.S_ISLNK(before.st_mode):
                target = os.readlink(current.name, dir_fd=parent.fd)
                need('..' not in target.split('/') and '\x00' not in target)
                links.append((str(current), identity(before), target, tuple(parent.chain)))
                current = Path(target) if target.startswith('/') else current.parent / target
                continue
            need(stat.S_ISREG(before.st_mode) and before.st_nlink >= 1 and before.st_mode & 0o100 and not before.st_mode & 0o022 and before.st_size <= 33554432)
            fd = os.open(current.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent.fd)
            try:
                need(identity(os.fstat(fd)) == identity(before))
                hash = hashlib.sha256()
                size = 0
                while True:
                    data = os.read(fd, 65536)
                    if not data:
                        break
                    size += len(data)
                    need(size <= 33554432)
                    hash.update(data)
                need(size == before.st_size and hash.hexdigest() == digest)
                need(identity(os.fstat(fd)) == identity(before) == identity(os.stat(current.name, dir_fd=parent.fd, follow_symlinks=False)))
                parent.recheck()
                target_identity = (str(current), identity(before), tuple(parent.chain))
            finally:
                os.close(fd)
            break
    else:
        need(False)
    for name, stamp, value, chain in links:
        with RootDirectory(str(Path(name).parent), uid) as parent:
            need(tuple(parent.chain) == chain and identity(os.lstat(name)) == stamp and os.readlink(name) == value)
    return tuple(links), target_identity


def loaded_flags(values):
    need(values.get('secure') == 'no' and values.get('dynamic') == 'no')
    need(values.get('inline signing') in (None, 'no') and values.get('key maintenance') in (None, 'none', 'off'))
    # Upstream 9.11.4-P2 emits signed serial for every raw/inline zone, and
    # emits key maintenance for hasraw/allow/maintain even when unsigned.
    need('signed serial' not in values)
    if 'inline signing' not in values:
        need('key maintenance' not in values)
