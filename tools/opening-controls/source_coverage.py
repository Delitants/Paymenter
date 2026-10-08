"""Read-only coverage. Even complete ownership cannot prove writer enforcement."""
from source_schema import CONTROL_CODES,MAX_AGE_NS,digest,validate_observation,validate_coverage_profile

def evaluate(observation,profile,controller_boot_id,now_ns):
    validate_observation(observation);blockers=[]
    def block(code,object_id,ref):blockers.append({'code':code,'object_id':object_id,'evidence_ref':ref})
    obs_hash=digest(observation);r=observation['receipt'];profile_hash=None
    if (type(now_ns) is not int or now_ns<r['capture_end_ns'] or r['controller_boot_id']!=controller_boot_id
        or now_ns-r['capture_start_ns']>MAX_AGE_NS):block('stale-observation','receipt',obs_hash)
    for e in observation['errors']:block(e['code'],e['ref'],obs_hash)
    if profile is None:block('profile-unaccepted','profile',obs_hash)
    else:
        validate_coverage_profile(profile);profile_hash=digest(profile)
        if profile['accepted_observation_sha256']!=obs_hash or profile['expected_host']!=observation['host_identity']:block('accepted-observation-changed','observation',obs_hash)
        assigned={a['object_id']:a for a in profile['assignments']};seen=set()
        for o in observation['objects']:
            seen.add(o['id']);a=assigned.get(o['id'])
            if a is None:block('unmapped-object',o['id'],o['sha256'])
            elif a['sha256']!=o['sha256']:block('object-changed',o['id'],o['sha256'])
        for name in sorted(set(assigned)-seen):block('expected-object-missing',name,profile_hash)
    complete=not blockers
    for code in sorted(CONTROL_CODES):block('enforcement-unproved',code,obs_hash)
    return {'schema_version':1,'purpose':'source-control-coverage-report','observation_sha256':obs_hash,'profile_sha256':profile_hash,
            'blockers':blockers,'inventory_complete':complete,'enforcement_complete':False,'real_execution_ready':False}
