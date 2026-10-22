# Offline legacy source audit

Python 3.11+, standard library, POSIX private filesystem only. No installation or dependencies. This first increment accepts **declared fictional data only**, replays it in memory, and reports record changes and exceptions. It does not connect to a database, invoke a callback, send mail, contact a provider, ingest receipts, post money or change migration holds. Do not use a fictional label as proof that real source data was validated.

The only profile is compiled `fictional-v1`; its session/schema/engine facts are synthetic. There is no live adapter, dynamic profile, application bootstrap, scheduler or credential input. Generated SQL is a reviewed candidate read surface, not qualified native SQL. Every payment remains `unqualified`; all five provenance/financial checks stay `not_evaluated` regardless of status.

Run the entire tool suite from the repository root:

```sh
python3 -W error -m unittest discover -s tools/legacy-payments/source-audit/tests -p 'test_*.py' -v
```

Create an owned fictional example (the test utility only generates data files):

```sh
task_audit_demo=$(python3 -c 'import pathlib,tempfile; print(pathlib.Path(tempfile.mkdtemp()).resolve())')
python3 - "$task_audit_demo" <<'PY'
from pathlib import Path
import sys
sys.path.insert(0, 'tools/legacy-payments/source-audit/tests')
from fixtures import make_demo
from models import SystemClock
make_demo(Path(sys.argv[1]), SystemClock())
PY
python3 tools/legacy-payments/source-audit/audit.py --descriptor "$task_audit_demo/descriptor.json" --fixture "$task_audit_demo/fixture.json"
```

Exit 0 means only that this **fictional** collection was complete and evidence finalization succeeded. Exit 2 means refusal or an incomplete collection. Errors use fixed aliases; incomplete collections may emit a bounded summary. Detailed rows remain in the private output directory, never terminal logs. Modes are 0700 for private directories and 0600 for files. No symlink traversal, overwrite, implicit retry or automatic baseline advance.

The descriptor authorizes at most 30 minutes and binds a fixed, sorted migration cohort, profile hashes and output root. It contains no credentials. Its original file bytes are rechecked during authority use; changing it revokes the loaded attempt. The exclusive attempt directory consumes its ID before transport open, including after failure or lost response. Retain failed attempts; the tool never deletes them.

All five datasets are swept in the same simulated snapshot, including old payment IDs. Counts, ordered pages, exact schema/identity, ownership/references, final empty exhaustion, rollback and close must agree. A source ID or timestamp is not an incremental watermark. Missing records are reported as absent from scope; deletion, reassignment and reversals are not inferred. Original and canonical decimal strings are retained without float conversion. The fictional profile declares monetary columns DECIMAL(18,4); the scalar parser supports independently specified provider precision. Actual source precision is not established here.

Limits: 2,048 accounts; 4,096 subaccounts; 50,000 payments; 256 methods/currencies each; 256 rows per page; 32 MiB raw business frames; 4 KiB canonical row; 64 KiB metadata/diagnostics; 33 MiB input fixture. Deadlines are 10-second setup, 3-second statements, 30-second snapshot-through-rollback and 10-second additional cleanup. Private output is capped at 64 MiB per artifact and 128 MiB total. Unsupported primitives, capacity/identity/authority loss and uncertain cleanup fail closed. Read-only access can still consume resources and metadata locks once a real adapter is separately qualified.

For a later fictional comparison, supply a **new** descriptor file with a fresh attempt ID and `previous_ref` copied from the successful summary's `evidence`. Keep the fixed cohort/source/profile binding. A previous reference needs its manifest hash and random completion token, emitted only after successful durability checks. A candidate manifest or a valid hash alone cannot promote failed evidence to a baseline. Clock history is shared across every stage and stays revoked after rollback. Expiry/revocation retains available incomplete rows and cleanup facts in a private failure manifest, with no completion token or further source read. Database and socket identity are part of the previous-audit binding. Canonical decimal values are stored in the manifest-covered private analysis alongside original collection values.

The token acknowledges local artifact finalization only; it grants no human, native, provider or financial authority. A lost response is not permission to retry or reconstruct that token.

Review [the approved specification](../../../docs/superpowers/specs/2026-10-20-legacy-source-audit-design.md) and [implementation plan](../../../docs/superpowers/plans/2026-10-20-legacy-source-audit.md). Native database fixtures, actual schema/runtime/driver/cancellation qualification, a source reader/export packet and any financial application remain separate later work. BILLmanager remains billing authority until explicitly approved handover. Do not include real rows, descriptors, operational paths or keys in the maintained fork.
