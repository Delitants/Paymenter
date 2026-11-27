# BILLmanager legacy payment delivery

The BILLmanager payment writer must insert an effect and its original journal observation in the same transaction as the payment. `journal.sql` defines the four source tables. Give the dispatch account read access to the effect and journal, and select, insert, and update access to the two dispatch tables.

`native/journal_writer.hpp` and `native/journal_writer.cpp` use the BILLmanager SDK connection already enlisted in the payment transaction. Construct `JournalTransaction` after the native transaction has started and before the payment write. Append one observation after the native callback validates the payment and establishes its economic facts. The returned journal ID is pending until the caller commits. The caller must roll back the original transaction if recording fails.

Run `python2.7 tools/legacy-payments/dispatch.py --help` for the dispatcher options. Provide a private MariaDB options file, signing key and SSH identity, and a pinned target host key. Run the dispatcher periodically with the same signing key while its observations are pending. Preserve the journal and dispatch tables so attempts and acknowledgements survive a restart.

On Paymenter, configure the dedicated SSH key for the fixed request `receive-legacy-payment`. Its forced command runs `php artisan billmanager:legacy-payment:receive --no-interaction --no-ansi` from the intended release. Disable PTY allocation and forwarding for the key, disable console telemetry, and enable the protected legacy receipt trust for the intended source. The receiver reads one frame from standard input and returns one JSON line.

`stored_pending` confirms custody. Financial results are available once Paymenter's separate posting process commits them. After a signature expires, the dispatcher can request the status of the identical retained observation while the original trust policy remains in force. Automatic checks stop at the configured attempt limit. Exhausted, invalid, expired and quarantined deliveries remain in the source dispatch records for review.

## Keep original callback arrivals

Run `python2.7 tools/legacy-payments/custody.py --help` for the retention and verification options. Use an existing private directory with mode `0700` and supply explicit record, byte and free-space limits. Keep the returned sequence and receipt hash in a separate protected record for later verification.

The `retain` command reads the `PAYMENTER-LEGACY-CUSTODY-V1` line, followed by four lines giving the decimal byte lengths of the method, request target, ordered header block and body. The exact original bytes follow in that order. A successful command records custody with `delivery_acknowledged` set to `false`. Authentication, provider responses and settlement require the provider-specific handler. An interrupted write leaves its pending file available for review.

Use `webmoney_receipt.authenticate` to check a retained WebMoney result against the original source payment and the configured merchant key. Confirm the returned transaction reference with WebMoney before using it for settlement.

Use `authorizenet_receipt.authenticate` with the original callback target, webhook ID and merchant signature key to verify a retained Authorize.net payment notification. Preserve both the merchant reference and provider transaction ID. Confirm ownership in BILLmanager and current transaction status at Authorize.net before settling anything. A verified refund or other notification may have no merchant reference and needs separate reconciliation.

## Keep Authorize.net notifications before native processing

Run `python2.7 tools/legacy-payments/authorizenet_ingress.py --help` for the collector options. Configure the original notification path and webhook ID, a private file containing the merchant signature key, and the custody directory. The collector requires no database access.

Proxy signed POST requests on that path to the collector through a private socket. Keep request buffering enabled and upstream retries disabled. Route `@paymenter_legacy_authorizenet_native` to the existing handler so it receives the original method, path, headers and body. The collector records a verified notification before nginx calls the handler. A custody failure returns HTTP 503 so Authorize.net can retry. Other requests retain the original handler's behavior. Use the recorded notification and provider settlement evidence for reconciliation.

## Route WebMoney callbacks through the cutoff

Run `python2.7 tools/legacy-payments/webmoney_ingress.py --help` for the receiver options. The receiver accepts one buffered nginx request on a private socket. Give its MariaDB account read access to `migration_route_state`, `migration_route_accounts`, `payment`, `paymethod`, `subaccount` and `billorder`.

Proxy the exact WebMoney result location to the receiver. Keep request buffering enabled and disable upstream retries. Route `@paymenter_legacy_webmoney_native` to the existing native handler with its original proxy settings. nginx handles this internal redirect using the original request. The receiver records the exact body and ordered headers it receives from nginx.

While the cutoff is armed, known retained accounts continue through the native handler. A result for a retired account is retained and returns HTTP 503 to defer acknowledgement. A database read or storage failure also returns HTTP 503 and records a fixed error in the service log. Before arming, verify that WebMoney prerequests include the payment number and that callbacks reach the gate through the configured result URL. Settlement still requires verification and posting.

## Forward new Paymenter callbacks from a shared merchant endpoint

Once the Paymenter endpoints are ready, route the internal nginx locations `@paymenter_authorizenet_target` and `@paymenter_webmoney_target` to their respective fixed Paymenter notification URLs over verified HTTPS. Preserve the original POST body and pass `X-ANET-Signature` for Authorize.net. Propagate the destination's response.

Enable `--forward-paymenter` on the Authorize.net collector to forward authenticated capture events with the Paymenter checkout reference. For WebMoney, provide both `--paymenter-purse-file` and `--paymenter-secret-file` for the destination merchant. Paymenter receives its prerequests and authenticated results. The collector preserves authenticated results before asking nginx to forward them.
