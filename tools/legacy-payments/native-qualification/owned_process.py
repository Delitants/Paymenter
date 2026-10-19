"""Bounded direct-child transport, compatible with source Python 2.7.

This is an execution substrate, not an issuer or an operator entry point. The
trusted single-threaded caller must pin executable/arguments/environment and
retain this result durably. No other code may reap its child. Only that unreaped
direct child can be signalled; process groups and descendants are never killed.
Non-Linux runs exercise pipes/ownership but cannot qualify source boot/start.
"""
from __future__ import division
import errno
import fcntl
import math
import os
import resource
import select
import signal
import subprocess
import sys
import time
try:
    _strings = (basestring,)
    _integers = (int, long)
except NameError:
    _strings = (str,)
    _integers = (int,)


def _clock():
    # POSIX times elapsed real time uses a fixed point, not the adjustable clock.
    return time.monotonic() if hasattr(time, 'monotonic') else os.times()[4]


def _kernel_identity(pid):
    if not sys.platform.startswith('linux'):
        return None
    with open('/proc/sys/kernel/random/boot_id', 'rb') as f:
        boot = f.read(128).strip().decode('ascii')
    with open('/proc/%d/stat' % pid, 'rb') as f:
        raw = f.read(8192).decode('ascii')
    fields = raw[raw.rfind(')')+2:].split()
    if raw.split(' ', 1)[0] != str(pid) or len(fields) < 20:
        raise ValueError('child-identity')
    return {'boot': boot, 'start': int(fields[19]), 'ppid': int(fields[1]),
            'pgrp': int(fields[2]), 'session': int(fields[3])}


def _same_child(child, identity, owner):
    if os.getpid() != owner or child.returncode is not None or child.pid != identity['pid']:
        return False
    try:
        actual = _kernel_identity(child.pid)
        return actual == identity['kernel'] and (actual is None or actual['ppid'] == owner)
    except (OSError, ValueError):
        return False


def supervise_owned(argv, input_bytes, seconds=5, stdout_limit=16384, stderr_limit=8192, env=None):
    """Return original bounded bytes, EOF/exit and direct-child retirement facts.

    No result is a commit/acceptance token. Failure is terminal and never retried.
    env is trusted launcher data, never an HTTP/profile-derived override.
    """
    return _supervise_owned(argv, input_bytes, seconds, stdout_limit, stderr_limit, env, 30)


def supervise_billmanager_startup(authority_expires, env=None):
    """One fixed actionlist getter, bounded by 120 seconds and existing expiry.

    The trusted caller supplies the absolute expiry from its already admitted
    authority, never a new lease derived from the current time. This function
    neither issues authority nor verifies its provenance. The caller must still
    pin the executable, arguments and environment and preserve the original
    result. There is no retry, installation or manager-exit action here.
    """
    remaining = authority_expires-time.time() if type(authority_expires) in _integers else 0
    if not 0 < remaining <= 600:
        raise ValueError('startup-authority')
    return _supervise_owned(
        ['/usr/local/mgr5/sbin/mgrctl', '-m', 'billmgr', '-o', 'xml', 'actionlist'],
        b'', min(120, remaining), 196608, 8192, env, 120, authority_expires)


def _supervise_owned(argv, input_bytes, seconds, stdout_limit, stderr_limit, env,
                     maximum_seconds, authority_expires=None):
    if (type(argv) is not list or not argv or not all(isinstance(x, _strings) and '\0' not in x for x in argv)
            or not os.path.isabs(argv[0]) or type(input_bytes) is not bytes or len(input_bytes) > 65536
            or type(seconds) not in (int, float) or not 0 < seconds <= maximum_seconds
            or any(type(n) is not int or not 0 < n <= 1048576 for n in (stdout_limit, stderr_limit))
            or signal.getsignal(signal.SIGCHLD) != signal.SIG_DFL):
        raise ValueError('child-input')
    owner = os.getpid()
    end = _clock()+seconds
    result = dict(stdout=b'', stderr=b'', stdout_eof=False, stderr_eof=False,
                  exitcode=None, failure=None, retirement='not-started', identity=None)
    launch_env = {'PATH': '/usr/bin:/bin', 'HOME': '/', 'LC_ALL': 'C'} if env is None else dict(env)

    def limits():
        os.setsid()
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        resource.setrlimit(resource.RLIMIT_CPU, (int(math.ceil(seconds))+1, int(math.ceil(seconds))+1))
        resource.setrlimit(resource.RLIMIT_FSIZE, (16777216, 16777216))
        resource.setrlimit(resource.RLIMIT_NOFILE, (64, 64))
        if sys.platform.startswith('linux'):
            resource.setrlimit(resource.RLIMIT_AS, (2147483648, 2147483648))

    try:
        child = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                 stderr=subprocess.PIPE, close_fds=True, env=launch_env, preexec_fn=limits)
    except (OSError, ValueError, getattr(subprocess, 'SubprocessError', OSError)):
        result['failure'] = 'spawn'
        return result
    result['retirement'] = 'pending'
    result['identity'] = {'pid': child.pid, 'owner': owner, 'kernel': None}
    streams = [child.stdin, child.stdout, child.stderr]
    reads = {child.stdout.fileno(): 'stdout', child.stderr.fileno(): 'stderr'}
    buffers = {'stdout': bytearray(), 'stderr': bytearray()}
    writefd, offset = child.stdin.fileno(), 0
    try:
        result['identity']['kernel'] = _kernel_identity(child.pid)
        if sys.platform.startswith('linux'):
            k = result['identity']['kernel']
            if k['ppid'] != owner or k['pgrp'] != child.pid or k['session'] != child.pid:
                raise ValueError('child-identity')
        for stream in streams:
            fd = stream.fileno()
            fcntl.fcntl(fd, fcntl.F_SETFL, fcntl.fcntl(fd, fcntl.F_GETFL) | os.O_NONBLOCK)
        if not input_bytes:
            child.stdin.close()
            writefd = None
        while reads or child.returncode is None:
            child.poll()
            if authority_expires is not None and time.time() >= authority_expires:
                result['failure'] = 'authority-expired'
                break
            if _clock() >= end:
                result['failure'] = 'deadline'
                break
            ready, writable, unused = select.select(list(reads), [] if writefd is None else [writefd], [], min(.02, max(0, end-_clock())))
            for fd in writable:
                try:
                    offset += os.write(fd, input_bytes[offset:offset+4096])
                except OSError as exc:
                    if exc.errno in (errno.EAGAIN, errno.EWOULDBLOCK):
                        continue
                    raise
                if offset == len(input_bytes):
                    child.stdin.close()
                    writefd = None
            for fd in ready:
                try:
                    chunk = os.read(fd, 4096)
                except OSError as exc:
                    if exc.errno in (errno.EAGAIN, errno.EWOULDBLOCK):
                        continue
                    raise
                slot = reads[fd]
                if not chunk:
                    result[slot+'_eof'] = True
                    del reads[fd]
                    continue
                limit = stdout_limit if slot == 'stdout' else stderr_limit
                room = min(limit-len(buffers[slot]), 1048576-sum(len(x) for x in buffers.values()))
                buffers[slot].extend(chunk[:room])
                if len(chunk) > room:
                    result['failure'] = slot+'-limit'
                    break
            if result['failure']:
                break
    except (OSError, ValueError):
        result['failure'] = 'pipe-or-identity'
    finally:
        # No wait/poll between identity validation and signal: this direct child
        # has not been reaped, so its PID cannot be reused by our supervisor.
        if child.returncode is None:
            if _same_child(child, result['identity'], owner):
                try:
                    os.kill(child.pid, signal.SIGKILL)
                except OSError as exc:
                    if exc.errno != errno.ESRCH:
                        result['retirement'] = 'signal-held'
                reap_end = _clock()+1
                while child.poll() is None and _clock() < reap_end:
                    time.sleep(.01)
                if child.returncode is None:
                    result['retirement'] = 'reap-held'
            else:
                result['retirement'] = 'identity-held'
        if child.returncode is not None:
            result['retirement'] = 'complete'
        for stream in streams:
            stream.close()
        result['stdout'], result['stderr'] = bytes(buffers['stdout']), bytes(buffers['stderr'])
        result['exitcode'] = child.returncode
    if not result['failure'] and child.returncode != 0:
        result['failure'] = 'child-exit'
    if not result['failure'] and result['stderr']:
        result['failure'] = 'child-stderr'
    return result
