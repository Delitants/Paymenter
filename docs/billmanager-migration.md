# BILLmanager migration preparation

The migration foundation validates a scoped export and provides encrypted archival,
stable source mappings and operational holds. The customer stage creates native users and shared-account membership under
billing and login holds. Ticket and attachment stages preserve native support history and a staff-only archive. Service and financial stages are still being
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
