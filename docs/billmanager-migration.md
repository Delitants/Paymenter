# BILLmanager migration preparation

The migration foundation validates a scoped export and provides encrypted archival,
stable source mappings and operational holds. Native customer, ticket, service and
financial import stages are still being implemented. `--apply` currently refuses
to write records. Do not enable destination billing during preparation.

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
embedded in application code. Omitting `--apply` defaults to validation. Future
apply operations also require the destination database to appear in the
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
