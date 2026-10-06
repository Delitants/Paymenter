# Native account funding

Keep the feature disabled pending separately approved installation, opening and handover acceptance. Isolated tests and generic source publication do not activate customer funding.

Account funding keeps deposited cash and borrowing limits separate. Managed accounts use a signed four-decimal wallet and immutable movement receipts. Other accounts retain ordinary deposit-only behavior.

`ACCOUNT_FUNDING_ENABLED` defaults to `false`. Schema installation creates no customer wallets, limits, credits, invoices or jobs. There is no production opening/apply endpoint or command in this subsystem. A source publication or isolated test result is not activation or billing handover.

## Balances and availability

For signed balance `B`, approved limit `L` and outgoing reversal reservations `R`:

| Value | Calculation |
| --- | --- |
| Exact outstanding debt | `max(-B, 0)` |
| Remaining borrowing allowance | `max(L - debt, 0)` |
| Deposited cash usable now | `floor(max(B - R, 0), 2)` |
| Account funding available | `floor(max(B + L - R, 0), 2)` |
| Excess debt | `max(debt - L, 0)` |

All calculations use exact decimals. Availability discards positive fractional cents. A deposit first repays debt; only the excess becomes usable cash. For example, a `50.00` deposit against `40.0050` debt leaves a balance of `9.9950`, `9.99` usable cash and `0.0050` retained fractional residue. Limits are never deposited funds. Fees and product tax are never deposit principal.

A managed account's native `Credit.amount` is a nonnegative projection. Direct Credit API/model/admin edits are rejected. A drifted projection, ambiguous receipt, inactive facility, migration hold or unresolved native posting blocks spending. If refund recovery currently owns an original payment receipt, statements stay readable but funding is briefly unavailable until a current receipt proof can be obtained without waiting back on its owner lock.

## Collection and receipts

The current invoice owner may fund an eligible purchase or renewal. Shared readers may see permitted account history but cannot spend. Account funds cannot buy another deposit. Automatic collection pays an entire invoice only when it fits the current capacity; it does not borrow partially toward an externally payable renewal.

Each internal allocation records the original invoice principal and exact cash/debt split. Checkout previews use that same signed-balance calculation and retain four decimals for the portions; refund reservations separately restrict eligibility. Reversing a partial allocation before the invoice is first fully paid still permits its first verified external payment and one fulfillment. It has no merchant transaction ID. Current captured product tax remains authoritative. External gateway fees apply only to the remaining external charge and stay untaxed. Replays do not repeat fulfillment.

A dedicated `admin.invoice_transactions.account_reverse` permission authorizes a partial or full linked internal reversal with a reason. General invoice edit permission does not. Reversal restores principal to the original wallet, reduces current debt before producing cash and cannot exceed the original allocation. It reopens only reversed invoice principal. Existing paid-processing and merchant receipts stay retained; service fulfillment is not repeated. Recollection preserves already collected fees and appends only the fee for the new external charge. New manual receipts freeze their principal pricing and fee identities; older receipts without that provenance require reconciliation.

Verified deposits and completed downgrades post once from their original native receipt identity. A receipt already reflected in an opening cannot become new income. A pending older invoice paid after that opening can still supply new verified income. Original financial ownership remains authoritative after a service transfer; a current identity conflict requires reconciliation.

## Outgoing deposits and reconciliation

Durably queued deposit refunds reserve their exact original principal before a provider write. Pending or uncertain outcomes retain the reservation. Verified failure releases it; verified success records one negative movement. Previously spent principal may create debt above the approved limit, which remains visible and blocks further funding.

If current holds, permissions or identity prevent posting an authenticated provider outcome, retain its original evidence and reservation and flag native posting as requiring reconciliation. Restoring current dedicated reconciliation authority permits posting the stored result without repeating the provider write. Do not convert verified success into failure or release reserved money to make a local conflict disappear.

Manual unsettlement and restoration use their dedicated settlement permissions, original payment identity and immutable operation receipts. They do not replay paid service processing. Account-funded allocations are not merchant receipts and cannot be refunded through a provider adapter.

## Statements and administration

Customer statements must keep cash, exact debt, limit, remaining allowance, available funding, fractional residue and reserved refunds separately labeled. Opening source identifiers and administrative audit details are not customer-visible. Current shared membership governs read access.

Administrative statement lineage requires `admin.account_funding.view` or the existing wildcard grant. Opening evidence, original references, actors, reasons and before/after values remain retained. Limit administration, delegated spending, arbitrary adjustments and refunds of source-only historical payments are outside this subsystem.

## Opening and handover

A later, separately approved opening applicator must provide fresh frozen source evidence, verified owner/member/currency mappings, original archive lineage, checksum, eligibility decisions and explicit preserve-limit and activation authority. Positive source cash uses the accepted HALF_UP opening policy; negative debt retains four decimals. Exact opening replay returns the same receipt; altered evidence is denied. Source/account/currency identity prevents a newer capture from creating a second opening.

BILLmanager remains authoritative until a separately approved handover. No feature switch or schema migration releases migration holds or authorizes customer mail, provider work or historical payment replay. Unresolved accounts remain blocked. Installation, disabled-runtime restore/rollback acceptance, opening application and live handover are separate operations from source publication.
