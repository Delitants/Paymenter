"""Audit a local source package. This module never installs or contacts a host."""
import hashlib
import json
import pathlib
import re

MODULES = {'__init__', 'protocol', 'config', 'zone', 'custody', 'journal', 'commands',
           'operations', 'lifecycle', 'convergence', 'cli', 'qualify'}


def require(condition):
    if not condition:
        raise ValueError('Package qualification failed')


def qualify(root):
    root = pathlib.Path(root)
    try:
        manifest = json.loads((root / 'package-manifest.json').read_text())
        require(manifest['schema'] == 'paymenter-bind-package-v1' and manifest['version'] == '0.1.0')
        required = {'paymenter_bind/' + module + '.py' for module in MODULES}
        required |= {'requirements.lock', 'pyproject.toml', 'install/ssh-dispatch',
                     'install/paymenter-bind-helper', 'install/sudoers.example',
                     'install/authorized_keys.example', 'install/server-config.example.json',
                     'tests/native-acceptance.example.json'}
        require(set(manifest['files']) == required)
        for name, digest in manifest['files'].items():
            path = root / name
            require(path.is_file() and not path.is_symlink())
            require(hashlib.sha256(path.read_bytes()).hexdigest() == digest)
        lock = (root / 'requirements.lock').read_text().splitlines()
        require(len(lock) == 2 and all(re.fullmatch(r'(dnspython|idna)==[0-9.]+ --hash=sha256:[a-f0-9]{64}', line) for line in lock))
        sudo = '\n'.join(line for line in (root / 'install/sudoers.example').read_text().splitlines() if line and not line.startswith('#'))
        require(sudo == 'paymenter-dns ALL=(root) NOPASSWD: /usr/local/libexec/paymenter-bind-helper ""')
        key = (root / 'install/authorized_keys.example').read_text()
        require('restrict,from="PAYMENTER_SOURCE_ADDRESS",command="/usr/local/libexec/paymenter-bind-ssh-dispatch" ssh-ed25519 REPLACE_WITH_DEDICATED_PUBLIC_KEY' in key)
        dispatch = (root / 'install/ssh-dispatch').read_text()
        require('[ "$#" -eq 0 ]' in dispatch and "= 'paymenter-bind-v1'" in dispatch and 'exec /usr/bin/sudo -n -- /usr/local/libexec/paymenter-bind-helper' in dispatch)
        config = json.loads((root / 'install/server-config.example.json').read_text())
        require(config['bindings'] == {} and 'private_key' not in config and 'password' not in config)
        native = json.loads((root / 'tests/native-acceptance.example.json').read_text())
        require(native['schema'] == 'paymenter-bind-native-acceptance-v1' and native['installation_authorized'] is False and native['native_accepted'] is False)
        require(native['targets'] == {'primary': None, 'secondary': None} and native['host_key_fingerprints'] == {'primary': None, 'secondary': None})
        require(all(native[key] is None for key in ['binding_id', 'disposable_zone', 'runtime_and_command_inventory', 'protected_preimages_and_serials', 'owned_installation_paths_and_key_fingerprints', 'rollback_inventory', 'reviewed_execution_authority']))
        return dict(version=manifest['version'], native_accepted=False, installation_authorized=False, files=len(required))
    except (OSError, KeyError, TypeError, json.JSONDecodeError) as error:
        raise ValueError('Package qualification failed') from error


if __name__ == '__main__':
    print(json.dumps(qualify(pathlib.Path(__file__).resolve().parents[1]), sort_keys=True))
