# Grouped native domain checkout and priced privacy

**Goal:** Implement the approved grouped checkout, lock the selected zone, expose nameservers, offer optional WHOIS protection at wholesale cost plus 20%, and display registration and renewal prices for each zone.

**Architecture:** Keep native cart, invoice and paid lifecycle. Extend the existing checkout field contract with optional plan context and presentation metadata. Cache provider privacy eligibility and wholesale price during catalog sync; never call pricing APIs from a render. Verify privacy expiry in the existing durable registration/renewal claims.

**Stack:** Laravel 12, Livewire 4, Blade, Alpine, native Price, MariaDB.

**Spec:** User-approved grouped Superdesign draft and follow-up instructions in this task.

## Global constraints
- BILLmanager billing, mail and callbacks stay authoritative until handover approval.
- Only isolated test/provider fixtures and held candidate installation; no real domain purchases, renewals or customer notices.
- No Proxmox operations. No private data or credentials in public code/design artifacts.
- Existing domain markup policy stays intact; the 20% rule applies only to optional WHOIS protection.
- Imported service prices and nameservers are not rewritten.
- Optional privacy defaults off; consent remains unchecked; selected TLD is validated on the server.

## Review focus
Check billing totals across plan changes, unknown provider responses, privacy expiry, custom NS persistence, unsupported zones, stale prices and the generic checkout compatibility path.

### Task 1: Native nameservers and paid privacy
- [ ] Write behavior tests first: eligibility/cost sync, 20% full-term rounding, plan changes, native cart total, malformed NS, locked TLD, paid registration/renewal and missing privacy confirmation/replay.
- [ ] Observe RED in the isolated network-disabled verification checkout.
- [ ] Extend getCheckoutConfig optional plan context in ExtensionHelper/Checkout/CartItem; retain existing extension signatures.
- [ ] Extend Catalog/ResellerClub/CatalogSync/DomainOrder/Lifecycle with cached optional privacy, validated NS, exact native paid totals, registration/renewal purchase flags and confirmed privacy expiry.
- [ ] Run focused tests, then full suite and formatter; commit.

**Interfaces:** Produces section/field metadata and optional per-plan prices consumed by Task 2. Privacy totals include the same whole-year plan used by native billing.

### Task 2: Approved grouped theme and zone pricing
- [ ] Update the selected Superdesign draft in place with locked extension, nameserver section and optional priced privacy; preserve synthetic-only inputs.
- [ ] Implement grouped checkout layout, fixed suffix presentation and distinct summary rows; retain generic Proxmox controls.
- [ ] Add an optional cached product pricing display hook and render zone registration/renewal terms on catalog and detail pages.
- [ ] Verify desktop/mobile native browser forms, plan selection, privacy totals, zone lock, validation, cart invoice, paid fixture confirmation and replay.
- [ ] Run full suite, formatter, fresh whole-branch review and necessary fixes.
- [ ] Publish generic core/plugin commits to the existing draft PRs; install the backed-up held candidate only; verify production/holds/table fingerprints. Record exact deployment and acceptance limits.

**Interfaces:** Consumes Task 1 plan-aware checkout metadata. Theme uses native Price and configurable branding.
