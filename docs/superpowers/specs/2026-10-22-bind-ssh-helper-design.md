# Native BIND management through restricted SSH

Status: owner approved the written design on2026-10-22; implementation and installation have not started. The implementation plan is a separate review artifact.

## Outcome and scope

Paymenter will manage customer DNS records on an existing BIND primary, with an explicitly configured BIND secondary. It will use a dedicated SSH identity whose only permitted command is a small server-side helper. Existing nameserver software and zone ownership remain authoritative. This integration does not require DNSmanager.

Initial scope is the existing zones whose account, DNS service and domain identity have all been verified independently. Unmatched zones remain unbound. Customer service holds and administrator-only access remain effective throughout migration preparation. BILLmanager keeps billing until the owner approves handover.

The reusable extension, helper, tests and documentation belong in the owner's Paymenter fork. Deployment hosts, customer domains, service/account identifiers, host-key pins, credentials and private discovery evidence are configuration or private operational records, never source-code defaults.

## Chosen approach

The owner selected the recommended restricted SSH helper. One transport supports records and controlled zone lifecycle. TSIG-only updates would not provide the required zone creation and secondary configuration; adding another control channel would increase operational complexity for this installation. The extension will not receive unrestricted root SSH or reuse an operator's private key.

## Components and configuration

A native Paymenter `Others/Bind` extension supplies administrator configuration and client DNS screens. A small transport service invokes the fixed SSH command with structured JSON over stdin and parses bounded JSON responses. A root-owned helper on each nameserver owns zone-file validation and BIND control operations. A common protocol version connects the components; incompatible versions refuse changes.

Administrator configuration supplies primary and secondary SSH endpoints, dedicated key references, pinned host keys, an authoritative nameserver set, and supported TTL limits. Private key material is encrypted at rest and materialized only to a process-private temporary file when the SSH library requires one. It is never returned to browser/API clients or written to logs. Key files are deleted on all ordinary completion/failure paths; crash recovery removes only ownership-marked, expired integration files.

Server configuration supplies fixed canonical zone/config/backup directories, fixed installed command paths, protected-zone suffixes, operation bounds and an explicit zone-binding allowlist. Configuration is root-owned and non-writable by the SSH account. Neither Paymenter nor a request may choose filesystem paths, executables, BIND configuration snippets or nameserver service units.

## SSH permission boundary

Create a dedicated non-administrative SSH account and a new key pair for the Paymenter integration. The operator's Mac key stays on the Mac. Keep separate integration keys for staging and production. The host's authorized key forces a root-owned dispatcher, disables PTY, agent/X11/port forwarding and arbitrary commands, and restricts source addresses where deployment networking permits it.

The dispatcher accepts only the literal protocol command and invokes one fixed helper through a narrowly matched sudoers rule with no user-supplied arguments. The helper reads one bounded JSON request from stdin. It has no shell command mode, SCP/SFTP, arbitrary file reader, package-management command or generic `rndc` interface. Validate the sudoers file and actual forced-command denial before declaring the channel restricted. Removing the authorized key revokes remote access without changing BIND service state.

Strict pinned host-key verification is mandatory; missing or changed host keys refuse connection. SSH host-key trust is installed by an administrator independently, never through trust-on-first-use during a customer action.

## Ownership and admission

Paymenter stores an immutable binding UUID, canonical zone name, customer account and DNS service relation. Binding approval requires an independently verified exact domain/account/service match. Legacy numeric IDs and descriptive service labels alone cannot establish ownership. Existing migration holds are consulted before every client read or write; disabling the extension or placing the service on hold immediately blocks customer operations.

Each helper's root-owned allowlist maps a binding UUID to exactly one canonical zone, role and permitted operations. The request cannot add or change allowlist entries. Allowlist installation is an administrator operation, separate from customer record changes. Protect infrastructure and reverse zones both through an explicit denylist and by their absence from the allowlist; a denylist match wins. The initial adoption set is private deployment data and must not expand implicitly to all discovered zones.

Client authorization is checked by the native Paymenter policy on every request, including shared-account rules. An authenticated client cannot submit another service/binding UUID successfully. The helper independently enforces its allowlist even if a caller constructs JSON directly. Service deletion, suspension or billing events do not delete a zone in version1.

## Version1 protocol

All requests contain protocol `paymenter-bind-v1`, a UUID request ID, a UUID binding ID and one closed operation name. Reject unknown/duplicate JSON keys, malformed UTF-8, trailing input, unsupported protocol versions, more than32KiB stdin and oversized responses. A request cannot choose the SSH host, zone pathname or command.

Supported operations:

- `inspect`: return the allowlisted zone's editable records, role, loaded serial and an opaque revision hash, with bounded response size. Read-only inspection does not adopt or create a zone.
- `replace_records`: change a bounded set of supported RRsets in an already allowlisted primary zone. Require the expected serial and revision hash; a conflict returns a fresh-read requirement before any write. Changes to apex SOA and apex NS are rejected.
- `prepare_zone` and `prepare_secondary`: administrator-only creation of a pre-allowlisted, absent zone using the server-owned SOA/NS template and primary/secondary settings. No arbitrary zone-file/config text. Existing files or duplicate declarations refuse creation.
- `inspect_request`: return a prior request's durable outcome without executing it again.

No remote delete, wildcard command, arbitrary query or all-zones listing is exposed. A repeated request ID with the same payload returns its retained outcome; the same ID with different bytes is rejected. An uncertain client transport result is reconciled through `inspect_request` and an independent zone read; it never triggers an automatic resubmission with a new ID.

Request bounds and helper execution limits are server-side configuration with conservative shipped defaults: at most100RRsets changed per request,500records total per managed zone, 2MiB zone file, 30seconds ordinary operation and 60seconds lifecycle operation. Refuse an oversized existing zone; do not truncate it. Bound helper child commands and retire only its own process group on timeout. Transport timeout does not imply that a server-side mutation failed.

## Records and zone parsing

Version1 edits A, AAAA, CNAME, MX, TXT, CAA and SRV RRsets. Name validation canonicalizes IDNA to ASCII, requires zone-relative or in-zone absolute owner names and rejects control characters and path components. RDATA uses typed DNS parsing, not string concatenation. Enforce valid addresses, numeric field ranges, TTL limits, CNAME exclusivity and no apex CNAME. TXT encoding preserves arbitrary valid text without generating BIND directives. Unknown existing RRsets remain semantically unchanged.

A real DNS zone parser must read and regenerate complete supported static zones. Detect and refuse dynamic-update journals, inline signing/DNSSEC-managed zones, `$INCLUDE`, `$GENERATE`, unsupported constructs and external files. Version1 does not freeze/thaw dynamic zones, resign DNSSEC zones or modify their keys. Preserve all unrelated RRsets and SOA fields except the serial. Compare semantic before/after RRsets to the intended patch before installation. Formatting/comments may be normalized; the exact previous zone bytes remain in the protected backup.

Use RFC1982 serial arithmetic to select a serial newer than the loaded and on-disk serials, including wraparound. Never infer that a serial is newer from ordinary integer comparison alone. If current file and loaded state disagree unexpectedly, refuse before changing anything.

## Atomic primary changes and rollback

Acquire a per-zone lock plus a configuration lock when declarations change. Re-read allowlist/config identities and actual serial/revision under the lock. Write a durable attempt record and exact protected preimage before mutation. Create a new candidate exclusively in the same filesystem as the destination, with safe owner/mode and no symlink traversal. Use descriptor-relative paths inside the fixed directories.

Validate the candidate with installed `named-checkzone`; for a lifecycle declaration validate a complete candidate config with `named-checkconf` before installation. No unvalidated content becomes live. Fsync the candidate, replace the exact destination atomically, fsync its directory, then issue only the required zone reload/reconfiguration and notification through fixed argument arrays. Verify loaded primary serial and exact intended RRsets. Persist a durable commit receipt with request hash, binding UUID, before/after hashes and observed serial. Audit logs omit secrets and full customer zone contents.

If installation occurred but reload/readback fails, classify the result as uncertain and retain all artifacts. An automatic rollback is permitted only while the lock is held and the exact current file is still the installed candidate: build a fresh validated rollback candidate restoring prior RRsets with a serial newer than every observed serial, then reload and verify. Do not restore the old serial or overwrite a concurrently changed file. Failed or unverifiable rollback remains visibly uncertain and blocks further writes pending administrator reconciliation.

## Secondary lifecycle and convergence

For an existing primary zone, preserve existing secondary declarations and transfer policy. For a new zone, stage and validate the secondary declaration and explicit primary-to-secondary transfer/notify policy before exposing the zone as ready. Transfer permissions are confined to the configured peers; no open AXFR is added. Keep existing authentication requirements; a separately configured transfer key, if used, remains server-only and is unrelated to the SSH identity.

Record-only changes mutate the primary once. After notify, poll the configured secondary with bounded read-only SOA/RRset checks. A delayed secondary is shown as `pending replication`, with the actual primary success recorded. Read-only convergence checks may repeat; they cannot reapply a mutation. A newly created zone is ready only after primary authority and matching secondary state are independently observed.

Creation across two servers is a journaled sequence, not a distributed atomic transaction. Partial creation remains an administrator-visible incomplete state; retain which declarations/files were created. Do not delete an existing or externally modified zone to simulate atomic rollback. A separately reviewed cleanup may remove only exact newly owned artifacts after verifying no subsequent changes.

## Paymenter behavior

Client screens show eligible owned zones, supported records and clear validation/conflict messages. A successful primary write with delayed replication says the update was saved and is reaching the secondary; do not confuse nameserver replication with recursive DNS cache TTL. Show stale revision and uncertain-operation states distinctly, preserving a request ID for administrator investigation.

Existing billing, service provisioning and termination remain unchanged. During migration, bindings and customer access stay disabled. Administrator inspection may operate read-only. The new extension has an independent enable switch and operation guards; enabling a generic provider extension never releases migration holds.

## Evidence and acceptance

Unit/integration tests exercise malformed requests, unknown operations, absent bindings, another customer's binding, protected zones, SSH command/forwarding denial, host-key mismatch, path/symlink substitution, concurrent revisions, serial wraparound, RDATA validation, preservation of unknown RRsets, parser refusals, process timeout, uncertain responses and rollback/secondary failure.

Native acceptance uses only one expressly designated disposable test zone, absent from current customer and infrastructure zones. Verify primary create/edit/readback, secondary transfer/convergence, denied cross-zone requests and rollback behavior. Keep exact customer zone files/configurations and loaded serials unchanged outside that test scope. Synthetic tests alone do not qualify installed SSH restrictions, real BIND reloads or transfer behavior.

Before customer activation: independently approve and install exact zone bindings, install dedicated keys/helper, verify native restrictions and test-zone behavior, retain fresh recoverable configuration backups, verify client ownership/hold behavior and obtain the separate billing handover approval. Any failed gate stays explicit.

## Review checklist

This design has no unresolved architecture choice: restricted SSH was selected. Deployment paths, dedicated key placement and exact zone adoption are deliberately private installation inputs, not source-code placeholders. No implementation, installation, DNS write, customer binding activation or handover has occurred through this design document.
