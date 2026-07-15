=== TCGiant Sync ===
Contributors: tcgiantteam
Tags: ebay, woocommerce, sync, inventory, tcg
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.2
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
* **Push to eBay** — create new eBay listings directly from WooCommerce (single product or bulk)
* Per-product eBay Category and Condition overrides
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

Yes! As of version 1.0.2, TCGiant Sync includes a full export module. Configure your default eBay Category ID and Business Policies in TCGiant Sync → Settings, then use the "Push to eBay" button on any product edit screen, or select multiple products and use the bulk action on the WooCommerce Products list.

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
6. Per-product Push to eBay button with Category and Condition overrides.

== Changelog ==

= 1.6.2 - 2026-07-15 =
* Fix: Resolved all Coin ConditionDescriptor errors when pushing graded and ungraded coins to eBay. Grader, Letter Grade, and Numeric Grade values now use the correct eBay conditionDescriptorValueIds instead of raw text strings.
* Fix: Ungraded Coin Condition now uses eBay descriptor Name 2 with correct value IDs instead of the TCG descriptor Name 40001.
* Fix: Coin Certification Number now uses descriptor Name 5 (not 2) with a 20-character max per eBay requirements.
* Improvement: Added all 16 eBay-recognized coin grading companies with correct numeric IDs.
* Improvement: Added complete Sheldon scale numeric grade ID mapping (70 through 1).

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

= 1.6.2 =
Critical fix for coin sellers: Graded and ungraded coins were failing to push to eBay because the plugin was sending text strings (NGC, MS, Uncirculated) instead of eBay's required numeric conditionDescriptorValueIds. All coin graders, grades, and ungraded conditions now use the correct eBay Metadata API values. If you sell coins, this update is required.

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
