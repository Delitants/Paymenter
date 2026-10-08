"""Pure fictional assessment. Inventory and assertions never grant enforcement."""
from collections import Counter
from boundary_schema import PREFIX, CONTROL_CODES, member_manifest_sha256, validate_boundary_report, CONTEXT_KEYS, MAX_AGE_NS

ADAPTERS=frozenset({'fixture-boundary-v1'})

def blocker(code,control_id=None,object_id=None,evidence_sha256=None):
    return dict(code=code,control_id=control_id,object_id=object_id,evidence_sha256=evidence_sha256)

def document(bundle,r):return bundle.documents[r['sha256']].value

def records(bundle,refs,kind=None):
    result=[]
    for r in refs:
        d=document(bundle,r)
        if d['purpose']==PREFIX+'fixture-evidence' and (kind is None or d['kind']==kind):result.extend(d['records'])
    return result

def matches(rows,obj,outcome='pass',reason=None):
    return any(r['object_id']==obj['object_id'] and r['object_sha256']==obj['object_sha256'] and r['outcome']==outcome and (reason is None or r['reason_code']==reason) for r in rows)

def positive(bundle,r):
    d=document(bundle,r)
    return bool(d['records']) and not d['errors'] and all(x['outcome']=='pass' for x in d['records'])

def evaluate_boundary(bundle,observer_identities,observer_boot_id,now_ns):
    p=bundle.plan.value;s=bundle.scope.value;blocks=[]
    if any(p[k]!=s[k] for k in ('source_identity','target_identity')):blocks.append(blocker('scope-identity'))
    for d in bundle.documents.values():
        if d.purpose==PREFIX+'scope' or (d.purpose==PREFIX+'fixture-evidence' and d.value['context'] is None):
            for e in d.value['errors']:blocks.append(blocker('capture-unacceptable',object_id=e['object_id'],evidence_sha256=d.sha256))
            for row in d.value.get('records',[]):
                if row['outcome']!='pass' or row['reason_code']=='shared-scope-unapproved':
                    code='shared-scope-unapproved' if row['reason_code']=='shared-scope-unapproved' else 'scope-evidence-unproved'
                    blocks.append(blocker(code,object_id=row['object_id'],evidence_sha256=d.sha256))
    for slot in ('cohort_ref','baseline_ref'):
        if not positive(bundle,p[slot]):blocks.append(blocker('scope-evidence-unproved',evidence_sha256=p[slot]['sha256']))
    counts=Counter();inventory={}
    for o in s['objects']:
        if o['object_id'] in inventory:blocks.append(blocker('object-duplicate',object_id=o['object_id']))
        inventory[o['object_id']]=o
    def assign(obj,cid=None):
        oid=obj['object_id'];counts[oid]+=1
        if oid not in inventory:blocks.append(blocker('object-added',cid,oid))
        elif inventory[oid]['object_sha256']!=obj['object_sha256']:blocks.append(blocker('object-hash-changed',cid,oid))
    requirements=Counter(c['requirement'] for c in p['controls']);cids={c['control_id'] for c in p['controls']}
    for code in sorted(CONTROL_CODES):
        if not requirements[code]:blocks.append(blocker('requirement-missing'))
    for c in p['controls']:
        cid=c['control_id']
        for dep in c['dependencies']:
            if dep not in cids:blocks.append(blocker('dependency-missing',cid))
        if c['adapter_id'] not in ADAPTERS:blocks.append(blocker('adapter-unavailable',cid))
        else:blocks.append(blocker('fixture-adapter-not-production',cid))
        manifest=document(bundle,c['adapter_manifest_ref'])
        if not positive(bundle,c['adapter_manifest_ref']) or not any(r['reason_code']==c['adapter_id'] for r in manifest['records']):
            blocks.append(blocker('adapter-manifest-mismatch',cid,evidence_sha256=c['adapter_manifest_ref']['sha256']))
        if not positive(bundle,c['rollback_ref']):blocks.append(blocker('rollback-unproved',cid))
        for m in c['members']:
            assign(m,cid)
            if not matches(records(bundle,c['precondition_refs'],'precondition'),m) or not matches(records(bundle,c['blocking_check_refs'],'blocking-check'),m):blocks.append(blocker('member-precondition-unproved',cid,m['object_id']))
            if m['role']=='contained-unknown' and not matches(records(bundle,m['evidence_refs'],'containment'),m):blocks.append(blocker('containment-unproved',cid,m['object_id']))
            for r in m['evidence_refs']+c['precondition_refs']+c['blocking_check_refs']:
                if any(x['reason_code']=='shared-scope-unapproved' for x in document(bundle,r)['records']):blocks.append(blocker('shared-scope-unapproved',cid,m['object_id'],r['sha256']))
    unrelated_refs=[r for c in p['controls'] for r in c['unrelated_scope_refs']]
    for obj in s['unrelated']:
        assign(obj)
        if not matches(records(bundle,obj['evidence_refs'],'unrelated-scope'),obj) or not matches(records(bundle,unrelated_refs,'unrelated-scope'),obj):blocks.append(blocker('unrelated-unproved',object_id=obj['object_id']))
    for oid in inventory:
        if not counts[oid]:blocks.append(blocker('object-missing',object_id=oid))
    for oid,count in counts.items():
        if count>1:blocks.append(blocker('object-duplicate',object_id=oid))
    for u in p['unresolved']:blocks.append(blocker('unresolved-member',object_id=u['object_id']))
    coverage=not any(b['code'] not in {'fixture-adapter-not-production'} for b in blocks)
    witness_blocks=[];seen=Counter(w.value['control_id'] for w in bundle.witnesses)
    sessions={(w.value['session_id'],w.value['freeze_id'],w.value['control_generation']) for w in bundle.witnesses}
    for cid in cids:
        if not seen[cid]:witness_blocks.append(blocker('witness-missing',cid))
        elif seen[cid]>1:witness_blocks.append(blocker('witness-duplicate',cid))
    if len(sessions)>1:witness_blocks.append(blocker('witness-binding'))
    for w in bundle.witnesses:witness_blocks.extend(assess_witness(bundle,w,observer_identities,observer_boot_id,now_ns))
    fictional=coverage and not witness_blocks
    blocks.extend(witness_blocks)
    blocks.append(blocker('native-signing-unavailable'))
    report={'schema_version':1,'purpose':PREFIX+'report','plan_sha256':bundle.plan.sha256,'scope_sha256':bundle.scope.sha256,
        'witness_sha256s':sorted(w.sha256 for w in bundle.witnesses),'structurally_valid':True,'coverage_complete':coverage,
        'fictional_witnesses_complete':fictional,'plan_accepted':False,'blockers':blocks,'enforcement_complete':False,'real_execution_ready':False}
    report['blockers'].sort(key=lambda b:tuple(b[k] or '' for k in ('code','control_id','object_id','evidence_sha256')))
    return validate_boundary_report(report)


def assess_witness(bundle,witness,observer_identities,observer_boot_id,now_ns):
    w=witness.value;cid=w['control_id'];blocks=[]
    controls={c['control_id']:c for c in bundle.plan.value['controls']};c=controls.get(cid)
    if c is None:return [blocker('witness-binding',cid,evidence_sha256=witness.sha256)]
    if w['plan_sha256']!=bundle.plan.sha256 or w['source_boot_id']!=bundle.plan.value['source_identity']['boot_id'] or w['adapter_manifest_sha256']!=c['adapter_manifest_ref']['sha256'] or w['member_manifest_sha256']!=member_manifest_sha256(c['members']):
        blocks.append(blocker('witness-binding',cid,evidence_sha256=witness.sha256))
    if w['state']!='held':blocks.append(blocker('witness-state',cid,evidence_sha256=witness.sha256))
    obs=w['observer_identity'];iv=w['interval']
    if obs['boot_id']!=observer_boot_id or iv['observer_boot_id']!=observer_boot_id or observer_identities.get(obs['pid'])!=obs:
        blocks.append(blocker('witness-observer',cid,evidence_sha256=witness.sha256))
    start,end=int(iv['start_ns']),int(iv['end_ns'])
    if type(now_ns) is not int or now_ns<end or now_ns<start:blocks.append(blocker('witness-time',cid,evidence_sha256=witness.sha256))
    elif now_ns-end>MAX_AGE_NS:blocks.append(blocker('witness-stale',cid,evidence_sha256=witness.sha256))
    ctx={k:w[k] for k in CONTEXT_KEYS.split()}
    all_refs=([w['gate_readback_ref']] if w['gate_readback_ref'] is not None else [])+w['attempt_result_refs']+w['unrelated_result_refs']+w['failure_refs']
    for r in all_refs:
        stack=[r];visited=set()
        while stack:
            current=stack.pop()
            if current['sha256'] in visited:continue
            visited.add(current['sha256']);d=document(bundle,current)
            if d['context'] is not None and d['context']!=ctx:blocks.append(blocker('witness-binding',cid,evidence_sha256=current['sha256']))
            if d['errors'] or d['kind']=='failure' or any(x['outcome']!='pass' and not(d['kind']=='attempt-result' and x['outcome']=='blocked' and x['reason_code']=='fixture-writer-denied') for x in d['records']):
                blocks.append(blocker('witness-failure',cid,evidence_sha256=current['sha256']))
            stack.extend(d['refs'])
    if w['gate_readback_ref'] is None or not w['attempt_result_refs'] or (bundle.scope.value['unrelated'] and not w['unrelated_result_refs']):
        blocks.append(blocker('witness-evidence-missing',cid))
    elif not positive(bundle,w['gate_readback_ref']):blocks.append(blocker('witness-evidence-missing',cid))
    if w['gate_readback_ref'] is not None:
        gate=document(bundle,w['gate_readback_ref']);members={(m['object_id'],m['object_sha256']) for m in c['members']}
        valid=bool(gate['records']) and all(r['outcome']=='pass' and r['reason_code']=='fixture-gate-held' and (r['object_id'] is None or (r['object_id'],r['object_sha256']) in members) for r in gate['records'])
        global_fact=any(r['object_id'] is None for r in gate['records'])
        if not global_fact:valid=valid and all(matches(gate['records'],m,'pass','fixture-gate-held') for m in c['members'])
        if not valid:blocks.append(blocker('witness-gate-unproved',cid,evidence_sha256=w['gate_readback_ref']['sha256']))
    attempts=records(bundle,w['attempt_result_refs'],'attempt-result')
    for member in c['members']:
        if not matches(attempts,member,'blocked','fixture-writer-denied'):blocks.append(blocker('witness-attempt-unproved',cid,member['object_id']))
    for obj in bundle.scope.value['unrelated']:
        if not matches(records(bundle,w['unrelated_result_refs'],'unrelated-result'),obj):blocks.append(blocker('witness-evidence-missing',cid,obj['object_id']))
    return blocks
