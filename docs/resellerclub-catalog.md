# ResellerClub catalog synchronization

Install the companion [ResellerClub extension](https://github.com/Delitants/paymenter-resellerclub-extension) including Catalog.php, CatalogSync.php, ManagedPricing.php and SyncSchedule.php. In Admin → Domain Names → ResellerClub choose the exact server and category, pricing policy and TLD selection. Use Dry Run to preview without database writes, then Sync Now to save draft products.

For markup managed in ResellerClub, select **ResellerClub markup + automatic increases**. The first successful sync captures the retail/cost ratio separately for each TLD, action and term. If wholesale cost rises, Paymenter raises its catalog price to preserve that ratio. Cost decreases retain the last price. A numerically changed ResellerClub retail price captures a new ratio and can lower the catalog price deliberately. The API cannot identify who changed a retail value: any changed value is treated as a new policy. No extra Paymenter markup is applied, and the plugin does not write prices back to ResellerClub. Paymenter prices may therefore exceed the retail values still displayed there.

Retail and wholesale currencies must match. A single annual wholesale rate is used as the flat annual cost for longer retail terms; multi-term wholesale tables require an exact term match. Ratios use exact decimal/rational arithmetic and round the full term once. Initial or changed retail below cost, zero costs without an inferable markup, and missing comparable costs require review. Affected TLDs are hidden and stock zero, while prior quote prices are preserved; valid TLDs continue syncing. Resolve those values in ResellerClub, sync again and verify held products before enabling them. Currency/account/product changes or corrupted/lost saved policies fail atomically. Switching to another price source preserves managed baselines; returning to managed mode resumes their previous floors.

The other policies copy ResellerClub customer selling prices or apply a local percentage to customer/cost prices. They do not infer or preserve a ResellerClub margin.

Enable Automatic Sync only when the selected pricing policy is ready. Daily is the default; weekly supports a weekday and monthly runs on the first day. The existing Paymenter scheduler checks hourly from 03:00 application time, retrying on eligible hours until successful. No additional OS cron is necessary. Existing service amounts remain unchanged. Manual partial/other-server refreshes do not suppress the configured schedule.

The sync discovers the provider's active TLD catalog, including extensions with no existing customer services. It uses explicit provider currency and term-specific registration, renewal and transfer prices. Per-year prices are multiplied by term and markup before rounding. Unsupported currencies or malformed/incomplete responses leave previous catalog prices intact. Withdrawn TLDs are hidden and out of stock; services are retained.

New products are hidden and out of stock. Their registration quote plans are one-time drafts. Distinct renewal/transfer totals are preserved in the product's `resellerclub_catalog` setting; they are not separate new-registration plans. Domain checkout, contact creation, registration, transfer, premium quotation and paid renewal must pass separate acceptance before publishing products for purchase.

```sh
php artisan resellerclub:sync-prices --server=SERVER_ID --category=CATEGORY_ID --source=managed --markup=0 --all --dry-run
```

`--all` overrides saved filters. `--tlds=.com,.co.uk` selects a subset. Automatic execution uses `--scheduled`; it respects the saved enable flag and selected schedule. Failed runs expose a sanitized status in the admin page. Install both fork changes together; a disabled schedule safely skips when the companion extension is absent.

Synthetic coverage: `ResellerClubCatalogTest`, `ResellerClubCatalogShapeTest`, `ResellerClubManagedPricingTest`, `ResellerClubManagedCatalogTest`, and `ResellerClubIntegrationTest`. Run in an isolated test database. Real provider orders and billing handover are deliberately separate from catalog tests.
