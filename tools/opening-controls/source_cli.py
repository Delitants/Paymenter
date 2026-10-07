"""Observe/evaluate only. No apply, freeze, signing, billing or provider operation."""
import argparse
import hashlib
import os
from pathlib import Path
import re
import select
import shlex
import subprocess
import sys
import time
import uuid
from custody import private_directory,private_file,CustodyError
from source_schema import SourceError,MAX_CAPTURE,MAX_PART,canonical,digest,parse_document,validate_capture_profile
from source_inventory import inventory
from source_coverage import evaluate

class ControllerClock:
    def boot_id(self):
        if sys.platform.startswith('linux'):return Path('/proc/sys/kernel/random/boot_id').read_text().strip()
        if sys.platform=='darwin':
            raw=subprocess.check_output(['sysctl','-n','kern.boottime'],timeout=5,text=True)
            fields=re.search(r'sec\s*=\s*(\d+),\s*usec\s*=\s*(\d+)',raw)
            if fields:return hashlib.sha256((fields[1]+':'+fields[2]).encode()).hexdigest()
        raise SourceError('Controller boot identity unavailable')
    def now_ns(self):return time.monotonic_ns()

class SSHTransport:
    def __init__(self,destination):
        if not isinstance(destination,str) or not re.fullmatch(r'(?:[A-Za-z_][A-Za-z0-9_-]*@)?(?:[A-Za-z0-9][A-Za-z0-9.-]*|\[[a-fA-F0-9:]+\])',destination):raise SourceError('Exact SSH destination required')
        self.destination=destination
    def capture(self,program,profile):
        args=['ssh','-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','--',self.destination,'python -c '+shlex.quote(program.decode('ascii'))]
        proc=subprocess.Popen(args,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        pending=canonical(profile);offset=0;outputs={proc.stdout:bytearray(),proc.stderr:bytearray()};active=list(outputs);end=time.monotonic()+60
        try:
            while active or proc.stdin is not None:
                if time.monotonic()>=end:raise SourceError('Source transport timed out')
                writes=[] if proc.stdin is None else [proc.stdin]
                ready,writable,_=select.select(active,writes,[],.1)
                if writable:
                    try:offset+=os.write(proc.stdin.fileno(),pending[offset:offset+4096])
                    except BrokenPipeError:raise SourceError('Source transport stopped')
                    if offset==len(pending):proc.stdin.close();proc.stdin=None
                for pipe in ready:
                    chunk=os.read(pipe.fileno(),65536)
                    if not chunk:active.remove(pipe);continue
                    outputs[pipe].extend(chunk)
                    if len(outputs[pipe])>(MAX_CAPTURE if pipe is proc.stdout else MAX_PART):raise SourceError('Source output exceeded private limit')
            return proc.wait(timeout=max(.1,end-time.monotonic())),bytes(outputs[proc.stdout]),bytes(outputs[proc.stderr])
        finally:
            if proc.poll() is None:
                proc.terminate()
                try:proc.wait(timeout=2)
                except subprocess.TimeoutExpired:proc.kill();proc.wait()
            for stream in [proc.stdin,proc.stdout,proc.stderr]:
                if stream is not None:stream.close()

def private_save(root,name,raw):
    try:
        root=private_directory(root);path=root/name
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb') as f:f.write(raw);f.flush();os.fsync(f.fileno())
        private_file(path);fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(fd)
        finally:os.close(fd)
        return path
    except (OSError,CustodyError) as exc:raise SourceError('Exclusive owned private evidence required') from exc

def observe(profile,transport,evidence_root,clock):
    validate_capture_profile(profile)
    try:private_directory(evidence_root)
    except (OSError,CustodyError) as exc:raise SourceError('Private evidence root required') from exc
    boot=clock.boot_id();start=clock.now_ns();capture_id=uuid.uuid4().hex
    program=Path(__file__).with_name('source_collect.py').read_bytes()
    try:status,stdout,stderr=transport.capture(program,profile)
    except Exception as exc:
        private_save(evidence_root,capture_id+'.transport-failure.json',canonical({'purpose':'source-control-transport-failure','error_type':type(exc).__name__}));raise SourceError('Capture transport failed; private evidence retained') from exc
    private_save(evidence_root,capture_id+'.raw.json',stdout);private_save(evidence_root,capture_id+'.stderr',stderr)
    if status!=0:raise SourceError('Source capture failed; private evidence retained')
    raw=parse_document(stdout,'source-control-raw-capture')
    if raw['host_identity']['hostname']!=profile['expected_host']['hostname']:raise SourceError('Wrong source host')
    if raw['capture_errors']:raise SourceError('Source capture contains errors')
    end=clock.now_ns()
    if clock.boot_id()!=boot:raise SourceError('Controller identity changed')
    obs=inventory(raw,{'controller_boot_id':boot,'capture_start_ns':start,'capture_end_ns':end})
    private_save(evidence_root,capture_id+'.observation.json',canonical(obs))
    return obs

def save_report(root,report):return private_save(root,'coverage-'+digest(report)+'.json',canonical(report))

def load_private(path,purpose):
    path=Path(path)
    try:private_directory(path.parent);private_file(path)
    except (OSError,CustodyError) as exc:raise SourceError('Private input required') from exc
    return parse_document(path.read_bytes(),purpose)

def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__);commands=parser.add_subparsers(dest='mode',required=True)
    observer=commands.add_parser('observe');observer.add_argument('--source',required=True);observer.add_argument('--profile',required=True);observer.add_argument('--evidence',required=True)
    checker=commands.add_parser('evaluate');checker.add_argument('--observation',required=True);checker.add_argument('--profile');checker.add_argument('--evidence',required=True)
    args=parser.parse_args(argv);clock=ControllerClock()
    try:
        if args.mode=='observe':
            obs=observe(load_private(args.profile,'source-control-capture-profile'),SSHTransport(args.source),Path(args.evidence),clock)
            print(canonical({'observation_id':obs['observation_id'],'capture_complete':True,'inventory_complete':not obs['errors'],'real_execution_ready':False}).decode());return 0
        obs=load_private(args.observation,'source-control-observation');profile=load_private(args.profile,'source-control-coverage-profile') if args.profile else None
        report=evaluate(obs,profile,clock.boot_id(),clock.now_ns());save_report(Path(args.evidence),report)
        print(canonical({'inventory_complete':report['inventory_complete'],'blockers':len(report['blockers']),'real_execution_ready':False}).decode());return 1
    except (SourceError,OSError,ValueError,subprocess.SubprocessError):
        print(canonical({'purpose':'source-control-operation-failure','real_execution_ready':False}).decode());return 1

if __name__=='__main__':sys.exit(main())
