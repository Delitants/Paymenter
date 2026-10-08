"""Local-only fictional boundary validation. No apply, sign, freeze or restore."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import sys
import time
import uuid
from boundary_schema import BoundaryError, PREFIX, canonical, parse_boundary_document, require
from boundary_refs import ReferenceSet, ordinary, token
from boundary_coverage import evaluate_boundary, blocker

class Arguments(argparse.ArgumentParser):
    def error(self,message):raise BoundaryError('argument-form')

def boot_id():
    value=Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    require(0<len(value)<=256,'witness-observer');return value

def current_observer_identity(pid: int) -> dict:
    require(type(pid) is int and 0<pid<2**31,'witness-observer')
    try:
        boot=boot_id();root=Path('/proc')/str(pid)
        def start():return (root/'stat').read_text().rsplit(')',1)[1].split()[19]
        before=start();h=hashlib.sha256()
        with (root/'exe').open('rb') as f:
            for chunk in iter(lambda:f.read(65536),b''):h.update(chunk)
        require(before==start() and boot==boot_id(),'witness-observer')
        return {'boot_id':boot,'pid':pid,'start_ticks':before,'exe_sha256':h.hexdigest()}
    except (OSError,ValueError,IndexError):raise BoundaryError('witness-observer') from None

def save_boundary_report(root: Path,raw: bytes) -> str:
    parse_boundary_document(raw,PREFIX+'report')
    try:
        with ReferenceSet(root) as owner:
            name='boundary-unacknowledged-'+uuid.uuid4().hex+'.json'
            fd=os.open(name,os.O_CREAT|os.O_EXCL|os.O_RDWR|os.O_NOFOLLOW|os.O_CLOEXEC,0o600,dir_fd=owner.root_fd)
            try:
                original=os.fstat(fd);ordinary(original);remaining=memoryview(raw)
                while remaining:
                    written=os.write(fd,remaining);require(written>0,'report-storage');remaining=remaining[written:]
                os.fsync(fd);os.fsync(owner.root_fd);owner.verify_unchanged()
                current=os.stat(name,dir_fd=owner.root_fd,follow_symlinks=False);ordinary(current)
                require(token(current)==token(original),'reference-substitution')
                os.lseek(fd,0,os.SEEK_SET);saved=owner._raw(fd);require(saved==raw,'report-storage')
                current=os.stat(name,dir_fd=owner.root_fd,follow_symlinks=False);held=os.fstat(fd)
                require(token(current)==token(original)==token(held),'reference-substitution');ordinary(current);ordinary(held);owner.verify_unchanged()
                return hashlib.sha256(saved).hexdigest()
            finally:os.close(fd)
    except OSError:raise BoundaryError('report-storage') from None

def identity_map(bundle):
    result={}
    for pid in {w.value['observer_identity']['pid'] for w in bundle.witnesses}:
        try:result[pid]=current_observer_identity(pid)
        except BoundaryError:pass  # A disappeared observer is unproved, never successful absence.
    return result

def arguments(argv):
    p=Arguments(add_help=False,allow_abbrev=False);commands=p.add_subparsers(dest='operation',required=True,parser_class=Arguments)
    for name in ('validate','evaluate'):
        sub=commands.add_parser(name,add_help=False,allow_abbrev=False)
        for flag in ('plan','plan-sha256','reference-root','evidence'):sub.add_argument('--'+flag,required=True)
        if name=='evaluate':sub.add_argument('--witness',nargs=2,action='append',default=[])
    return p.parse_args(argv)

def main(argv=None) -> int:
    try:
        a=arguments(argv);witness_refs=[{'path':p,'sha256':h} for p,h in getattr(a,'witness',[])]
        with ReferenceSet(Path(a.reference_root)) as refs:
            bundle=refs.load_plan({'path':a.plan,'sha256':a.plan_sha256},witness_refs)
            before=identity_map(bundle);boot=boot_id()
            if a.operation=='evaluate':report=evaluate_boundary(bundle,before,boot,time.monotonic_ns())
            else:report={'schema_version':1,'purpose':PREFIX+'report','plan_sha256':bundle.plan.sha256,'scope_sha256':bundle.scope.sha256,
                'witness_sha256s':[],'structurally_valid':True,'coverage_complete':False,'fictional_witnesses_complete':False,'plan_accepted':False,
                'blockers':[blocker('syntax-only')],'enforcement_complete':False,'real_execution_ready':False}
            refs.verify_unchanged();require(before==identity_map(bundle) and boot==boot_id(),'witness-observer')
            h=save_boundary_report(Path(a.evidence),canonical(report))
            refs.verify_unchanged();require(before==identity_map(bundle) and boot==boot_id(),'witness-observer')
            result={'schema_version':1,'purpose':'source-control-boundary-cli-result','operation':a.operation,
                'document_count':len(refs.cache),'blocker_count':len(report['blockers']),'report_sha256':h,'real_execution_ready':False}
        print(json.dumps(result,sort_keys=True));return 0 if a.operation=='validate' else 3
    except BoundaryError as exc:print('boundary: '+exc.code,file=sys.stderr);return 2
    except (OSError,ValueError,TypeError,KeyError,RecursionError):print('boundary: document-form',file=sys.stderr);return 2

if __name__=='__main__':sys.exit(main())
