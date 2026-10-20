"""Pure record comparison. No payment status can confer money authority."""
from dataclasses import dataclass
import hashlib
from models import AuditError,Dataset,FieldSpec,parse_decimal
from query_plan import fictional_profile
from private_io import canonical,decode_json
from collect import freeze,plain,binding,validate_row,validate_coverage

PROVENANCE=('provider_validation','manual_authorization','allocation','business_atomicity','opening_membership')

@dataclass(frozen=True)
class Change:
    kind:str
    previous_hash:str|None
    current_hash:str|None
    fields:tuple[str,...]

@dataclass(frozen=True)
class Analysis:
    changes:object
    exceptions:object
    row_digests:object
    payment_authority:object
    provenance:object
    canonical_values:object
    def __post_init__(self):
        for name in ('changes','exceptions','row_digests','payment_authority','provenance','canonical_values'):object.__setattr__(self,name,freeze(getattr(self,name)))


def canonical_row(dataset,row,profile):
    if profile!=fictional_profile():raise AuditError('analysis-profile')
    validate_row(dataset,dict(row))
    names=('balance','creditlimit') if dataset==Dataset.SUBACCOUNTS else ('subaccountamount','paymethodamount','usedamount','commissionamount','taxrate','taxamount') if dataset==Dataset.PAYMENTS else ()
    values={name:parse_decimal(row[name],FieldSpec(18,4)).canonical for name in names}
    return canonical({'dataset':dataset.value,'source':dict(row),'canonical_values':values})


def compare(current,previous,descriptor,profile):
    want=binding(descriptor,profile)
    for collection in (current,previous):
        if collection is None:continue
        if collection.state!='complete' or collection.origin!='fictional' or plain(collection.profile_binding)!=want:raise AuditError('analysis-binding')
        validate_coverage(collection.rows,descriptor)
    changes={};digests={};exceptions={};authority={};provenance={};values={}
    subs={s['id']:s for s in current.rows['subaccounts']};methods={m['id']:m for m in current.rows['methods']};currencies={c['id']:c for c in current.rows['currencies']}
    for dataset in Dataset:
        new={};old={}
        for collection,target in ((current,new),(previous,old)):
            if collection is None:continue
            for row in collection.rows[dataset.value]:
                if row['id'] in target:raise AuditError('analysis-duplicate')
                encoded=canonical_row(dataset,row,profile)
                target[row['id']]=(row,hashlib.sha256(b'legacy-source-audit-row-v1\0'+encoded).hexdigest())
                if collection is current:values[dataset.value+':'+row['id']]=decode_json(encoded)['canonical_values']
        for ident in sorted(set(new)|set(old),key=int):
            key=dataset.value+':'+ident;n=new.get(ident);o=old.get(ident)
            kind='first_seen' if o is None else 'missing_since_previous_complete' if n is None else 'unchanged' if n[1]==o[1] else 'changed'
            changed=tuple(sorted(k for k in n[0] if o is not None and n[0][k]!=o[0][k])) if n is not None else ()
            changes[key]=Change(kind,o[1] if o else None,n[1] if n else None,changed)
            if n:digests[key]=n[1]
            if dataset!=Dataset.PAYMENTS:continue
            authority[key]='unqualified';provenance[key]={k:'not_evaluated' for k in PROVENANCE}
            codes={'provenance_not_evaluated','source_status_unmapped'}
            if n is None:codes.add('record_missing');exceptions[key]=tuple(sorted(codes));continue
            row=n[0]
            sub=subs[row['subaccount']];method=methods.get(row['paymethod'])
            if sub['account'] in descriptor.held_account_ids:codes.add('elected_hold')
            if method is None or not method['module']:codes.add('method_unknown')
            if any(row.get('link_'+x)=='present' for x in ('externalid','billorder','invoice','sender','recipient','xmlparams','expense_commission','recurring','recurring_subscription','recurring_action_on_fail','recurring_action_on_success')):codes.add('link_present')
            if any(v in ('null','invalid') for k,v in row.items() if k.startswith('flag_')) or any(v=='invalid' for k,v in row.items() if k.startswith('link_')):codes.add('link_unknown')
            if row['flag_refund']=='on':codes.add('refund_related')
            if row['link_recurring']=='present' or row['link_recurring_subscription']=='present' or (method and method['recurring']=='on'):codes.add('recurring_related')
            ids=(row['currency'],sub['currency'],method['currency'] if method else None)
            if any(i is None or i not in currencies or not currencies[i]['iso'] for i in ids):codes.add('currency_unknown')
            if len(set(i for i in ids if i is not None))>1:codes.add('currency_mismatch')
            if kind=='changed' and any(k in changed for k in ('subaccount','paymethod','currency','subaccountamount','paymethodamount','usedamount','commissionamount','taxrate','taxamount')):codes.add('economics_changed')
            exceptions[key]=tuple(sorted(codes))
    return Analysis(changes,exceptions,digests,authority,provenance,values)
