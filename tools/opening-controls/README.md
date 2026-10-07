# External opening control rehearsal

This is an external operations toolkit, not a Paymenter extension. Python 3.10+ and its standard library are sufficient. It installs no routes, workers, schedules, trust or real signing keys. The application never invokes it.

`custody.py` retains original bounded POST bodies, ordered headers, path/query and every delivery in an operator-owned `0700` directory with a `0600` SQLite journal. Transactions use FULL synchronization; a receipt is returned only after commit and filesystem synchronization. Authenticated Authorize.net notifications are deduplicated by notification ID and original body hash. Conflicting bodies remain quarantined; every retry remains in the arrivals table. Receipt acknowledgement means delivery, never settlement. No provider call, financial application or automatic replay exists.

Supply the separately configured Authorize.net Signature Key as decoded 64-byte data. It is never generated, loaded from the app or logged by this library. [Authorize.net documents HMAC-SHA512 verification and HTTP 200 delivery acknowledgement](https://developer.authorize.net/api/reference/features/webhooks.html). An accepted ingress can respond 200 only after `delivery_acknowledged=True`. Errors must prevent acknowledgement. Storage failure after commit is recoverable by redelivery; idempotency retains one logical event.

Other provider requests can be retained with `retain()` but cannot produce a delivery acknowledgement. Their actual source integration authentication, retry semantics and legacy reference ownership must be accepted separately. WebMoney prerequests must deny new payments while frozen: [the Merchant Interface requires YES to permit checkout](https://en.webmoney.wiki/projects/webmoney/wiki/Web_Merchant_Interface). The disposable listener returns NO and retains the request. Its HTTP responses are rehearsal behavior, not deployment configuration. Do not infer the Klarna acquirer webhook protocol applies to a hosted checkout integration.

`control.py` implements persistent **rehearsal-only** state, exact coverage of three fixture boundaries, increasing sequence, expiry and Linux boot/PID/start-time observation. A missing/changed gate or lost operator latches denial. Neither controller exit nor permit expiry removes gates. Explicit fixture release requires observed original operator exit. Permits have purpose `opening-control-rehearsal-permit` and `real_execution_ready=false`; they cannot satisfy Paymenter's native signed fence schema. Fixture gate files enforce only cooperating disposable writers, not arbitrary processes or database clients.

Run local focused tests:

```sh
python3 -m unittest discover -s tools/opening-controls/tests -v
```

Two tests require root Linux and are skipped elsewhere. Native acceptance must run outside the app in a network-isolated root process:

```sh
unshare --net python3 -m unittest discover -s tools/opening-controls/tests -v
unshare --net python3 tools/opening-controls/rehearsal.py --evidence /ABSOLUTE/PRIVATE/DIRECTORY
```

The evidence directory must already be owned by the operator with mode `0700`. The runner creates only a unique `/dev/shm/opening-controls-rehearsal-*` directory, disposable SQLite ledger, Unix-domain callback listener, fixture key and owned subprocesses. It requires 1 GiB root and 512 MiB RAM reserve. It tests recurring source/target writers, late/duplicate/conflicting callbacks, unsupported aliases, denied WebMoney checkout, controller SIGKILL, expiry, fresh-process renewal and operator exit. It stops only subprocesses it created, retains optional fixture evidence and removes its owned RAM directory. It never imports Paymenter or opens its database socket.

This increment is **not a live freeze implementation or live acceptance**. Before genuine signed lease issuance, separately implement and accept persistent source scheduler/process/CLI/SQL controls; scoped canonical/direct/alternate ingress enforcement; target writer exclusion; provider-specific late-payment custody/reconciliation; MariaDB-version-matched lock-connection rehearsal; external signer isolation; exact manifests and actual-cohort timing. Preserve source billing until separately approved handover. Never install these fixture gates as a substitute for those controls, or acknowledge an event based only on its presence in custody.
