#!/usr/bin/env python3
"""Offline fictional audit entry point. No live adapter, networking or ingestion."""
import sys
if sys.version_info<(3,11):
    sys.stderr.write('runtime-unsupported\n')
    raise SystemExit(2)
import argparse
from pathlib import Path
from models import AuditError,SystemClock,AuthorityClock
from descriptor import load_descriptor
from private_io import read_private_json,open_private_root,canonical
from query_plan import fictional_profile,build_plan
from collect import FictionalTransport,collect
from analyze import compare
from evidence import claim_attempt,load_previous,seal_attempt,summarize


def run_offline(descriptor_path,fixture_path,clock):
    clock=AuthorityClock(clock)
    profile=fictional_profile();d=load_descriptor(descriptor_path,profile,clock)
    fixture=read_private_json(fixture_path,33*1024*1024)
    if not isinstance(fixture,dict) or set(fixture)!={'version','origin','rows'} or type(fixture['version']) is not int or fixture['version']!=1 or fixture['origin']!='fictional' or not isinstance(fixture['rows'],dict):raise AuditError('fixture-schema')
    transport=FictionalTransport(d,profile,clock,fixture['rows']);plan=build_plan(d,profile)
    with open_private_root(Path(d.output_root)) as root:
        with claim_attempt(root,d,clock) as claim:
            previous=load_previous(root,d.previous_ref,d) if d.previous_ref is not None else None
            collection=collect(d,profile,plan,transport,clock)
            analysis=compare(collection,previous,d,profile) if collection.state=='complete' else None
            ref=seal_attempt(claim,collection,analysis,clock)
            return summarize(ref,collection,analysis)

class SafeParser(argparse.ArgumentParser):
    def error(self,message):raise AuditError('cli-arguments')


def main(argv=None):
    try:
        parser=SafeParser(description='Fictional offline source audit; no live adapter.',allow_abbrev=False)
        parser.add_argument('--descriptor',required=True,type=Path)
        parser.add_argument('--fixture',required=True,type=Path)
        args=parser.parse_args(argv)
        summary=run_offline(args.descriptor,args.fixture,SystemClock()).as_dict()
        sys.stdout.write(canonical(summary).decode('ascii')+'\n');sys.stdout.flush()
        return 0 if summary['completion']=='complete' else 2
    except AuditError as e:
        sys.stderr.write(e.code+'\n');return 2
    except (Exception,KeyboardInterrupt):
        sys.stderr.write('audit-refused\n');return 2

if __name__=='__main__':raise SystemExit(main())
