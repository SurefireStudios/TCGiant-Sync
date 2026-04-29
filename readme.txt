=== TCGiant Sync ===
Contributors: tcgiantteam
Tags: ebay, woocommerce, sync, inventory, tcg
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.3
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

TCGiant Sync is optimized for graded trading card game (TCG) collectibles, including PSA and BGS graded cards, but works with any eBay listing.

== Screenshots ==

1. Dashboard overview showing sync status and statistics.
2. eBay connection settings with one-click OAuth.
3. Category filter selector for targeted imports.
4. Live activity log with real-time updates.
5. Push to eBay export settings with Business Policy selector.
6. Per-product Push to eBay button with Category and Condition overrides.

== Changelog ==

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

= 1.0.3 =
Adds per-order "Sync to eBay" panel to the dashboard. Sold a card on WooCommerce? Click Push to eBay on that order to instantly update eBay stock — no queue, no cron. Also fixes a crash when stock hits 0 (now correctly ends the eBay listing instead of sending an invalid qty=0 API call).

= 1.0.2 =
Major feature release: Push to eBay exporter module. Create and update eBay listings directly from WooCommerce — individually or in bulk. Requires eBay Business Policies to be enabled on your seller account.

= 1.0.1 =
Allows strict WooCommerce-first category sync mapping.

= 1.0.0 =
Initial release of TCGiant Sync.
