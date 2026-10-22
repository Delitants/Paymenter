"""Prepared native admission probe. Read-only, no BIND calls or DNS requests."""
import hashlib
import importlib
import json
import os
import pathlib
import platform
import stat
import sys
from .runtime_bundle import SCHEMA, LINKS, safe_name, require


def verify_tree(root, expected_uid=0):
    """Read an already staged tree; never install, fix permissions, or execute it."""
    root = pathlib.Path(root)
    from .custody import RootDirectory
    with RootDirectory(str(root), expected_uid) as custody:
        manifest = json.loads(custody.read('bundle-manifest.json', 4 * 1024 * 1024).raw)
        require(manifest['schema'] == SCHEMA and manifest['native_accepted'] is False and manifest['installation_authorized'] is False)
        require(manifest['native_admission_required'] is True)
        expected = set(manifest['files']) | {'bundle-manifest.json'}
        observed = set()
        for directory, dirs, files in os.walk(root, followlinks=False):
            for leaf in dirs + files:
                path = pathlib.Path(directory) / leaf
                info = path.lstat()
                name = path.relative_to(root).as_posix()
                require(info.st_uid == expected_uid and (stat.S_ISLNK(info.st_mode) or not info.st_mode & 0o022))
                if path.is_symlink() or not stat.S_ISDIR(info.st_mode):
                    observed.add(name)
        require(observed == expected)
        for name, record in manifest['files'].items():
            safe_name(name)
            if name in LINKS:
                path = root / name
                require(record == {'symlink': LINKS[name]} and path.is_symlink() and os.readlink(path) == LINKS[name])
            else:
                image = custody.read(name, 32 * 1024 * 1024)
                require(record == {'sha256': hashlib.sha256(image.raw).hexdigest(), 'mode': image.mode})
        custody.recheck()
    return manifest


def main():
    if len(sys.argv) != 1 or os.geteuid() != 0:
        return 77
    # -I and -B are required before importing this trusted staged module.
    require(sys.flags.isolated and sys.dont_write_bytecode)
    require(platform.system() == 'Linux' and platform.machine() == 'x86_64')
    root = pathlib.Path('/opt/paymenter-bind')
    manifest = verify_tree(root)
    require(sys.prefix == str(root / 'venv') and sys.base_prefix == str(root / 'runtime'))
    require(platform.python_version() == manifest['python_version'])
    required = ['fcntl', 'select', 'socket', 'subprocess', 'signal', 'hashlib', 'json',
                'dns.zone', 'dns.name', 'idna', 'paymenter_bind.cli', 'paymenter_bind.legacy']
    for name in required:
        importlib.import_module(name)
    import dns.zone
    zone = dns.zone.from_text('@ 60 IN SOA ns.example.invalid. hostmaster.example.invalid. 1 60 60 60 60\n'
                              '@ 60 IN NS ns.example.invalid.\nns 60 IN A 192.0.2.1\n', origin='example.invalid.')
    require(zone.get_rdataset('@', 'SOA')[0].serial == 1)
    print(json.dumps({'schema': 'paymenter-bind-runtime-admission-v1', 'imports_verified': True,
                      'package_verified': True, 'bind_native_accepted': False,
                      'installation_authorized': False}, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
