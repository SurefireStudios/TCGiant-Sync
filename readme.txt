=== TCGiant Sync ===
Contributors: tcgiantteam
Tags: ebay, woocommerce, sync, inventory, tcg
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your eBay TCG listings to WooCommerce automatically — and push WooCommerce products back to eBay as new listings. Import products, map categories, keep inventory in sync, and create listings in both directions.

== Description ==

TCGiant Sync bridges your eBay store and WooCommerce, enabling automatic import and synchronization of trading card game (TCG) product listings.

**Free Features:**

* Connect to eBay via secure OAuth 2.0
* Import up to 50 active eBay listings to WooCommerce
* Automatic product mapping and category organization
* Real-time inventory synchronization
* Live sync dashboard with status monitoring
* Activity logging for troubleshooting
* **Push to eBay** -- create new eBay listings directly from WooCommerce (single product or bulk)
* **Unified "eBay Listing" tab** -- guided step-by-step flow: Item Type, Category, Condition, Push
* **Category auto-suggestion** -- eBay suggests the best leaf categories from your product title
* **Visual card selectors** for Item Type (Trading Cards / Coins) and Condition (Graded / Ungraded)
* **Pre-push readiness checklist** validates category, condition, policies, images, and title length
* Per-product eBay Category and Condition overrides
* **Per-product Listing Type & Duration** — choose Fixed Price or Auction, with duration from 1 day to GTC, per product or as global defaults
* **Per-product Shipping Policy** — override the default fulfillment policy on individual products
* **"All Other" item type** — list non-collectible products (electronics, clothing, etc.) with simple conditions, no grading required
* eBay ConditionDescriptor support — Graded (PSA, BGS, SGC, CGC, etc.) and Ungraded conditions for Trading Cards and Coins categories
* Business Policy management (Shipping, Returns, Payments) with one-click fetch

**Pro Features (requires license key):**

* Unlimited product imports
* Priority support

== Installation ==

1. Upload the `tcgiant-sync` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure WooCommerce is installed and active.
4. Navigate to **TCGiant Sync** in the admin menu.
5. Click **Connect to eBay** to authorize your eBay account.
6. Select your store categories and start syncing!
7. To push listings to eBay, configure Export Defaults in Settings (Category ID + Business Policies), then use the **Push to eBay** button on any product.

== Frequently Asked Questions ==

= Can I push WooCommerce products to eBay? =

Yes! Open any product and click the **"eBay Listing"** tab. Follow the guided 4-step flow: choose Item Type, select a Category (or let eBay auto-suggest one from your title), set the Condition, then review the readiness checklist and click Push. You can also bulk-push from the WooCommerce Products list.

= What is the "eBay Listing" tab? =

The eBay Listing tab is a unified product panel that replaces the old "Grading & Condition" and "TCGiant Sync" tabs. It walks you through 4 steps: (1) Item Type, (2) Category, (3) Condition, (4) Push to eBay. Each step uses visual card selectors instead of dropdowns, and includes a readiness checklist to catch issues before you push.

= What are eBay Business Policies? =

Business Policies are eBay's system for managing reusable Shipping, Return, and Payment settings across your listings. Your seller account must be enrolled to use the Push to eBay feature. Most accounts are enrolled automatically — if not, you can enroll at bizpolicy.ebay.com.

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and active for TCGiant Sync to function.

= How many products can I sync for free? =

The free tier supports up to 50 active synced products. Upgrade to Pro for unlimited imports.

= Is my eBay data secure? =

Yes. TCGiant Sync uses eBay's official OAuth 2.0 authentication. Your credentials are never stored in plain text.

= How does inventory sync work? =

When a product sells on either eBay or WooCommerce, the plugin automatically adjusts stock levels on the other platform to prevent overselling.

= What types of products are supported? =

TCGiant Sync is optimized for trading card game (TCG) collectibles and coins. It includes full eBay ConditionDescriptor support for graded items (PSA, BGS, SGC, CGC, and 17 other graders) and ungraded items (Near Mint, Excellent, etc. for cards; Uncirculated, Fine to Very Fine, etc. for coins). It works with any eBay listing type.

== Screenshots ==

1. Dashboard overview showing sync status and statistics.
2. eBay connection settings with one-click OAuth.
3. Category filter selector for targeted imports.
4. Live activity log with real-time updates.
5. Push to eBay export settings with Business Policy selector.
6. "eBay Listing" tab showing the unified step-by-step push flow with readiness checklist.
7. Category auto-suggestion pills from product title.

== Changelog ==

= 3.3.0 - 2026-07-30 =
**Security**
* Credentials are no longer written to the activity log. A failed token refresh logged the relay's full response verbatim, which could place an access token, refresh token or the relay signing key into a plaintext file that ends up in support bundles and backups.

**New**
* NEW: Listings created from WooCommerce now include your product attributes as eBay item specifics. Previously they went up with almost none, which eBay penalises in search — and for categories with mandatory aspects, rejects.
* NEW: Required item specifics are checked before pushing, naming exactly which are missing instead of failing at eBay with a generic error. Skipped if eBay's metadata service is unreachable, so an outage can never block listing.
* NEW: "Host listing images on eBay?" setting. Listings normally reference images on your own site, which requires eBay to reach it — that fails on password-protected sites, behind bot protection, and breaks on a domain change. Uploads to eBay Picture Services instead, cached per image, with fallback to the site URL if an upload fails.

**Background tasks**
* FIX: Page scanning no longer stalls between batches — the immediate-continue request was being ignored because the scanner runs from cron itself, so each batch waited for the next scheduled tick.
* FIX: Background work now runs on hosts that disable WP-Cron. Those sites never ran image localization at all, leaving products on eBay-hosted images that stop working when the listing ends.
* NEW: "Background Tasks" indicator in System Health, so a misconfigured host is visible instead of silently doing nothing.

= 3.2.1 - 2026-07-30 =
* Re-release of 3.2.0 so every install receives the complete update. The 3.2.0 release went out a few minutes before its final two fixes landed, so some sites may have downloaded a 3.2.0 build missing the product-duplication fix and the Settings page layout fix — and with an identical version number, would never have been offered a correction.
* No functional changes beyond 3.2.0. Harmless if you already have a complete 3.2.0.

= 3.2.0 - 2026-07-30 =
**Data safety**
* CRITICAL: Duplicating a product no longer copies its eBay listing link. WooCommerce copies all custom fields on duplicate, so the copy inherited the original's eBay Item ID and appeared already listed — while pointing at the original's live listing. Pressing Update on the copy would have overwritten the original's real listing, and End Listing would have closed it. Duplicates now start unlinked. Export settings (category, condition, grader, policies) are still copied.
* CRITICAL: Products duplicated BEFORE this fix still carry the stale link. Push and End Listing are now blocked for any product sharing an eBay Item ID, an admin notice lists the affected products, and a new "Unlink from eBay" button clears the bad link. Unlinking is local only and never changes the eBay listing.
* CHANGED: Variations that disappear from an eBay listing are no longer deleted — they are set to private and out of stock. A partial eBay response previously destroyed them permanently along with their links to past orders. Retiring is reversible; deleting was not.
* FIX: eBay order values are parsed properly. Amounts arriving as structured values (whenever eBay includes a currency attribute) were cast directly to a number, silently producing $1.00 orders. Totals, shipping, line prices and quantities now share the product importer's normalizer.
* FIX: Order import reads every page — it previously requested only the first 100 orders per day and ignored the rest.

**Fixed**
* FIX: Settings page two-column layout — "Import Settings" and "Push to eBay Settings" were stacking vertically instead of sitting side by side. The Import card was missing its closing tag, so the Push card nested inside it. This is the same markup fault the 3.1.1 and 3.1.2 CSS changes were chasing.
* FIX: The Listings page and auto-relist scheduler were reading a snapshot frozen at install time; the listings table was never written to after the initial migration. It is now kept current on every import.
* FIX: Store categories are no longer lost for an hour after a failed lookup, which caused products to import uncategorised.
* FIX: Bulk "Push to eBay" no longer reports success before contacting eBay. Queued and completed are now counted separately.
* FIX: The progress bar's colour gradient, lost in 3.1.2 when the stripe overlay replaced it instead of layering over it.
* FIX: Saving settings could fail on nested values.
* FIX: Job status lookups now require administrator permissions.

**Security & privacy**
* The activity log filename now includes a per-site random token. The log directory relies on an .htaccess rule that nginx ignores, so the log — containing product titles, SKUs and raw eBay API responses — was readable at a predictable URL on nginx.
* eBay-supplied business policy names are escaped before display.

**Maintenance**
* Removed ~760 lines of unreachable code (the pre-3.0.0 image downloader and pre-2.0.0 page scanner). The old scanner counted progress differently, which broke "Resume Import" after an interrupted sync.
* Action Scheduler cleanup now removes matching log rows instead of leaving them to accumulate.
* PHPStan static analysis reports zero findings, and runs in CI with a syntax check on every change.

= 3.1.4 - 2026-07-30 =
**Performance**
* PERFORMANCE: Added a database index for the plugin's metadata lookups. Image deduplication and eBay Item ID matching previously scanned every matching row in wp_postmeta on every lookup — roughly 80,000 full scans during a 10,000-product sync. Applied once, automatically, and skipped safely if the database user lacks permission.
* PERFORMANCE: The image deduplicator no longer duplicates attachment records. Reusing an image across products created a new media library entry each time, bloating the library and leaving copies that broke if the original file was deleted.
* PERFORMANCE: The scan's active-listing tracker is no longer autoloaded — on a 10,000-item store it grew to ~300KB and was loaded on every page request, front-end included, for the duration of the sync.
* PERFORMANCE: Bulk job progress no longer rewrites the whole job history after every batch (~1,000 rewrites of a large option for a 5,000-product push).
* PERFORMANCE: Log writes are buffered and flushed once per request rather than appending to the file on every line, and routine info lines are no longer duplicated into the WooCommerce log.
* NEW: "Log Detail" setting under Settings → Scheduling. "Warnings and errors only" substantially reduces disk activity on large stores.

**Reliability**
* FIX: eBay requests retry transient failures. A single DNS blip, dropped connection or eBay 5xx used to fail an entire sync job outright.
* FIX: eBay HTTP 429 responses are recognised as rate limiting and honour the Retry-After header.
* FIX: The daily API call counter is incremented atomically — concurrent workers were overwriting each other's counts, so the daily budget safeguard engaged later than intended.
* FIX: Log rotation now looks in the directory the plugin actually writes to; the daily cleanup had been checking a path that never contained logs.

**Uninstall**
* FIX: Uninstalling now actually removes the plugin's data. The routine referenced option names that never existed, so deleting the plugin left everything behind — including the stored eBay access token, refresh token and relay signing key. Products and orders are deliberately left untouched.

= 3.1.3 - 2026-07-30 =
**Security**
* SECURITY: The eBay OAuth callback accepted account credentials without verifying who sent them — no nonce, no capability check. Any logged-in user, or an administrator following a crafted link, could replace the site's stored eBay tokens and relay signing key, redirecting the store's listings and stock pushes to another eBay account. Connecting now goes through a nonce-protected handler with a one-time state token, and the callback requires an administrator plus a pending handshake.
* SECURITY: Removed the hardcoded fallback key used to verify eBay Marketplace Account Deletion notifications. The key shipped inside the plugin, so it could be used to forge notifications against sites that had not yet been issued a per-site key — and that endpoint erases customer data from orders. If notifications stop being accepted after updating, reconnect your eBay account from Settings to be issued a key.
* SECURITY: Deletion notifications older than 5 minutes are now rejected, preventing replay of a captured request.

**Critical fixes**
* CRITICAL: Importing from eBay could permanently END live eBay listings. Imported stock was being pushed straight back to eBay, and anything mapping to 0 available stock triggered auto-end — so sold-out-but-active listings were closed simply by importing them. Stock that originates from eBay is no longer pushed back.
* CRITICAL: That same loop queued one wasted eBay API call per imported product. A 10,000-item import no longer generates 10,000 pointless stock pushes.
* CRITICAL: A single failed API request part-way through a scan was treated as "no more pages", so the scan ended early and the orphan pruner trashed every product on the pages never reached. Failed requests are now reported as errors, progress is kept, and pruning is blocked unless the scan completed cleanly.
* CRITICAL: Orphan pruning gained two more safety nets — it refuses to run when no active eBay Item IDs were recorded, and aborts if it would retire more than 20% of your eBay-linked products at once.
* CRITICAL: Image migration on relist could strip images off live products when two active listings shared one seller SKU. It now only harvests images from trashed products.
* CRITICAL: Background image localization marked products "done" even when every download failed, losing those images permanently. Failures are now retried up to 3 times with a 10-minute backoff, and permanent 404/403 errors are recognised rather than retried forever.
* CRITICAL: The weekly inventory reconciliation crashed on every run (it called a method that does not exist), taking down other scheduled tasks in the same cron tick. It now works, checks every page instead of only the first 200 listings, and compares available stock rather than listed quantity.

**Fixes**
* FIX: Relisting always failed with "The UUID can only contain Numbers and Letters from A to F" — the v3.1.1 UUID fix missed the relist call, so auto-relist and bulk relist never worked.
* FIX: eBay Marketplace Account Deletion requests matched no orders and erased nothing, because the handler searched a meta key the plugin never writes.
* FIX: The setup wizard never showed eBay as connected and never advanced past step 1.
* FIX: Order-driven stock reductions and orphan pruning no longer echo redundant calls back to eBay.

= 3.1.2 - 2026-07-29 =
* FIX: Settings page two-column layout — removed 53 duplicated CSS rules that were overriding the grid definitions.
* FIX: Images are preserved when eBay relists an item under a new Item ID and SKU, instead of being re-downloaded.
* Performance: Global image deduplication — a previously downloaded image is reused across products.
* Performance: Image localization processes 50 products per batch (up from 10), cutting total localization time for large stores.

= 3.1.1 - 2026-07-29 =
* FIX: UUID format error on Push to eBay — eBay requires 32 hex characters without hyphens.
* FIX: "No changes allowed on ended listings" error — stock sync now handles already-ended listings gracefully and marks local status as Ended.
* FIX: Settings page two-column layout — added the missing CSS definitions for tc-card, tc-row-2col and tc-row-3col.
* FIX: Stock was being synced to eBay for products that no longer exist in WooCommerce. All SKU lookups now exclude trashed products.

= 3.1.0 - 2026-07-29 =
* Feature: Setup Wizard — multi-step guided setup (Connect → Import → Export → Done).
* Feature: REST API — five endpoints under /tcgiant-sync/v1/ for listings, listing detail, sync status, start sync and orders.
* Feature: Job Queue — AJAX batch processor with progress tracking for bulk operations.
* Feature: Bulk actions on Listings — bulk Push, End and Relist with a modal progress bar.
* Feature: Verify button on the product edit screen — calls VerifyAddItem to show estimated fees and catch errors before listing.
* Feature: Auto-Relist Scheduler — daily check for ended listings with stock remaining. Off by default.
* Feature: eBay Order column on the WooCommerce Orders list (HPOS and legacy compatible).
* Feature: UUID duplicate prevention on AddItem, so a network timeout and retry cannot create two listings.

= 3.0.1 - 2026-07-29 =
* CRITICAL FIX: Products were not matching by SKU when eBay changed the Item ID (old listing expired, seller created a new one), creating duplicate products with mangled SKUs like "SKU-123456789".
* FIX: Trashed WooCommerce products were blocking SKU matching and causing false "Duplicate SKU detected" warnings.
* FIX: Trashed products could be matched by _ebay_item_id. The lookup now excludes them.

= 3.0.0 - 2026-07-29 =
* CRITICAL: Deferred image pipeline — products receive eBay image URLs immediately during sync and images are downloaded to the media library in the background. Eliminates the 7,600+ queued image jobs that blocked scanning for days.
* Feature: Full eBay order import via GetOrders, polling every 15 minutes, with address and line-item mapping, deduplication by eBay Order ID, and HPOS compatibility.
* Feature: Tracking number push to eBay when a WooCommerce order is marked Completed.
* Feature: Real-time WooCommerce → eBay stock sync for manual edits, CSV imports and REST API changes.
* Feature: Listings admin page — filterable, sortable, searchable table of all eBay-linked products.
* Feature: Best Offer support with auto-accept and auto-decline thresholds.
* Feature: GPSR compliance fields (manufacturer, address, EU responsible person).
* Feature: Custom database table for eBay listing data with indexed columns.
* Performance: File-based concurrency lock, cron rate limiting, and a daily maintenance task.

= 2.1.0 - 2026-07-28 =
* CRITICAL FIX: Scan resume now uses WP-Cron instead of Action Scheduler. When PHP timed out, the resume action was getting stuck behind 2,800+ image/import jobs in the AS queue for hours. WP-Cron events fire directly on the next page load, bypassing the AS batch queue entirely.
* Scan batching: Processes 10 pages per execution then immediately schedules the next batch. A 47-page store completes in ~5 minutes across 5 batches, regardless of PHP time limits.
* Feature: End eBay listings directly from WordPress — new "⛔ End Listing" button on product list and edit screens with confirmation dialog.
* Feature: SKU format setting — choose between plain Item Number (185727729088) or EBAY- prefix (EBAY-185727729088) for new imports. Default is now plain number.
* Feature: Staging environment detection — automatically detects staging/dev sites and blocks write API calls (push, revise, end) to prevent accidental changes to live listings.
* Fix: Relay server now sends User-Agent header (TCGiant-Sync-Relay/2.1) to prevent Cloudflare/WAF from blocking deletion notifications as bot traffic.

= 2.0.0 - 2026-07-27 =
* BREAKING: Complete page scan architecture overhaul. Scanning no longer uses per-page Action Scheduler jobs (which got stuck behind thousands of import/image jobs). Instead, all pages are scanned in a single direct PHP loop with sleep(2) between API calls. A 47-page store now completes scanning in ~3 minutes instead of 4+ days.
* Performance: Added IncludeVariationSpecifics and HideVariations=false to GetSellerList API call. eBay now returns complete variation data inline, dramatically reducing the number of GetItem fallback calls needed.
* Reliability: Auto-resume on PHP timeout — if the server kills the scan loop, it saves progress and auto-schedules a resume within 30 seconds.

= 1.9.1 - 2026-07-24 =
* Performance: GetItem fallback imports moved to separate Action Scheduler group ('tcgiant_sync_imports'). This was the second bottleneck — 600 GetItem calls were blocking the page scan from advancing. Now three fully parallel queues: page scanning, product imports, and image downloads.

= 1.9.0 - 2026-07-24 =
* Feature: Persistent shipping policies — eBay policies now stored permanently in wp_options instead of 1-hour transients. No more "forgetting" after an hour.
* Feature: Sortable & filterable eBay column — dropdown filter above the WooCommerce Products list with options: Listed on eBay, Not on eBay, Ended, Auctions Only, Fixed Price Only. Column is also sortable.
* Feature: Listing type badges — each product now displays its eBay listing type (Auction/Fixed Price), duration (GTC, 10 Days, etc.), and end date directly in the product list column.
* Feature: Automatic ended-listing detection — hourly cron checks for products whose eBay end time has passed and marks them as "Ended" with a red ⛔ indicator.
* Data: eBay listing metadata (ListingType, ListingDuration, EndTime, ListingStatus) now stored during import for all future syncs.

= 1.8.5 - 2026-07-24 =
* Performance: Critical fix — image downloads now run in a separate Action Scheduler group ('tcgiant_sync_images') so they no longer block page scanning and product imports. Previously, 700+ image download jobs would queue ahead of the next page scan, causing the sync to stall for hours. Page scanning and image downloads now run in parallel.

= 1.8.4 - 2026-07-23 =
* Performance: Dramatically faster page scanning for large stores. Previous logic waited up to 5+ minutes between pages (3s × queued items). Now caps next-page delay at 30s max and GetItem fallback stagger at 60s. A 47-page store that previously took hours to scan now completes much faster.

= 1.8.3 - 2026-07-23 =
* Feature: Dashboard now shows full live sync status — page progress (e.g. "Page 5/47 - All Categories"), Products/Queued/Pending Jobs counters, progress bar during import phase, and a spinning "Sync in Progress" indicator. All stats auto-update via AJAX polling.

= 1.8.2 - 2026-07-23 =
* Feature: Added "Import All Listings" toggle to the Settings page. A prominent green switch at the top of Import Filters makes it clear and easy to import every active eBay listing regardless of category. Toggling it off reveals the Custom and Standard category filter sections for selective imports.

= 1.8.1 - 2026-07-23 =
* Fix: Per-product shipping policy dropdown now correctly reads cached policies from eBay — previously empty due to a transient key mismatch.
* Feature: Added "Year" field for Coins & Paper Money listings. eBay requires the Year item specific for all coin categories; a new text input appears when the item type is set to Coins (both graded and ungraded).

= 1.8.0 - 2026-07-22 =
* Fix: UUID format corrected — eBay requires exactly 32 hex characters (no hyphens). `wp_generate_uuid4()` produces 36 characters with hyphens; the fix strips them. This was blocking ALL listing pushes with the error "The UUID can only contain Numbers and Letters from A to F and must be 32 characters long."

= 1.7.9 - 2026-07-22 =
* Feature: Added explicit "Disconnect" button on the Settings page to easily clear stored OAuth tokens when switching eBay seller accounts.

= 1.7.8 - 2026-07-22 =
* Fix: Adjusted GetSellerList EndTime scan range from 3650 days to 120 days to comply with eBay Trading API maximum date range restrictions, resolving "time range has exceeded 121 days" error during full syncs.

= 1.7.7 - 2026-07-22 =
* Fix: Token refresh timeout increased from 5s to 30s to prevent transient cURL error 28 timeouts on relay requests.
* Fix: Corrected false-positive rate limit classification where generic eBay error messages containing the word "exceeded" (e.g. token expiration) were misidentified as rate limit hits.
* Feature: Automatic token refresh retry when eBay returns an expired or invalid IAF token during API requests.

= 1.7.6 - 2026-07-22 =
* Feature: Text-based coin grade aliases — "Gem BU", "Choice BU", "Superb Gem", "Gem Proof", "Choice Proof", "Choice AU", "Choice XF", and "Choice VF" are now available in the graded coin dropdown. Each maps to its Sheldon-scale equivalent (e.g. Gem BU ≈ MS 65) for eBay submission.
* Fix: UUID duplicate prevention — every AddItem request now includes a unique UUID. If a network timeout causes a retry, eBay deduplicates the request instead of creating a duplicate listing.

= 1.7.5 - 2026-07-22 =
* Improvement: eBay daily API call limit updated from 5,000 to 50,000 following Application Growth Check approval.
* Feature: Centralized API usage tracking — sites report daily call counts to the relay server, enabling global usage monitoring.
* Feature: Dashboard "API Calls Today" stat card with color-coded progress bar and usage chart.
* Feature: Per-product Listing Type (Fixed Price / Auction) and Duration (GTC, 30, 10, 7, 5, 3, 1 days) overrides with global defaults on Settings page.
* Feature: Per-product Shipping Policy override — select a different fulfillment policy for individual products.
* Feature: "All Other" item type for non-collectible products — skips grading fields, uses simple conditions.
* Improvement: Auction listings enforce Quantity = 1 and validate duration compatibility.
* Improvement: Duration dropdown dynamically filters to valid options based on listing type.
* Fix: Adaptive throttling for large store imports (10,000+ items) with exponential backoff on rate limits.

= 1.7.2 - 2026-07-18 =
* Fix: Resolved image import timeouts for products with many variants. Image downloads are now processed in chunked batches (2 at a time) to prevent PHP execution limits from silently killing the process.
* Feature: Variation-specific images from eBay are now imported and assigned to the correct WooCommerce variation thumbnail.
* Feature: New "Sync Specific Items" tool on the Import page — enter eBay Item IDs to sync individual products without running a full catalog scan.
* Feature: New "Re-sync images only" checkbox — re-download images for specific products without touching titles, prices, or stock.
* Feature: Auto-retry for failed image downloads — transient network failures get a single automatic retry after 60 seconds.
* Improvement: Image download error logs now include the product ID, variation ID, and the full URL that failed for easier debugging.
* Improvement: Each product now tracks image sync status (`_tcgiant_image_status` meta) with expected vs actual download counts to detect partial imports.
* Improvement: HTTP timeout for image downloads capped at 30s (down from WordPress default 300s) to prevent queue stalls on slow connections.
* Improvement: Memory limit bumped during image processing to handle large eBay photos on shared hosting.
* Fix: Emergency Stop now also cancels pending image download jobs (previously orphaned chunked chains would keep running).
* Fix: Starting a new full/delta sync now clears any leftover image download jobs from previous syncs.

= 1.7.1 - 2026-07-17 =
* Fix: Export <Country>, <Currency>, and <Site> XML tags now use marketplace-derived values instead of hardcoded US/USD.
* Fix: Full sync inline processing now requires ItemSpecifics and PictureDetails before processing inline. Products missing either are routed to GetItem fallback, ensuring attributes and images import reliably.
* Improvement: MARKETPLACES constant now includes country, currency, and site fields for all 8 supported markets.
* Improvement: Settings page placeholder text updated with international examples (e.g., "London, UK", "SW1A 1AA") and marketplace dropdown hint clarifies it controls export Country/Currency/Site.

= 1.7.0 - 2026-07-17 =
* Feature: Unified "eBay Listing" tab replaces the separate "Grading & Condition" and "TCGiant Sync" tabs. All export fields are now in a single panel with a guided 4-step flow.
* Feature: Step-by-step push wizard: Item Type -> Category -> Condition -> Push to eBay. Sections use progressive disclosure and auto-expand as you complete each step.
* Feature: Visual card selectors for Item Type (Trading Cards / Coins) and Condition (Graded / Ungraded) replace plain dropdowns.
* Feature: Category auto-suggestion via eBay's Taxonomy API. Click "Suggest Category from Title" and select from clickable suggestion pills.
* Feature: Pre-push readiness checklist validates category, condition, business policies, images, and title length before queuing.
* Feature: Category browser now filters by item type -- Coins items start at "Coins & Paper Money" root, TCG items start at "Toys & Hobbies" root.
* Feature: Clear Error button to dismiss export errors and retry cleanly.
* Improvement: Smart AJAX polling with adaptive intervals -- reduces server load on non-export pages.
* Improvement: Transient caching for sync stats (30s) and static caching for category lists.
* Improvement: Pre-push validation runs synchronously before queuing, preventing invalid listings from entering the export queue.
* Improvement: All icons use WordPress Dashicons instead of emoji for reliable cross-platform rendering.
* Fix: Grader and grade field name mismatches for item-type-specific suffixes (_tcg, _coins) now resolve correctly.
* Fix: Category-item type mismatch detection warns when a Coins category is set for a TCG item and vice versa.

= 1.6.3 - 2026-07-15 =
* Feature: New "Push to eBay" column on the WooCommerce Products list table. View eBay category, item ID link, push date, and error status for every product at a glance.
* Feature: Inline "Push to eBay" / "Update" buttons in the product list — one-click push without opening the product editor.
* Feature: AJAX-powered push queue with real-time status feedback ("Queuing…", "✔ Queued!", or error messages) inline.
* Improvement: Red "No eBay category" warning for products that need configuration before export.
* Improvement: Category column resolves human-readable names from built-in lists, saved custom categories, and per-product overrides.

= 1.6.2 - 2026-07-15 =
* Fix: Resolved all Coin ConditionDescriptor errors when pushing graded and ungraded coins to eBay. Grader, Letter Grade, and Numeric Grade values now use the correct eBay conditionDescriptorValueIds instead of raw text strings.
* Fix: Ungraded Coin Condition now uses eBay descriptor Name 2 with correct value IDs instead of the TCG descriptor Name 40001.
* Fix: Coin Certification Number now uses descriptor Name 5 (not 2) with a 20-character max per eBay requirements.
* Fix: TCG graders (Ace, AGS, DSG, Majesty, GRAAD, Arena Club, AiGrading) now use correct numeric IDs. Removed 3 delisted graders (PCA, TCG, ARK).
* Improvement: Added all 16 eBay-recognized coin grading companies with correct numeric IDs.
* Improvement: Added complete Sheldon scale numeric grade ID mapping (70 through 1).
* Feature: Category browser now remembers and displays the selected category name persistently (e.g., "✔ US Coins (253)") on Settings, Product Edit, and Export pages.

= 1.6.1 - 2026-07-15 =
* Performance: Dramatically faster full syncs for large stores (10,000+ items). Variation items are now processed inline when GetSellerList returns complete data, eliminating thousands of individual GetItem API calls.
* Performance: Image downloads in the GetItem fallback path are now scheduled asynchronously, preventing long blocking operations from clogging the Action Scheduler queue.
* Performance: Reduced per-item stagger delay from 3 seconds to 1 second for items that still require GetItem fallback.
* Performance: Reduced next-page scanning delay from 5 seconds to 2 seconds when all items on a page are processed inline.

= 1.5.6 - 2026-07-10 =
* Fix: Resolved another fatal error (`Undefined constant GRADES`) in the settings page that was preventing Javascript from loading and rendering the "Load Store Categories" button inert.

= 1.5.5 - 2026-07-10 =
* Fix: Resolved a fatal error (`Undefined constant GRADERS`) on the settings and export pages caused by the recent splitting of the graders list into separate Trading Cards and Coins arrays.

= 1.5.4 - 2026-07-10 =
* Feature: Added "eBay Standard Categories to Import" filter to settings to allow importing specific standard categories (e.g. Trading Card Games, Coins: US) without requiring an eBay Store subscription.
* Improvement: Replaced the manual eBay Category ID text input on the product page with a searchable dropdown of supported Standard Categories, while retaining a "Custom Category ID" option for subcategories.
* Fix: ConditionDescriptor validation now checks the "Item Category" (e.g. Coins, Trading Cards) set in the Grading tab instead of just checking the numeric Category ID, resolving issues where custom/deep subcategories would trigger an "Invalid Category" API error.
* Fix: Added robust error handling and UI feedback to the "Load Store Categories" button if the API request fails or the seller lacks a Store subscription.

= 1.5.0 - 2026-07-08 =
* Feature: Added eBay ConditionDescriptor support for Trading Cards and Coins categories. eBay now mandates structured condition data (Graded vs. Ungraded) for these categories — listings missing this data will be blocked.
* Feature: New "Condition Type" setting with Graded (Professional Grader + Grade + optional Cert Number) and Ungraded (card/coin condition) options that generate the required `ConditionDescriptors` XML.
* Feature: 21 professional grading companies supported (PSA, BGS, SGC, CGC, CSG, and more) with correct eBay numeric value IDs.
* Feature: Category-aware ungraded conditions — Trading Cards show Near Mint, Excellent, Very Good, Poor, etc.; Coins show Uncirculated, Extremely Fine to AU, Fine to VF, Below Fine.
* Feature: Per-product condition descriptor overrides in the TCGiant Sync product tab.
* Feature: Added Coins categories (US, World, Canada, Ancient, Medieval) to the export category dropdown.
* Improvement: Export validation now checks for required ConditionDescriptor fields when the target category is TCG or Coins.
* Improvement: Export status page displays structured condition info (e.g., "Graded · PSA · 10").

= 1.4.8 - 2026-07-08 =
* Fix: Resolved "No <Item.Location> exists" error when pushing products to eBay. The required `<Location>` and `<PostalCode>` XML tags were missing from the AddItem/ReviseItem API call.
* Feature: Added "Item Location" and "Postal Code" fields to the Push to eBay settings page.
* Improvement: Export validation now catches missing Location and Postal Code before hitting the eBay API, providing a clear error message instead of a raw API error.

= 1.4.7 - 2026-07-01 =
* Feature: Added a native WooCommerce "BIN" field to the product inventory tab. This visually surfaces the `_bin_location` meta field, enabling users who map eBay Custom SKU to Bin Location to view and edit warehouse bin codes directly inside WooCommerce.

= 1.4.6 - 2026-06-29 =
* Feature: New "eBay SKU Maps To" setting with two modes: "Product SKU" (default) or "Bin Location". When set to Bin Location, the eBay SKU is stored as a `_bin_location` custom meta field instead of the WooCommerce SKU — useful for sellers who use the eBay SKU field for warehouse/shelf location codes (e.g., "A3-B7"). The WooCommerce SKU automatically falls back to ISBN/UPC/EAN or EBAY-{ItemID}.
* Improvement: Added Free Shipping guidance tip under the "Add eBay Shipping Cost to Product Price" setting, directing users to configure a WooCommerce Free Shipping zone for an all-inclusive pricing setup.

= 1.4.5 - 2026-06-29 =
* Fix: Weight and dimensions are now correctly imported for non-US marketplaces (UK, CA, AU, DE, FR, IT, ES). Metric values from eBay (e.g., 200g, 34cm) were incorrectly treated as imperial and double-converted. The plugin now uses the configured marketplace to determine the source unit system.
* Fix: Item Specifics (specs/attributes) now import correctly during scheduled delta syncs. The GetSellerEvents API call was missing the IncludeItemSpecifics flag.
* Fix: Product images now import reliably during delta syncs. If GetSellerEvents omits image or spec data, the plugin automatically falls back to a GetItem call.

= 1.4.4 - 2026-06-22 =
* Feature: New "Add eBay Shipping Cost to Product Price" setting in Import Settings. When enabled, the eBay flat-rate shipping cost is automatically added to the WooCommerce product price on import (e.g., $10 item + $5 shipping = $15). Free shipping and calculated shipping items are unaffected.

= 1.4.3 - 2026-06-22 =
* Fix: Resolved category filter not matching any items during full sync. The eBay GetSellerList API was not returning Storefront and PrimaryCategory data because OutputSelector tags were overriding DetailLevel. Switched to DetailLevel=ReturnAll to guarantee all item fields are returned.
* Fix: Import log now distinguishes between total items scanned vs. items matched by the category filter (e.g., "Scanned 100 items, 3 matched").
* UX: Added a contextual settings summary bar on the Import page showing active category filter, auto-sync interval, and data mapping settings at a glance with a direct link to edit.
* UX: Dashboard "Recent Activity" log now renders at full width.

= 1.3.1 - 2026-06-16 =
* Security: Replaced the hardcoded webhook signing key with a unique, cryptographically random per-installation key. Each site now receives its own signing key during OAuth connection, preventing forged Marketplace Account Deletion requests.
* Improvement: Legacy installations continue to work using the previous key until they re-authenticate.

= 1.3.0 - 2026-06-16 =
* Feature: Import product weight and package dimensions (length, width, height) from eBay listings into WooCommerce. Values are automatically converted to match your store's configured weight and dimension units.
* Feature: Added "Overwrite Weight & Dimensions" toggle in the Data Mapping settings section, allowing users to control whether weight/dimensions are updated on re-imports (defaults to Yes).
* Improvement: Import activity log now shows imported weight alongside existing product details for verification.

= 1.2.3 - 2026-06-09 =
* Fix: eBay Item Specifics (specs) are now correctly saved as WooCommerce Product Attributes. Fixed a WooCommerce compatibility issue where custom attributes were silently discarded during save.
* Fix: Import now survives eBay API rate limits. When the daily API call limit is reached during a large import (6,000+ items), the sync pauses and auto-retries instead of restarting from scratch.
* Feature: Added "Resume Import" button for manual recovery after rate limiting. Progress is fully preserved across pauses.
* Improvement: Import log now shows the number of attributes detected per product.

= 1.2.2 - 2026-06-08 =
* Fix: Addressed an issue where custom variation attribute keys were incorrectly formatted, causing them to not link to parent product attributes.
* Improvement: Renamed the "eBay Sync Log" product data tab to "TCGiant Sync" to make export category and condition overrides more discoverable.
* Feature: Added a new setting to "Import Item Specifics as Product Tags", allowing all eBay Item Specifics to be imported as WooCommerce Product Tags in addition to Product Attributes.
* Improvement: Enhanced pricing sync logic to automatically detect Markdown Manager sales. If an original retail price is detected, WooCommerce will now correctly populate the Regular Price and Sale Price fields, automatically enabling the Sale badge.
* Fix: Corrected the 3-column layout on the dashboard and removed the hardcoded background color to seamlessly blend with the WordPress admin interface.

= 1.2.1 - 2026-06-08 =
* Feature: Complete UI/UX overhaul of the admin Dashboard.
* Feature: Added a persistent "Getting Started Checklist" onboarding widget for new users.
* Feature: Implemented a dedicated "Activity Logs" submenu page to view up to 1,000 historical events without dashboard clutter.
* Improvement: Truncated the Dashboard Activity Log to only display the 10 most recent events with a "View All" link to the new Logs page.
* Improvement: Reorganized dashboard layout into a clean 3-column "At a Glance" metrics row and a dedicated "Quick Actions" hub.

= 1.2.0 - 2026-06-08 =
* Feature: Full support for bidirectional Variation syncing! You can now map eBay Variations directly into WooCommerce Variable products. The plugin also properly pushes WooCommerce Variable products natively to eBay.
* Feature: The Mapper now dynamically extracts and maps *all* available eBay Item Specifics into WooCommerce Attributes (e.g. Brand, Material, Scale), ensuring robust native support for non-TCG categories.

= 1.1.7 - 2026-06-08 =
* Feature: Added automatic mapping of standard eBay PrimaryCategory to WooCommerce categories (useful for standard/free eBay accounts without Store Categories).
* Feature: eBay SKUs now accurately map from ISBN, UPC, or EAN values found in Product Listing Details or Item Specifics if the custom label is blank.
* Security: Implemented stricter capability checks for several informational admin AJAX endpoints.

= 1.1.6 - 2026-06-08 =
* Feature: Added an option to "Preserve Categories" in settings. Items in these WooCommerce categories will no longer be sent to the trash when they sell out on eBay; instead, their stock will be set to 0 and they will be unlinked from the old eBay listing.

= 1.1.5 - 2026-06-05 =
* Feature: Added Pushed and Pulled product metrics to the dashboard and telemetry payload to accurately differentiate sync direction.
* Fix: Addressed an issue where `thecardboardshop.com` and other instances reported as 'free' with '0 synced' by correcting JSON payload formatting and validation logic in the telemetry ping.

= 1.1.4 - 2026-06-05 =
* Feature: Added WooCommerce HPOS (High-Performance Order Storage) compatibility.
* Compatibility: Declared WooCommerce compatibility up to 10.0.
* Fix: Scheduled cron events on `init` to prevent early translation loading warnings.
* Fix: Corrected telemetry ping payload formatting.
* Security: Added permission callback to webhook REST API endpoint.
* Security: Sanitized autoloader file names to prevent path traversal.

= 1.1.3 - 2026-05-28 =
* Feature: Added Global Marketplace Support. You can now select your primary eBay regional site (e.g., UK, Canada, Australia) in settings to import and push listings internationally.

= 1.1.2 =
* Feature: Added modern horizontal tab navigation across all admin pages.
* UX: Implemented "Premium Empty States" for Recent Sales and Activity Logs.
* UX: Added CSS animations (barber pole effect) to progress bars during sync operations.
* UX: Custom dark-mode sleek scrollbars added to the Activity Log viewer.
* Polish: Form inputs and buttons updated with subtle hover and focus states.
* Feature: Added "Clear" button to Recent Sales widget on Dashboard.

= 1.1.1 - 2026-05-26 =
* Compatibility: Tested and verified for WordPress 7.0.
* Feature: Telemetry now includes license plan data.

= 1.1.0 - 2026-05-11 =
* Feature: Smart duplicate SKU resolution during import. Automatically detects if an eBay SKU is already used by a different product and appends the eBay Item ID to prevent WooCommerce overwriting.
* Feature: Activity Log is now fully responsive and available on all plugin pages (Dashboard, Import, Export).
* Feature: Visual feedback and auto-hide added to the "Recent Sales — Sync to eBay" dashboard widget upon successful manual push.
* Feature: Dashboard automatically filters out recently pushed orders so they don't persist after success.
* Feature: Added telemetry ping to track successful exports.

= 1.0.3 - 2026-04-29 =
* Feature: "Recent Sales — Sync to eBay" dashboard panel showing last 10 WooCommerce orders with a per-order Push to eBay button.
* Feature: Direct AJAX order sync — bypasses Action Scheduler and WP-Cron entirely to prevent accidental eBay→WooCommerce re-import side effects.
* Fix: eBay Trading API now correctly handles qty = 0 — instead of sending an invalid ReviseInventoryStatus request, the listing is ended via EndItem (NotAvailable).
* API: Added end_item() Trading API method for closing sold-out listings.

= 1.0.2 - 2026-04-24 =
* Feature: Added "Push to eBay" exporter module — create eBay listings directly from WooCommerce.
* Feature: Bulk push via WooCommerce Products list bulk action (Action Scheduler powered).
* Feature: Per-product Push to eBay button in the product edit screen (eBay Sync Log tab).
* Feature: Per-product eBay Category ID and Condition overrides with global defaults fallback.
* Feature: Export Defaults settings section — default Category ID, Condition, and Business Policy selectors.
* Feature: One-click Fetch Policies from eBay — populates Shipping, Return, and Payment policy dropdowns live.
* Feature: Smart re-push detection — updates existing listings via ReviseItem instead of creating duplicates.
* Feature: eBay Business Policies prerequisite notice with context-aware red warning / blue confirmation states.
* API: Added Trading API AddItem and ReviseItem support.
* API: Added REST Account API policy fetching (fulfillment, return, payment).

= 1.0.1 - 2026-04-24 =
* Fix: Suppressed API error logging for WooCommerce-only items that don't exist on eBay.
* Feature: Added WooCommerce category selectors to lock down inventory outgoing sync.

= 1.0.0 - 2026-04-09 =
* Initial release.
* eBay OAuth 2.0 connection via secure relay.
* Product import with automatic WooCommerce mapping.
* Inventory synchronization between eBay and WooCommerce.
* Freemium licensing with Pro upgrade path.
* Admin dashboard with live status polling and logs.
* Store category filtering for targeted imports.
* Marketplace Account Deletion notification support.

== Upgrade Notice ==

= 3.3.0 =
Adds product attributes as eBay item specifics on push — listings previously went up with almost none, hurting search placement and causing rejections in categories with required aspects. Required aspects are now checked before pushing and named if missing. Adds optional image hosting on eBay for sites eBay cannot reach. Also fixes background tasks stalling between scan batches, and never running at all on hosts with WP-Cron disabled.

= 3.2.1 =
Install this even if you already updated to 3.2.0. A small number of sites may have downloaded an incomplete 3.2.0 during a brief window, missing the product-duplication fix and the Settings layout fix, and would never have been offered a correction because the version number was the same. No other changes.

= 3.2.0 =
Data-safety and maintenance release. IMPORTANT: fixes product duplication copying the eBay listing link — duplicated products appeared already listed and pointed at the original's live eBay listing, so pressing Update or End Listing on a copy would have changed or closed the original's real listing. Products duplicated before this update are detected and blocked, with a new "Unlink from eBay" button to clear them. Variations removed from an eBay listing are now retired rather than deleted, so a bad eBay response can no longer destroy them. Fixes eBay order values being misread as $1.00 in some cases, order import missing anything past the first 100 per day, and the Listings page showing data frozen at install time. Also removes ~760 lines of dead code and hardens the activity log against public access on nginx.

= 3.1.4 =
Performance and reliability release, recommended for all stores and important for large ones. Adds a database index and removes several hot spots that made large syncs slow: a ~300KB option being autoloaded on every page request, duplicated media library records, and tens of thousands of individual log writes per import. eBay requests now retry transient network failures instead of failing an entire sync, and rate-limit responses are handled properly. Also fixes uninstall, which previously left all plugin data behind — including your stored eBay tokens.

= 3.1.3 =
Security and data-loss release — update immediately. Fixes an unauthenticated path that allowed the site's eBay credentials to be replaced, removes a hardcoded key that let forged eBay account-deletion requests erase customer data from orders, and stops imports from permanently ending live eBay listings. Also prevents a failed API call mid-scan from trashing large parts of your catalogue, stops image downloads being silently abandoned after a single timeout, and fixes relisting, which never worked. If eBay deletion notifications stop being accepted after updating, reconnect your eBay account from Settings.

= 1.7.2 =
Critical fix for sellers with multi-variant products: image imports were silently timing out for products with many variant photos. Images are now downloaded in safe chunks with automatic retry on failure. Also adds "Sync Specific Items" and "Re-sync Images Only" tools for fine-grained control over what gets updated. Recommended for all users, especially international sellers.

= 1.7.0 =
Major UX rework for Push to eBay! The old "Grading & Condition" and "TCGiant Sync" tabs are merged into a single "eBay Listing" tab with a guided 4-step wizard. New features include category auto-suggestion from your product title, visual card selectors, a pre-push readiness checklist, and smart validation that catches errors before they hit eBay. Plus performance improvements with smarter AJAX polling and transient caching.

= 1.6.3 =
New "Push to eBay" column on the WooCommerce Products list! See eBay category, item ID, push status, and errors for every product at a glance. Push or update any product to eBay with one click directly from the products table — no need to open each product individually.

= 1.6.2 =
Critical fix for coin and TCG sellers: Graded coins and several TCG graders were sending text strings instead of eBay's required numeric conditionDescriptorValueIds, causing export failures. All descriptor IDs are now verified against the eBay Metadata API. Also adds category browser name persistence — you'll now see the selected category name alongside the ID.

= 1.6.1 =
Major performance improvement for large eBay stores. Full syncs that previously took 2+ days for 13,000+ item stores now complete in 2-4 hours. Variation items are processed inline instead of requiring individual API calls, and image downloads no longer block the import queue. Recommended for all users with 1,000+ eBay listings.

= 1.4.8 =
Fixes the "No <Item.Location> exists" error that blocked all Push to eBay exports. Adds new Item Location and Postal Code fields to the export settings. After updating, go to TCGiant Sync → Settings → Push to eBay Settings and fill in your city/state and ZIP code.

= 1.4.7 =
Adds a visual "BIN" field to the WooCommerce Inventory tab for sellers routing their eBay Custom SKUs to the Bin Location custom field.

= 1.4.6 =
New import setting: "eBay SKU Maps To" lets you route eBay SKU to a Bin Location custom field instead of WooCommerce SKU — great for sellers who use eBay SKU for warehouse shelf codes. Also adds Free Shipping setup guidance for the baked-shipping workflow.

= 1.4.5 =
Critical fix for non-US marketplaces: weight and dimensions were being double-converted (metric treated as imperial). Also fixes missing Item Specifics and product images during scheduled delta syncs.

= 1.4.4 =
New feature: "Add eBay Shipping Cost to Product Price" setting. Enable it in Import Settings to automatically combine eBay item price + flat-rate shipping into a single WooCommerce price on import.

= 1.4.3 =
Critical fix for stores using category filters: imports were scanning all pages but matching zero items because eBay's API was not returning category data. This release also adds a contextual settings summary bar on the Import page and fixes the dashboard activity log width.

= 1.3.1 =
Security fix: the webhook signing key used for Marketplace Account Deletion notifications is now unique per installation. We recommend reconnecting to eBay via Settings to activate the new per-site key.

= 1.3.0 =
New feature: product weight and package dimensions (length × width × height) are now automatically imported from eBay and saved to your WooCommerce products. Values auto-convert to match your store's units. A new "Overwrite Weight & Dimensions" data mapping toggle controls re-import behavior.

= 1.2.3 =
Critical fix for large eBay stores (6,000+ listings): imports now survive API rate limits and resume from where they left off instead of restarting from scratch. Also fixes Product Attributes not saving after import.

= 1.1.0 =
Major reliability improvement for eBay stores with duplicate SKUs. The importer now gracefully detects duplicate SKUs and auto-appends eBay Item IDs to ensure all products are successfully imported without overwriting each other. Also includes UI improvements for manual sync feedback and a fully responsive Activity Log across all views.

= 1.0.3 =
Adds per-order "Sync to eBay" panel to the dashboard. Sold a card on WooCommerce? Click Push to eBay on that order to instantly update eBay stock — no queue, no cron. Also fixes a crash when stock hits 0 (now correctly ends the eBay listing instead of sending an invalid qty=0 API call).

= 1.0.2 =
Major feature release: Push to eBay exporter module. Create and update eBay listings directly from WooCommerce — individually or in bulk. Requires eBay Business Policies to be enabled on your seller account.

= 1.0.1 =
Allows strict WooCommerce-first category sync mapping.

= 1.0.0 =
Initial release of TCGiant Sync.
