"""Pure semantic projection. No SQL text, transport or credential handling."""
from dataclasses import dataclass, replace
from sql_fence_schema import (GrantModel,GrantRow,SqlFenceError,BusinessRequirement,
    parse_seed,model_record,canonical,sha,require,PRIVILEGES)

BUSINESS = frozenset(('SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX',
                      'REFERENCES','CREATE TEMPORARY TABLES','LOCK TABLES'))

@dataclass(frozen=True)
class GrantOperation:
    kind: str
    principal: tuple[str,str]
    target: tuple[str,...]
    privileges: tuple[str,...]

@dataclass(frozen=True)
class Projection:
    before: GrantModel
    held: GrantModel
    establish: tuple[GrantOperation,...]
    restore: tuple[GrantOperation,...]


def identifier(value: str) -> str:
    require(type(value) is str and 0<len(value)<=64 and '\0' not in value,'sql-identifier')
    return '`'+value.replace('`','``')+'`'


def database_pattern(value: str) -> str:
    require(type(value) is str and 0<len(value)<=64 and '\0' not in value,'sql-pattern')
    return value.replace('\\','\\\\').replace('_','\\_').replace('%','\\%')


def semantic_hash(model: GrantModel) -> str:return sha(canonical(model_record(model)))


def _validated(m):
    try:normalized=parse_seed(canonical(model_record(m)))
    except (TypeError,AttributeError,KeyError,ValueError):raise SqlFenceError('sql-model') from None
    require(m==normalized,'sql-model-normalization')
    return normalized


def apply_operation(model: GrantModel, op: GrantOperation) -> GrantModel:
    _validated(model)
    require(type(op) is GrantOperation and op.kind.count(':')==1,'sql-operation')
    action,level=op.kind.split(':');require(action in ('grant','revoke'),'sql-operation')
    row=GrantRow(op.principal,level,op.target,op.privileges)
    # Validate shape and vocabulary even for a no-op revoke.
    candidate=replace(model,grants=tuple(g for g in model.grants if (g.principal,g.level,g.target)!=(row.principal,level,row.target))+(row,))
    parse_seed(canonical(model_record(candidate)))
    rows={(g.principal,g.level,g.target):set(g.privileges) for g in model.grants}
    key=(row.principal,level,row.target);current=rows.get(key,set())
    after=current|set(row.privileges) if action=='grant' else current-set(row.privileges)
    if after:rows[key]=after
    else:rows.pop(key,None)
    return replace(model,grants=tuple(GrantRow(k[0],k[1],k[2],tuple(sorted(v))) for k,v in sorted(rows.items())))


def _diff(before,after):
    b={(g.principal,g.level,g.target):set(g.privileges) for g in before.grants}
    a={(g.principal,g.level,g.target):set(g.privileges) for g in after.grants}
    ops=[]
    # Materialize explicit safe business access before removing broad access.
    for action in ('grant','revoke'):
        for k in sorted(set(b)|set(a)):
            privileges=(a.get(k,set())-b.get(k,set())) if action=='grant' else (b.get(k,set())-a.get(k,set()))
            if privileges:ops.append(GrantOperation(action+':'+k[1],k[0],k[2],tuple(sorted(privileges))))
    return tuple(ops)


def compile_projection(model: GrantModel, protected: str, preserved: tuple[str,...],
                       required: tuple[BusinessRequirement,...]) -> Projection:
    model=_validated(model)
    require(protected in model.schemas and len(preserved)==3 and len(set(preserved))==3 and protected not in preserved and set(preserved)<=set(model.schemas),'sql-schema-scope')
    identities={(p.user,p.host):p for p in model.principals}
    for p in model.principals:
        require(len({q.role for q in model.principals if q.user==p.user})==1,'sql-host-ambiguity')
    require(not model.definitions,'sql-definer-escape')
    require(not any(g.level in ('proxy','routine') for g in model.grants),'sql-unsupported-graph')
    seen=set();held=[]
    for g in model.grants:
        role=identities[g.principal].role
        if role=='administrator':held.append(g)
        elif role in ('collector','keeper'):
            require(g.level=='database' and g.target==(protected,) and set(g.privileges)<={'SELECT'},'sql-readonly-role')
            held.append(g)
    for r in required:
        require(type(r) is BusinessRequirement and r.schema in preserved and r.role in ('writer','shared_writer') and r.privileges and set(r.privileges)<=BUSINESS,'sql-business-escape')
        require((r.schema,r.role) not in seen,'sql-business-duplicate');seen.add((r.schema,r.role))
        actors=[p for p in model.principals if p.role==r.role];require(actors,'sql-business-principal')
        for p in actors:
            principal=(p.user,p.host)
            available=set()
            for g in model.grants:
                if g.principal==principal and (g.level=='global' or (g.level=='database' and g.target==(r.schema,))):available.update(g.privileges)
            require(set(r.privileges)<=available,'sql-business-unproven')
            held.append(GrantRow(principal,'database',(r.schema,),tuple(sorted(r.privileges))))
    require({r.schema for r in required}==set(preserved),'sql-business-scope')
    held_model=replace(model,grants=tuple(sorted(held,key=lambda g:(g.principal,g.level,g.target))))
    _validated(held_model)
    return Projection(model,held_model,_diff(model,held_model),_diff(held_model,model))
