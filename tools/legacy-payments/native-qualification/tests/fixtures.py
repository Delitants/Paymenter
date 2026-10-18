"""Fictional proposal documents; none are native execution evidence."""
import hashlib
import json
from datetime import datetime, timedelta, timezone

RUN = 'a' * 64
BOOT = 'fictional-boot'
RUNTIME = 'fictional-runtime'
TABLES = ['paymenter_qa_business_v1', 'paymenter_qa_journal_v1']


def encode(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()


def timestamp(seconds):
    return (datetime.now(timezone.utc) + timedelta(seconds=seconds)).strftime('%Y-%m-%dT%H:%M:%SZ')


def packet(mode='framework', mutate=None):
    expires = timestamp(300)
    common = dict(schema_version=1, run_id=RUN, runtime_id=RUNTIME,
                  boot_id=BOOT, expires_at=expires)
    documents = {
        'runtime': dict(common, purpose='source-payment-qa-runtime', observed_at=timestamp(-1),
                        platform='linux', engine='InnoDB', header_namespace='billmgr/payment'),
        'build': dict(common, purpose='source-payment-qa-build', runtime_sha256='',
                      module_sha256='b' * 64, registration='reviewed', install='reviewed',
                      unload='reviewed', access='reviewed'),
        'registry': dict(common, purpose='source-payment-qa-registry', retired=False,
                         tables=TABLES[:], tables_initially_absent=True, objects=[], used_ids=[]),
        'authority': dict(common, purpose='source-payment-qa-authority', mode=mode,
                          intent='prepare-only', registry_sha256='', actor='fictional-actor',
                          actor_permission='reviewed', manual_lookup='reviewed',
                          manual_calls='reviewed', retirement='reviewed',
                          isolation=dict(mail='reviewed', queue='reviewed', provider='reviewed')),
    }
    if mode == 'manual':
        documents['registry']['objects'] = [
            dict(kind='account', id='1', parent=None, created_by_run=RUN,
                 migration_selected=False, services=0, currency='USD', initial_balance='0.0000'),
            dict(kind='subaccount', id='2', parent='1', created_by_run=RUN,
                 migration_selected=False, services=0, currency='USD', initial_balance='0.0000'),
            *[dict(kind='payment', id=str(n), parent='2', created_by_run=RUN,
                   migration_selected=False, services=0, currency='USD', initial_balance='0.0000')
              for n in range(3, 7)],
        ]
    if mutate:
        mutate(documents)
    documents['build']['runtime_sha256'] = hashlib.sha256(encode(documents['runtime'])).hexdigest()
    documents['authority']['registry_sha256'] = hashlib.sha256(encode(documents['registry'])).hexdigest()
    originals = {}
    profile = dict(schema_version=1, mode=mode, run_id=RUN, expires_at=expires)
    for slot, document in documents.items():
        raw = encode(document)
        path = '/fictional/source-qa/' + slot + '.json'
        originals[path] = raw
        profile[slot] = dict(path=path, sha256=hashlib.sha256(raw).hexdigest(), bytes=len(raw))
    return profile, originals


def case(case_id='F1', phase='after'):
    return dict(purpose='source-payment-qa-v1', run_id=RUN, case_id=case_id,
                phase=phase, evidence=[dict(path='/fictional/source-qa/observation.json',
                                          sha256='c' * 64, bytes=100)])
