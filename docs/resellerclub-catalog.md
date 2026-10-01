# ResellerClub catalog synchronization

Install the companion [ResellerClub extension](https://github.com/Delitants/paymenter-resellerclub-extension) including Catalog.php, CatalogSync.php, ManagedPricing.php and SyncSchedule.php. In Admin → Domain Names → ResellerClub choose the exact server and category, pricing policy and TLD selection. Use Dry Run to preview without database writes, then Sync Now to save draft products.

For markup managed in ResellerClub, select **ResellerClub markup + automatic increases**. The first successful sync captures the retail/cost ratio separately for each TLD, action and term. If wholesale cost rises, Paymenter raises its catalog price to preserve that ratio. Cost decreases retain the last price. A numerically changed ResellerClub retail price captures a new ratio and can lower the catalog price deliberately. The API cannot identify who changed a retail value: any changed value is treated as a new policy. No extra Paymenter markup is applied, and the plugin does not write prices back to ResellerClub. Paymenter prices may therefore exceed the retail values still displayed there.

Retail and wholesale currencies must match. A single annual wholesale rate is used as the flat annual cost for longer retail terms; multi-term wholesale tables require an exact term match. Ratios use exact decimal/rational arithmetic and round the full term once. Initial or changed retail below cost, zero costs without an inferable markup, and missing comparable costs require review. Affected TLDs are hidden and stock zero, while prior quote prices are preserved; valid TLDs continue syncing. Resolve those values in ResellerClub, sync again and verify held products before enabling them. Currency/account/product changes or corrupted/lost saved policies fail atomically. Switching to another price source preserves managed baselines; returning to managed mode resumes their previous floors.

The other policies copy ResellerClub customer selling prices or apply a local percentage to customer/cost prices. They do not infer or preserve a ResellerClub margin.

Enable Automatic Sync only when the selected pricing policy is ready. Daily is the default; weekly supports a weekday and monthly runs on the first day. The existing Paymenter scheduler checks hourly from 03:00 application time, retrying on eligible hours until successful. No additional OS cron is necessary. Existing service amounts remain unchanged. Manual partial/other-server refreshes do not suppress the configured schedule.

The sync discovers the provider's active TLD catalog, including extensions with no existing customer services. It uses explicit provider currency and term-specific registration, renewal and transfer prices. Per-year prices are multiplied by term and markup before rounding. Unsupported currencies or malformed/incomplete responses leave previous catalog prices intact. Withdrawn TLDs are hidden and out of stock; services are retained.

The provider's `thirdleveldotname` profile with exactly `*.name` is excluded because personal third-level .NAME registrations require a separate order contract. Ordinary `.name` and compound zones remain in the catalog. Unknown or mixed wildcard identities still fail validation, and a feed containing no supported catalog zones cannot replace previous prices.

New products are hidden and out of stock. Registration plans use whole-year native billing terms. Distinct renewal/transfer totals are preserved in the product's `resellerclub_catalog` setting; they are not separate new-registration plans. The opt-in paid lifecycle and a separate demo reseller account must pass acceptance before publishing products for purchase; transfer, premium and registry-specific flows remain unavailable.

The native grouped checkout fixes the extension to the selected catalog product and validates that identity on the server. Clients can change the name and registration term, and supply two required and two optional distinct nameserver hostnames. Defaults come from server settings. Existing domain nameservers are never changed by checkout or catalog sync.

Optional WHOIS protection defaults off. Catalog sync caches provider eligibility and the reseller's wholesale annual privacy price. For compatible currencies and supported zones, the add-on is priced at cost plus 20% for the selected term, with one final rounding to cents. Missing, zero, invalid or incompatible quotes cannot be purchased. Domain pricing continues to use its existing provider-managed markup policy. Native tax handling applies to both charges. At native invoice checkout the plugin saves an authenticated quote tied to the customer, product, plan, domain, registrar account and selected extra. Its invoiced gross amount includes the original tax, including zero tax, so later tax or billing-country changes cannot block a correctly paid registration. Unquoted pending registrations require review; they are never inferred from current taxes.

Registration and paid renewal purchase the selected privacy term through the existing durable provider claims. A domain does not finish provisioning until the exact domain/order/customer, nameservers, domain expiry and selected privacy status/expiry are confirmed. An unknown result is reconciled by reads, without another purchase request. Synced privacy increases apply when a new native renewal invoice is generated; issued invoices and imported/custom service prices are not rewritten.

Zone cards display distinct registration and renewal prices for the shortest supported term. Product details display a table for all supported quoted terms. Checkout shows registration, optional privacy, tax, total today and the current renewal total. These views use cached catalog data and native currency formatting.

Companion extensions can provide optional `getProductPricing(Product $product): ?array` display metadata and accept an optional fourth `Plan` argument in `getCheckoutConfig`. These render hooks must use cached data and never issue provider requests. Premium DNS, email, SSL, website security, backups and other separately provisioned products are not sold by this domain lifecycle.

```sh
php artisan resellerclub:sync-prices --server=SERVER_ID --category=CATEGORY_ID --source=managed --markup=0 --all --dry-run
```

`--all` overrides saved filters. `--tlds=.com,.co.uk` selects a subset. Automatic execution uses `--scheduled`; it respects the saved enable flag and selected schedule. Failed runs expose a sanitized status in the admin page. Install both fork changes together; a disabled schedule safely skips when the companion extension is absent.

Synthetic coverage: `ResellerClubCatalogTest`, `ResellerClubCatalogShapeTest`, `ResellerClubManagedPricingTest`, `ResellerClubManagedCatalogTest`, and `ResellerClubIntegrationTest`. Run in an isolated test database. Real provider orders and billing handover are deliberately separate from catalog tests.
