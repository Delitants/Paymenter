"""Build/inspect an inert Linux runtime candidate; never extract or execute it."""
import argparse
import hashlib
import io
import json
import pathlib
import re
import stat
import struct
import tarfile
import zipfile

SCHEMA = 'paymenter-bind-runtime-candidate-v1'
MAX_FILE = 32 * 1024 * 1024
MAX_TOTAL = 512 * 1024 * 1024
LINKS = {
    'venv/bin/python3': '../../runtime/bin/python3.11',
    'venv/lib/python3.11/site-packages/paymenter_bind': '../../../../source/paymenter_bind',
}


def require(ok):
    if not ok:
        raise ValueError('Runtime candidate verification failed')


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe_name(name):
    require(isinstance(name, str) and name and not name.startswith('/') and '\\' not in name)
    require(all(part not in ('', '.', '..') for part in name.split('/')))
    return name


def elf_metadata(raw):
    """Static ELF/ABI inspection only. This does not prove dynamic relocation."""
    require(len(raw) >= 64 and raw[:7] == b'\x7fELF\x02\x01\x01')
    header = struct.unpack_from('<16sHHIQQQIHHHHHH', raw)
    require(header[2] == 62 and header[1] in (2, 3) and header[3] == 1)
    offset, entry, count = header[5], header[9], header[10]
    require(entry == 56 and 0 < count <= 256 and offset + entry * count <= len(raw))
    segments = [struct.unpack_from('<IIQQQQQQ', raw, offset + i * entry) for i in range(count)]
    require(all(p[2] + p[5] <= len(raw) for p in segments))
    dynamic = [p for p in segments if p[0] == 2]
    require(len(dynamic) <= 1)
    strings, tags = None, []
    if dynamic:
        p = dynamic[0]
        require(p[5] % 16 == 0)
        for pos in range(p[2], p[2] + p[5], 16):
            tag, value = struct.unpack_from('<qQ', raw, pos)
            if tag == 0:
                break
            tags.append((tag, value))
        tables = [v for k, v in tags if k == 5]
        sizes = [v for k, v in tags if k == 10]
        require(len(tables) == len(sizes) == 1)
        mapped = [p[2] + tables[0] - p[3] for p in segments if p[0] == 1 and p[3] <= tables[0] < p[3] + p[5]]
        require(len(mapped) == 1 and sizes[0] <= MAX_FILE and mapped[0] + sizes[0] <= len(raw))
        strings = raw[mapped[0]:mapped[0] + sizes[0]]

    def string(index):
        require(strings is not None and index < len(strings))
        end = strings.find(b'\0', index)
        require(end >= index)
        return strings[index:end].decode('ascii')

    needed = [string(v) for k, v in tags if k == 1]
    require(all(re.fullmatch(r'[A-Za-z0-9_.+-]+', name) for name in needed))
    # No baked-in build prefixes or library search paths are admitted.
    require(not any(k in (15, 29) for k, _ in tags))
    interpreters = [raw[p[2]:p[2] + p[5]].rstrip(b'\0').decode('ascii') for p in segments if p[0] == 3]
    require(interpreters in ([], ['/lib64/ld-linux-x86-64.so.2']))
    # Conservative scan includes all version labels, not just referenced ones.
    versions = sorted({tuple(map(int, v.split(b'.'))) for v in re.findall(rb'GLIBC_([0-9]+(?:\.[0-9]+)+)', raw)})
    require(not versions or max(versions) <= (2, 17))
    return {'architecture': 'x86_64', 'interpreter': interpreters[0] if interpreters else None,
            'needed': sorted(set(needed)), 'glibc_max_label': '.'.join(map(str, max(versions))) if versions else None,
            'relocation_verified': False}


def read_tar(path, skip_static=False):
    files, links, total, seen = {}, {}, 0, set()
    with tarfile.open(path, 'r:gz') as archive:
        for item in archive:
            name = safe_name(item.name.rstrip('/') if item.isdir() else item.name)
            require(name not in seen and len(seen) < 20000)
            seen.add(name)
            if item.isdir():
                continue
            if item.issym():
                require(name in LINKS and item.linkname == LINKS[name])
                links[name] = item.linkname
                continue
            static_archive = skip_static and name.endswith('.a')
            require(item.isfile() and 0 <= item.size <= (64 * 1024 * 1024 if static_archive else MAX_FILE) and item.mode & 0o7022 == 0)
            total += item.size
            require(total <= MAX_TOTAL)
            if static_archive:
                continue
            files[name] = (archive.extractfile(item).read(), item.mode & 0o777)
    return files, links


def verify_archive(path):
    files, links = read_tar(path)
    require('bundle-manifest.json' in files)
    try:
        manifest = json.loads(files.pop('bundle-manifest.json')[0])
        require(manifest['schema'] == SCHEMA and manifest['native_accepted'] is False and manifest['installation_authorized'] is False)
        require(manifest['native_admission_required'] is True and set(manifest['files']) == set(files) | set(links))
        require(links == LINKS)
        for name, record in manifest['files'].items():
            if name in links:
                require(record == {'symlink': links[name]})
            else:
                data, mode = files[name]
                require(record == {'sha256': digest(data), 'mode': mode})
        require('runtime/bin/python3.11' in files and 'source/package-manifest.json' in files)
        observed_elf = {name: elf_metadata(data) for name, (data, _) in files.items() if data.startswith(b'\x7fELF')}
        require(observed_elf == manifest['elf'])
        require(observed_elf['runtime/bin/python3.11']['interpreter'] == '/lib64/ld-linux-x86-64.so.2')
        return manifest
    except (KeyError, TypeError, json.JSONDecodeError) as error:
        raise ValueError('Runtime candidate verification failed') from error


def installation_root(value):
    """Require an unambiguous Linux installation path, safe in pyvenv.cfg."""
    require(type(value) is str and re.fullmatch(r'(?:/[A-Za-z0-9._-]+)+', value) is not None)
    require(all(part not in ('.', '..') for part in value.split('/')[1:]))
    return pathlib.Path(value)


def build_bundle(source, expected_sha256, source_prefix, wheels, output, helper_root,
                 install_root='/opt/paymenter-bind'):
    install_root = installation_root(install_root)
    source, output, helper_root = pathlib.Path(source), pathlib.Path(output), pathlib.Path(helper_root)
    require(re.fullmatch(r'[a-f0-9]{64}', expected_sha256) is not None and source.stat().st_size <= MAX_TOTAL)
    require(digest(source.read_bytes()) == expected_sha256 and not output.exists())
    safe_name(source_prefix)
    original, links = read_tar(source, skip_static=True)
    require(not links)
    files, elf = {}, {}
    for name, (data, mode) in original.items():
        require(name.startswith(source_prefix + '/'))
        relative = name[len(source_prefix) + 1:]
        parts = relative.split('/')
        if parts[0] not in ('bin', 'lib', 'include'):
            continue
        if parts[0] == 'include' and relative != 'include/python3.11/patchlevel.h':
            continue
        if relative.startswith('lib/pkgconfig/') or relative.startswith('lib/python3.11/config-'):
            continue
        if '__pycache__' in parts or relative.endswith(('.pyc', '.pyo')):
            continue
        if parts[0] == 'bin' and relative != 'bin/python3.11':
            continue
        if relative.startswith('lib/python3.11/'):
            sub = relative[len('lib/python3.11/'):]
            if sub.split('/')[0] in ('test', 'idlelib', 'tkinter', 'turtledemo', 'ensurepip', 'site-packages'):
                continue
            require(sub not in ('sitecustomize.py', 'usercustomize.py') and not sub.endswith('.pth'))
        target = 'runtime/' + relative
        if data.startswith(b'\x7fELF'):
            elf[target] = elf_metadata(data)
        files[target] = (data, 0o755 if mode & 0o111 else 0o644)
    require(all('runtime/' + name in files for name in ['bin/python3.11', 'lib/python3.11/os.py', 'lib/python3.11/encodings/__init__.py', 'include/python3.11/patchlevel.h']))
    require(elf['runtime/bin/python3.11']['interpreter'] == '/lib64/ld-linux-x86-64.so.2')
    header = files['runtime/include/python3.11/patchlevel.h'][0]
    match = re.search(rb'^#define PY_VERSION\s+"(3\.11\.[0-9]+)"', header, re.M)
    require(match is not None)
    version = match.group(1).decode()
    lock = (helper_root / 'requirements.lock').read_text().splitlines()
    require(len(lock) == len(wheels) == 2)
    dependencies = {}
    for line in lock:
        match = re.fullmatch(r'(dnspython|idna)==([0-9.]+) --hash=sha256:([a-f0-9]{64})', line)
        require(match is not None)
        name, wheel_version, sha = match.groups()
        matches = [pathlib.Path(w) for w in wheels if pathlib.Path(w).name == f'{name}-{wheel_version}-py3-none-any.whl']
        require(len(matches) == 1 and matches[0].stat().st_size <= MAX_FILE)
        data = matches[0].read_bytes()
        require(digest(data) == sha)
        dependencies[name] = {'version': wheel_version, 'wheel_sha256': sha}
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            seen, total = set(), 0
            for item in archive.infolist():
                member = safe_name(item.filename.rstrip('/') if item.is_dir() else item.filename)
                require(member not in seen and len(seen) < 20000)
                seen.add(member)
                require(not stat.S_ISLNK(item.external_attr >> 16) and 0 <= item.file_size <= MAX_FILE)
                total += item.file_size
                require(total <= MAX_TOTAL)
                if item.is_dir():
                    continue
                require(not member.endswith('.pth') and '.data' not in member.split('/') and '/' in member)
                target = 'venv/lib/python3.11/site-packages/' + member
                require(target not in files)
                files[target] = (archive.read(item), 0o644)
    from .qualify import qualify
    qualify(helper_root)
    package = json.loads((helper_root / 'package-manifest.json').read_text())
    for name in list(package['files']) + ['package-manifest.json']:
        files['source/' + name] = ((helper_root / name).read_bytes(), 0o644)
    files['venv/pyvenv.cfg'] = (f'home = {install_root}/runtime/bin\ninclude-system-site-packages = false\nversion = {version}\n'.encode(), 0o644)
    manifest = {'schema': SCHEMA, 'native_accepted': False, 'installation_authorized': False,
                'native_admission_required': True, 'python_version': version,
                'source_archive_sha256': expected_sha256, 'dependencies': dependencies, 'elf': elf,
                'files': {name: {'sha256': digest(data), 'mode': mode} for name, (data, mode) in files.items()}}
    manifest['files'].update({name: {'symlink': value} for name, value in LINKS.items()})
    files['bundle-manifest.json'] = (json.dumps(manifest, sort_keys=True, indent=2).encode() + b'\n', 0o644)
    require(sum(len(data) for data, _ in files.values()) <= MAX_TOTAL)
    # Exclusive output; a failed build is retained for diagnosis, never installed.
    with output.open('xb') as handle, tarfile.open(fileobj=handle, mode='w:gz') as archive:
        for name in sorted(files):
            data, mode = files[name]
            item = tarfile.TarInfo(name); item.size = len(data); item.mode = mode
            archive.addfile(item, io.BytesIO(data))
        for name, value in LINKS.items():
            item = tarfile.TarInfo(name); item.type = tarfile.SYMTYPE; item.linkname = value; item.mode = 0o777
            archive.addfile(item)
    return verify_archive(output)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=pathlib.Path)
    args = parser.parse_args()
    result = verify_archive(args.archive)
    print(json.dumps({key: result[key] for key in ['schema', 'python_version', 'native_accepted', 'installation_authorized']}))
