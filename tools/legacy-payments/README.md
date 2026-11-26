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
