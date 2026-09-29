# Payment gateway preparation

The migration installs native WebMoney, Authorize.Net, Klarna and Wave gateway records **disabled**, with `collection_enabled=0`. Every imported setting is encrypted. Source credentials and callback settings are retained in the encrypted migration archive. Existing source callbacks and synchronization jobs are not changed.

`billmanager:import ... --stage=gateway-configuration --gateway-bundle=/private/gateway-settings.enc --apply` requires the same source identity, cutoff and allowed database as the other stages. Currency IDs are resolved through the validated snapshot, including Authorize.Net credentials obtained from an external broker. Repeating the stage verifies existing settings and refuses changed or enabled records.

Stream credentials with `tools/billmanager/export-gateway-settings.py --source SOURCE --id ID [...]` directly over SSH to `tools/billmanager/receive-gateway-settings.php PRIVATE_OUTPUT EXPECTED_SOURCE` on the destination. Authorize.Net's optional `--authorizenet-broker PATH` uses the adjacent PHP tokenizer to read literal declarations without executing the broker. Never store the plaintext stream or include it in a repository.

## Payment records and verification

Native checkout binds the exact gateway record. A destination payment attempt records its owner, amount, currency, merchant fingerprint and an independent reference. Invoice and referenced service migration holds apply to initiation and settlement. Provider requests use verified TLS, bounded timeouts and redacted errors.

Only verified provider payment data calls Paymenter's invoice settlement hooks. Gateway and invoice row locks, a unique provider transaction key and persisted attempt state prevent repeated notifications from recording a second payment or renewing a service twice. Browser redirects never record payments. An uncertain creation response remains `initializing`; refreshing checkout does not submit it again. Expired hosted sessions require reconciliation rather than automatic replacement. Reconciliation tooling for uncertain requests remains an operator acceptance item.

- **WebMoney:** WMZ/USD and SHA256 `LMI_HASH` only. Prerequests validate destination reference, purse, amount and mode without recording payment. Held payments and unsupported escrow fields are rejected. Actual merchant test/live and hash settings must be verified before changing the imported safe test-mode default.
- **Authorize.Net:** Accept Hosted token is encrypted and reused for up to 14 minutes. Raw-body HMAC-SHA512 uses the merchant's signature-key string. A valid notification triggers `getTransactionDetailsRequest`; captured state, transaction ID, destination invoice reference and amount must match. This adapter supports a verified USD merchant account. `authenticateTestRequest` verifies API credentials, not hosted checkout or the webhook signature configuration.
- **Klarna:** Payments session plus hosted payment page in `CAPTURE_ORDER` mode for immediately delivered digital services. A per-session secret token protects the status callback, then authenticated session and order reads verify identity, accepted fraud status, exact full capture, currency and zero refunds. The importer leaves API region, purchase country and locale unset until verified. Taxed or partially paid invoices require explicit order-line allocation and are refused.
- **Wave:** Finds an exact, unique active customer by the invoice owner's email, or creates that customer. A durable business/user claim serializes creation across invoices and gateway records sharing that business. The provider customer reference is encrypted and cannot bind to two native users within one merchant. Unknown responses and changed local emails require reconciliation; a later checkout does not create another customer. Creates and approves a destination-numbered invoice; never calls invoice email delivery. Reuses the stored customer/invoice. Signed webhook timestamps must match and be within five minutes; merchant and provider-returned internal invoice ID must match before authenticated GraphQL readback verifies business, customer, destination number, currency and fully paid amounts. GraphQL IDs and webhook IDs are distinct; webhook business ID and signing secret require separate configuration. `wave:reconcile GATEWAY REFERENCE --apply` uses the same verification for explicit polling. No polling schedule is installed. Taxed or partially paid invoices require explicit allocation and are refused.

Customer creation requires an autocommit connection. Wrapping the provider call in
an outer database transaction is refused because rolling it back could erase the
claim after a remote customer was created. The claim has no automatic expiry or
takeover. An overlapping invoice stays open and can continue once the winning
customer binding is ready; an uncertain provider operation remains blocked.
Operator recovery for uncertain or changed bindings still requires acceptance.

## Acceptance still required

Synthetic tests do not prove a provider has accepted a merchant, market, hosted checkout, tax configuration or webhook. Complete provider sandbox payment tests, expired/uncertain-session operator recovery, currency and tax checks, callback delivery and public browser checks before enabling collection. Wave webhook availability and internal-ID correspondence require account-specific verification; its existing source poller remains authoritative during preparation. Its durable customer binding also needs merchant sandbox acceptance before activation.

Never release customer migration holds, activate gateway records, register live callbacks, enable delivery or start collection as a side effect of importing settings. Billing handover is a separate approved operation.

## Provider references

- [Authorize.Net Accept Hosted](https://developer.authorize.net/api/reference/features/accept-hosted.html) and [webhooks](https://developer.authorize.net/api/reference/features/webhooks.html)
- [WebMoney Web Merchant Interface](https://en.webmoney.wiki/projects/webmoney/wiki/Web_Merchant_Interface)
- [Klarna hosted checkout](https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/before-you-start/accept-klarna-payments-using-hosted-payment-page/) and [status callbacks](https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/api-documentation/status-callbacks/)
- [Wave API](https://developer.waveapps.com/hc/en-us/articles/360019968212-API-Reference) and [webhook verification](https://developer.waveapps.com/hc/en-us/articles/51070420388628-Webhooks-Setup-Guide)
