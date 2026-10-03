# Native ResellerClub lifecycle

Paid native invoice items must provision or renew domains asynchronously after the billing transaction commits. Generic server extensions retain their current behavior. The registrar confirms the exact domain, order, customer, status and expiry before Paymenter activates a service or changes its due date.

Use an encrypted durable operation journal with unique identities for customer, contact, domain registration and each paid renewal item. Persist a claim before any provider POST. Unknown results permit read-only reconciliation, never blind resubmission. Claims cannot be erased by an outer transaction. Match the current native server, owner, invoice, term, currency and account configuration at execution time. Migration holds and a default-off lifecycle switch must prevent all writes.

Persist the native paid-service claim before queue dispatch. An unresolved paid claim blocks another renewal invoice, credit charge, suspension or termination even before the worker runs. Complete it atomically with confirmed provider expiry; completed callback replays cannot reopen the billing block. Persist a configuration fingerprint on customer/contact/domain claims so a credential or server configuration change between executions requires intervention. Renewal reconciliation requires both original bindings to remain present and equal.

Provide native checkout fields for a domain and a complete registrant contact. Check availability before payment, and again before registration. Standard registration prices must never buy premium, early-access, trademark-claim or restricted domains. Initially support ordinary generic-contact ASCII registrations; IDNs and restricted registry contact profiles remain unavailable. Catalog registration and renewal amounts remain separate; Paymenter drives renewals using paid native invoices and provider auto-renew is off.

Opt-in paid-lifecycle checkout details must not be bound to browser URLs or initialized from contact-bearing URL parameters. They remain native form/cart state. Ordinary checkout retains its existing public configuration URL behavior.

Keep imported resource bindings and negotiated service prices unchanged. No live registrar writes, customer mail, billing handover or production activation during preparation. Use synthetic fixtures and a demo account for acceptance. Publish generic implementation and tests without private configuration.
