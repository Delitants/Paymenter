# BILLmanager migration preparation

The migration foundation validates a scoped export and provides encrypted archival,
stable source mappings and operational holds. The customer stage creates native users and shared-account membership under
billing and login holds. Ticket and attachment stages preserve native support history and a staff-only archive. Financial history preserves exact amounts and source states. Service and integration stages are still being
implemented; the default full `--apply` remains unavailable. Do not enable destination billing during preparation.

Export only approved accounts using `tools/billmanager/export-selected.py`. Run it
on the source with existing local database access. It reads the authentication log
and executes its database queries in one consistent InnoDB snapshot. The cutoff
is inclusive and uses timestamps as recorded in that log; verify the source time
zone before selecting a cutoff. Passwords, TOTP secrets and merchant credentials
are intentionally excluded.

Stream the output to a private destination outside the web root. Restrict both the
JSON file and its adjacent `.sha256` file to the operator. The sidecar must contain
only the lowercase SHA-256 digest of the JSON bytes. Do not commit exports,
credentials, source identifiers, deployment addresses or reconciliation reports.

Example validation with a documentation-only source address:

```sh
php artisan billmanager:import /private/snapshot.json \
  --source=192.0.2.10 --login-cutoff='2024-01-01 00:00:00' \
  --dry-run --report=/private/report.json
```

The expected source and cutoff are explicit inputs; no deployment identity is
embedded in application code. Omitting `--apply` defaults to validation. Apply operations also require the destination database to appear in the
`BILLMANAGER_ALLOWED_DATABASES` environment setting.

Use a separate filesystem and database for rehearsal, a database account without
production grants, non-delivering mail, separate cache/session stores and no
scheduler or queue worker. Restrict network egress during import tests. Never run
`RefreshDatabase` tests against a production database.

Migration holds protect service lifecycle jobs, billing helpers, renewal,
payment processing and custom service actions. Scheduled tasks skip held records.
A user hold also covers that user's services and invoices, including new invoices.
Import persistence bypasses observers intentionally; this is not a mechanism to
bypass holds for ordinary application operations. Keep all imported accounts held
until reconciliation, provider checks and the billing handover are approved.

The source mapping key is `(source_host, source_table, source_id)`. Native writes
and mappings must share a transaction. Source payloads are encrypted with the
destination application key, preserving exact decimal strings. Replaying the
same mapping is allowed; remapping an existing source identity is rejected.

Run the migration tests against the isolated test database:

```sh
vendor/bin/phpunit tests/Feature/BillmanagerMigration tests/Unit
```

Also run native login, logout and checkout regressions. `EndToEndTest` runs the
customer, credential, service, ticket and financial CLI stages from one synthetic
snapshot. It injects a failure after financial writes, verifies that stage's
transaction rolls back without losing earlier completed stages, then verifies
stable replay, original amounts, shared identities, OTP preservation and no mail,
jobs or provider requests. This is staged recovery, not an all-stage transaction
or a substitute for live provider acceptance.

Browser acceptance must use synthetic accounts in a separate application,
database and non-delivering environment. Verify legacy username/password login
with and without OTP, full archived messages, shared-account history, private
attachment downloads, unrelated-account denial and permission-scoped staff
archives. Logout uses a CSRF-protected POST route so it remains available even
when the current resource's authorization middleware denies access. Real customer
credentials and mail settings must remain in private destination storage.

To rehearse only customer identities, explicitly use `--stage=customers --apply`.
This does not transfer credentials or enable billing. Repeating the same snapshot
preserves native identities. Existing normalized-email collisions require an
explicit reconciliation; they are never merged automatically. Shared account
members get read access, while mutations still require the owner's permission.

Credential migration is a separate protected operation requiring operator
authorization. `export-credentials.py` accepts only a selected user-ID list on
stdin; pipe its output directly to `receive-credentials.php` on the destination.
The receiver encrypts before writing a private file outside the application
directory. Never send this output to a terminal or ordinary local file. Compatible
legacy hashes upgrade on successful authentication, and a password reset disables
legacy fallback. Required MFA must be preserved; unsupported or missing factors
leave login blocked. No credential payload belongs in this repository.

Install an authorized encrypted bundle with `--stage=credentials --apply
--credential-bundle=/private/credentials.enc`. Every bundle identity must match the
selected snapshot. Legacy usernames remain login aliases alongside email, sharing
the account's rate limit. Alias collisions require explicit reconciliation. OTP
is preserved when compatible; the explicit `--disable-unsupported-otp` option is
available only for an operator-authorized fallback and records an audit event.
Replaying the bundle never reinstates a password after a native password change.

`export-mail-settings.py` and `receive-mail-settings.php` transfer SMTP settings
directly into encrypted destination storage and application settings. The receiver
requires an allowed database and keeps delivery disabled. `verify-mail.php` checks
TLS/authentication and disconnects without sending mail. Keep the rehearsal mail
transport non-delivering until the approved deployment and communication phase.

After customers, use `--stage=tickets --apply`. Historical timestamps require an
IANA region in the snapshot's `source_timezone`, or an explicit
`BILLMANAGER_SOURCE_TIMEZONE` environment value. Original timestamp strings remain
in the encrypted archive. Historical staff names do not create staff logins.
Deleted messages and internal notes are visible only in the permission-scoped
administrator archive. Imported tickets stay read-only until support handover.

Build an attachment manifest from the same snapshot. Each entry has `id`,
`account`, `ticket_message`, `filename`, account-relative `path`, integer `size`,
lowercase `sha256`, and `original` containing the exact source attachment row.
Use `stream-attachments.py SOURCE_ROOT` on the source with the manifest on stdin,
piped directly to `receive-attachments.py MANIFEST DESTINATION_DIRECTORY` on the
destination. These tools validate account containment, file sizes and hashes;
shared physical files are transferred once while each attachment record survives.
The receiver can safely resume a partial transfer when existing hashes match.

Apply `--stage=attachments --apply --attachment-manifest=MANIFEST
--attachment-directory=DESTINATION_DIRECTORY` against the same snapshot. Visible
messages receive native attachments. Files belonging to deleted messages remain
available to authorized support staff from the archive, never through a customer
ticket. The importer validates every source file before writing and removes its
newly created files if its database transaction fails. Run as the application
storage owner, or explicitly give that owner access to the resulting private files.
Do not expose the attachment directory through a public storage symlink.

Use `--stage=financial --apply` after customer identities. This creates an exact
financial history accessible to account members through Billing history, with
payment states kept separate from accounting document states. It preserves
original receipt numbers, external references, payer/issuer profiles, balances,
credit limits, fees, taxes and audit revisions. Preliminary accounting documents
remain hidden from customers. Source decimal strings and computed rounding
differences are retained; preparation does not create spendable credit.

Native paid invoices are created only when confirmed payments in the same currency
fully cover matching invoice lines, amounts fit native precision, the issuer is
known, and zero-tax treatment is proven. Other accounting documents stay available
with their truthful source state in Billing history; no payment is invented to
make a document look paid. Historical native invoices are held, and original
transaction references represent their documented allocations without submitting
new payments. The report explains why documents remain history-only. Replaying
the same snapshot verifies records and creates no duplicate invoice or credit.

Use `--stage=services --apply` to prepare native services after customer identities.
Each imported service has a private negotiated plan, a hidden product with no
orderable stock, and its own billing/provider hold. The original status separates
ordered and processing records even though both map to Paymenter's pending state.
Lifetime periods become non-recurring plans. The service screen retains the exact
source renewal amount and selected account/domain/network details.

The source `item.cost` is a calculated renewal total; addons and discounts must not
be applied a second time. Matching-period billed expenses provide reconciliation
evidence, while fractional cents, changed periods and unmatched amounts remain
explicit review reasons. Exact source amounts, discounts, catalog rows and addon
selections are encrypted in the archive. Native two-decimal amounts include a
recorded rounding delta. Tax and discount policy must be reconciled before removing
the service hold. Replaying the service stage rejects changed ownership, price,
status, plan or catalog rather than silently overwriting a prepared record.

Existing Proxmox resources can be attached with `--stage=proxmox --apply
--proxmox-servers=/private/proxmox-servers.json`. The file contains `source_host`
and `servers_by_module`, an explicit mapping of source module IDs to native
Paymenter server IDs. Install the corresponding Proxmox extension with
`getResourceInventory()` and current-node identity validation first. Attachment
requires both an identifier and hostname match, rejects duplicate assignment,
checks each resource through live GET requests, and never creates or powers a VM.
An attached source processing record remains pending. Other providers and manual
services require their own reviewed resource bindings.

Existing IP reservations are inspected and archived without changing ownership.
Missing/stale owners or hostname disagreements are reported for reconciliation;
they never authorize freeing or replacing an address. The Proxmox stage needs
read-only network access; the service stage and verification can run without it.
`verify-services.php SNAPSHOT SOURCE LOGIN_CUTOFF` checks native ownership, holds,
original rows, addons, parameters, source/native amounts and billing periods.
Provider lifecycle, console relay, IP ownership handover and all real write tests
remain separate acceptance gates.

Provider preparation has two explicit stages. `--stage=provider-configuration
--apply --provider-bundle=/private/provider-settings.enc` consumes a source-bound
encrypted bundle of allowlisted provider settings. It creates migration-owned,
disabled native server records with encrypted settings; repeat runs reject changed
credentials or configuration. Disabled source providers remain recorded dependencies.
The bundle must be delivered directly between the authorized hosts, outside Git.

`--stage=provider-accounts --apply --provider-servers=/private/providers.json`
accepts `source_host` and `servers_by_module` for reviewed ISPmanager or DNSmanager
bindings. The server must have been configured by this import. The stage matches
the exact source username, verifies active/suspended state, preserves the provider
owner and rejects duplicate bindings or later ownership drift. Existing source
services and their billing holds remain unchanged. This stage reads provider APIs
but never provisions or changes a remote account.

ISPmanager/DNSmanager currently expose authenticated native account status;
SSLStore checks credentials through its health API without ordering a certificate.
The Manual server identifies services requiring an operator. Automated lifecycle,
DNS-zone editing, registrar renewal and certificate ordering are not implemented
by these preparation adapters. Such operations raise explicit errors, including
on direct invocation; they must not be represented as a completed integration or
used for billing handover. DNSmanager uses the ISPmanager account-list contract
and still requires live acceptance against its particular installed version.

The companion ResellerClub preparation branch validates existing order, customer
and domain identity through the documented read endpoint and exposes supported
native status actions. Its unverified legacy lifecycle calls are unavailable.
Configuration, read-only connectivity, resource attachment, sandbox lifecycle
acceptance and public customer views are separate readiness gates.

`--stage=registrar-accounts --apply --provider-servers=/private/registrars.json`
binds existing ResellerClub domains using the same `source_host` and
`servers_by_module` mapping schema. Use a mapping containing only the reviewed
registrar modules. It requires the exact imported snapshot, mapped native owner,
preserved source service metadata and billing fields, active holds, hidden products
and disabled servers. The domain comes from the unique source `domain` parameter;
descriptive service labels are not registrar identities.

Each binding needs a current authenticated GET response with matching domain,
positive order/customer IDs, matching active/suspended state and at least one
matching nonempty source nameserver set or expiry date. Domain/order/customer
properties and encrypted provenance are immutable on replay. Duplicate bindings,
changed ownership or configuration, and local mapping conflicts reject the batch.
Expiry and nameserver differences are reported without changing billing dates or
DNS. Unverified records remain held with explicit review reasons; a batch with no
verified identities fails. Successful mixed batches report
`registrar_accounts_review_required`, so success is not an all-domains acceptance.

Use the same registrar stage with `--dry-run` to check current provider evidence
against an existing import without binding or provenance writes. The `attached`
count then means eligible verified identities. Generic dry-run behavior for other
stages remains snapshot validation only. This stage never places registrar orders,
renews domains, transfers domains, updates contacts or changes nameservers.

`export-provider-settings.py` takes the source identity, native `mgrctl` path,
manager name and repeated explicit module IDs as runtime arguments. It performs
only `processing.edit` reads without a submission flag and emits allowlisted
settings. Pipe it directly to `receive-provider-settings.php PRIVATE_OUTPUT
EXPECTED_SOURCE`; the receiver encrypts an exclusively created private file and
verifies readback, without changing native settings. Apply configuration separately
through the explicit provider stage after reviewing the source/module mapping.


### Refreshed support snapshots and delivery holds

Previously mapped ticket messages must still be present and match their original archived source rows, including content, author and deletion markers. Any edit, deletion or disappearance rejects the support stage transaction and requires explicit reconciliation. Do not deploy or release a held candidate based on a rejected refreshed snapshot; the earlier native message and its attachments remain from the prior accepted batch. No automatic deletion or visibility transition is performed.

Held recipients and held notification subjects suppress email, in-app and push delivery, including normal login notifications, without blocking successful credential authentication. Cancellation, upgrade and subscription cancellation reject held services before persistence or provider calls. Shared account membership grants read access only; write actions require the native update permission.
