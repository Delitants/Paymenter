# Owned MariaDB privilege-fence feasibility

Status: the SQL-only scope is derived from the approved private physical-containment proposal. The accompanying implementation plan requires review before implementation. This documentation installs no controls and issues no operational authority.

## Goal and limits

Determine whether durable source-schema privilege contraction, independent of a table-lock keeper, can deny application writes while preserving explicitly required work in other existing schemas and exact grant recovery. Use independently bootstrapped fictional data and an explicitly supplied, checksum-verified MariaDB 5.5.68 runtime. The result is a feasibility experiment, not a production adapter, freeze proof or signing service.

The [source boundary architecture](2026-10-07-source-boundary-enforcement-design.md), [scheduler/process design](2026-10-07-scheduler-process-adapters-design.md) and [capture/readiness protocol](2026-10-14-source-capture-opening-readiness-design.md) retain their acceptance boundaries. Do not modify the application, opening schema, permissions, grants, holds, billing ownership, live schedulers/processes/ingress or provider integrations. Deferred timing work is not reused or resumed. No VM, container image, new host, real customer data, source credentials, provider request or genuine signing key is required.

## Selected approach

Build a closed fictional input/privilege model, a pure typed operation planner, and an owned native driver/rehearsal. Reuse the existing runtime archive validator and anchored private-reference checks without broadening the existing boundary parser's accepted purposes. All outputs retain `real_execution_ready=false`; no output is accepted as a physical boundary witness or opening attestation.

The privileged fixture administrator is a declared trust boundary. Do not claim that grants or a local control file resist malicious host/database administration. Automated fixture writers lose protected-schema DML/DDL and global escape privileges. Preserve only named existing-schema business operations; unrestricted global administration or creation of arbitrary databases cannot remain while claiming a schema-specific prohibition. Unsafe graphs produce a feasibility rejection, never an automatic broader outage.

## Input and data contracts

The closed schema-1 input purpose is `fictional-sql-privilege-rehearsal`. It contains exactly `schema_version`, `purpose`, `runtime_archive`, `runtime_manifest`, `grant_seed`, `protected_schema`, `preserved_schemas`, `required_business`, and `cases`. References contain exactly absolute `path` and original `sha256`. Paths/commands, live sockets/datadirs, host targets, credentials, SQL bodies, arbitrary modules and caller-supplied operation lists are not inputs.

`grant_seed` is a closed schema-1 `fictional-sql-grant-seed` record describing exact fictional application schema names, principal roles and normalized grant rows, without authentication material or predicted grant timestamps. The factory compares its newly initialized normalized grants to that seed, then captures the actual complete private native before-state, including grant-row metadata and authentication/resource fields. Original native hashes are created only after those bytes exist. This separates deterministic fixture intent from exact recoverable native originals.

Principal roles are writer, shared writer, collector, keeper and administrator. Generated account names fit the legacy 16-character user column. Preserve the distinct `(User,Host)` identity and all account-host/pattern/proxy/direct-grant relationships. Normalize all legacy privilege columns, including SHOW DATABASES and CREATE TABLESPACE; reject modern or unknown privileges. Database grant-pattern escaping is separate from identifier quoting. Credentials, TLS/resource attributes, grantor/timestamp fields and table column-privilege summaries must never be lost by a logical grant projection.

JSON control inputs use ordinary root-owned/root-group `0600` files inside anchored `0700` private directories. Retain inode/device/hash and pre/post-read verification, strict duplicate-key/nonfinite/float/boolean-type rejection, a 4 MiB individual JSON bound, 32 MiB combined unique JSON bound, 10,000 combined entries and eight reference levels. This closed graph has only the root input plus its declared seed/manifest children; arbitrary nested references are forbidden. The binary runtime archive retains the existing separate 256 MiB compressed/unpacked and per-member bounds from `lock_runtime`; it is not silently counted as a 4 MiB JSON document. Repeated references cannot leak descriptors or bypass aggregate bounds.

## Projection and exact recovery

The planner consumes a validated normalized grant model and explicit business requirements. It emits typed grants/revokes/restoration operations, with complete semantic before/held/restore models and hashes. It does not connect to a server or produce arbitrary SQL. Establishment denies protected DML/DDL and global/grant-table/credential/proxy/definer escapes while materializing only accepted unrelated-schema privileges.

Existing cached-privilege connections are part of the boundary. No held acknowledgment is allowed until all accepted old writer sessions are drained/closed and fresh authenticated identities are verified. Active or unexplained/shared sessions reject establishment; they are not killed to get a quiet census.

Native recovery retains exact original grant rows and nonprivilege account bytes. Each operation is journaled before execution with its actual current row/state identity and typed intent, then acknowledged only after exact physical readback and durable evidence. Native grant timestamps/hashes are observed, not predicted. Partial establishment, unknown state or a competing change prevents a complete result. Restore original grants and remove only exact generated rows under verified current-row preconditions; do not restore a stale whole database or erase accepted unrelated workload changes. Authentication and unrelated principals/rows remain untouched.

## Owned native execution and resource contract

The public CLI accepts only `--plan`, `--plan-sha256`, `--reference-root` and `--evidence`. The factory creates a new private root, mount/network/PID namespaces, tmpfs, cgroup and server/socket/datadir identities. User input cannot construct an owned context. Every SQL operation rechecks the actual server PID/start/boot, namespaces, runtime hashes, directory/socket identities and fixture marker. A path prefix or private JSON assertion alone is insufficient. Existing/supplied roots, sockets and datadirs are rejected before SQL.

Reuse only accepted archived loader/server/client/admin/bootstrap files with explicit options; never read host option files. Unix clients use factory-created private options containing fictional credentials only. For host-precedence tests, enable only loopback TCP inside the new network namespace, with fixed factory-selected port and verified absence of other interfaces/listeners. No external route or supplied address is allowed.

Use 300 protected InnoDB tables, three unrelated business schemas including a MyISAM workload, and separate demonstration/pattern-neighbor fixtures. Demonstration writes occur only on demonstration data. Cached write authorization can be exercised with a zero-row protected update and unchanged complete data fingerprint before admission; no protected value needs changing. The read-only keeper uses independent credentials and no snapshot/transaction responsibility.

Root free must remain at least 1 GiB + 128 MiB after the maximum 16 MiB retained evidence budget. Reserve at least 512 MiB shared-RAM and MemAvailable after a 256 MiB tmpfs budget and 256 MiB process allowance. Use a newly owned cgroup-v2 memory domain with 512 MiB combined memory cap, zero swap and bounded PIDs; this is only a QA resource limit, not source cgroup-v1 containment acceptance. Create/change no existing cgroup or ancestor controls. A missing/nondelegated memory controller fails admission, not a skipped test. Verify staged-size, actual memory peaks and evidence bounds; exceeding any bound fails the rehearsal. The input schema cannot enlarge these constants.

The parent owns staging/process/cgroup/mount identities and a private startup barrier before the child can bootstrap SQL. An independent owned supervisor retains the server while expendable controller children are interrupted/replaced. Worker/supervisor death, memory kill or lost custody is failure; it cannot produce success or silently restore grants. Cleanup verifies every owned identity, PID/socket/mount/cgroup absence and independently retained evidence before deleting exact staging. Failed or uncertain retirement preserves diagnostics/resources; no broad signal or deletion is allowed.

## Ten native acceptance cases

1. Demonstrate broader global-grant bypass of a narrow revoke and SUPER bypass of read-only on separate demonstration data with exact counter results.
2. Deny protected existing-table DML and existing/new-object DDL, truncate and cross-schema rename; verify exact protected data/schema unchanged.
3. Preserve each declared unrelated CRUD/accepted-DDL operation and MyISAM workload, with exact retained counters/data.
4. Demonstrate cached privileges in an existing session, reject active/unowned sessions, then require accepted drain/reconnect before held acknowledgment.
5. Exercise authenticated account/host precedence, underscore/percent collisions, table/column grants, proxy/grant-option candidates and unsafe/unknown graph rejection before projection mutation.
6. Reject grant-table/credential/admin escalation and unexpected cross-schema definer objects; retain only the declared trusted administrator exception.
7. Lose/start a transaction on the secondary keeper and interrupt the owned controller; primary restrictions remain, and a fresh controller cannot invent continuity.
8. Restart the owned server orderly and after a hard interruption under independent admission denial. Read back grants/integrity/counters before permitting fresh fixture writers. This does not accept the real host boot/start guard.
9. Crash at each typed-operation intent/readback boundary; recover actual state without a false complete result. Competing grant changes block restoration without overwrite.
10. Restore exact grant/authentication metadata, preserve protected data and accepted unrelated deltas, verify repeat readback, and retire only owned resources after evidence retention.

Each denial requires an authenticated live owned session, an existing fixture object where applicable, finite statement-specific privilege errors and unchanged intended data. Syntax/authentication/missing-object/connection errors, lock timeouts, dead writers or unexplained counters are failures, not proof of fencing. All ten cases and required regressions must pass with zero skips/incomplete results. Partial failure remains explicit evidence.

## Publication and remaining gates

Publish generic implementation/tests/docs to the user's fork only after native acceptance, scoped review, protected-value scanning and exact tested/published byte comparison. Keep source identities, runtime archives/manifests, fixture credentials, SQL originals, transcripts and operational profiles private. Report author-only review accurately if the preserved inline boundary excludes independent agents.

A green SQL experiment does not install a real source adapter or accept panel suspension, scheduler/process/ingress controls, callbacks, target exclusion, source-root authority, signer/trust, actual-cohort duration, full migration storage, financial openings or handover. These remain separate operational gates. The first executable increment must stay useful even when it proves the selective approach infeasible.
