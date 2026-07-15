# TCGiant Sync

[![Version](https://img.shields.io/badge/version-1.6.2-blue.svg)](https://wordpress.org/plugins/tcgiant-sync/)
[![License](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Requires PHP](https://img.shields.io/badge/PHP-7.4%2B-green.svg)](#)
[![Tested up to](https://img.shields.io/badge/WordPress-7.0-blue.svg)](#)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple.svg)](#)

**TCGiant Sync** is a bidirectional eBay ↔ WooCommerce sync plugin built for trading card game sellers. Import your eBay listings into WooCommerce *and* push WooCommerce products back to eBay as live listings — all from one dashboard.

---

## 🔄 How It Works

```
eBay Store  ──→  Import from eBay  ──→  WooCommerce
WooCommerce ──→  Push to eBay     ──→  eBay Store
```

No matter which platform you start from, TCGiant Sync keeps both sides in sync. Sell on eBay and stock drops in WooCommerce. Sell on your site and stock drops on eBay. Create new WooCommerce products and push them live to eBay in one click.

---

## 🚀 Features

### Import: eBay → WooCommerce
| Feature | Free | Pro |
|---|---|---|
| Import active eBay listings to WooCommerce | ✅ (up to 50) | ✅ Unlimited |
| Automatic product mapping & category organization | ✅ | ✅ |
| eBay store category filter (import only what you want) | ✅ | ✅ |
| WooCommerce category sync mapping | ✅ | ✅ |
| Auto-sync on a schedule (15min / hourly / daily) | ✅ | ✅ |
| Real-time stock reduction when sold on eBay | ✅ | ✅ |
| Data mapping rules (overwrite title, price, images, etc.) | ✅ | ✅ |
| Import weight & package dimensions from eBay | ✅ | ✅ |
| Auto-convert metric/imperial units per marketplace | ✅ | ✅ |
| Bake eBay shipping cost into product price | ✅ | ✅ |
| Map eBay SKU to Bin Location custom field | ✅ | ✅ |
| Prune sold/ended listings from WooCommerce | ✅ | ✅ |

### Push: WooCommerce → eBay *(New in v1.0.2)*
| Feature | Free | Pro |
|---|---|---|
| Push single WooCommerce product to eBay | ✅ | ✅ |
| Bulk push via Products list bulk action | ✅ | ✅ |
| Smart update detection (AddItem vs ReviseItem) | ✅ | ✅ |
| Curated TCG category dropdown + custom ID override | ✅ | ✅ |
| Per-product Category & Condition overrides | ✅ | ✅ |
| eBay ConditionDescriptor support (Graded/Ungraded for TCG & Coins) | ✅ | ✅ |
| eBay Business Policy integration (Shipping/Returns/Payment) | ✅ | ✅ |
| Async background processing via Action Scheduler | ✅ | ✅ |

### General
- **Secure OAuth 2.0** connection via centralized relay — credentials never stored
- **Live dashboard** with health checks, sync status, and activity log
- **Multi-page admin** — Dashboard, Import, Push to eBay, Settings
- **Detailed activity logs** for troubleshooting

---

## 📋 Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| WooCommerce | 5.0+ |
| eBay Seller Account | Required |
| eBay Business Policies | Required for Push to eBay |

---

## 🛠️ Installation

1. Download the latest `tcgiant-sync.zip` from [Releases](../../releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and click **Activate**.

---

## ✨ Getting Started

### Connecting to eBay
1. Go to **TCGiant Sync → Settings** in your WordPress admin.
2. Click **Connect to eBay** and authorize via eBay's OAuth flow.

### Importing from eBay
1. Go to **TCGiant Sync → Import from eBay**.
2. Click **Fetch Inventory** to scan your eBay store and import listings into WooCommerce.
3. *(Optional)* Set a schedule in Settings for automatic imports.

### Pushing to eBay *(New in v1.0.2)*
1. Go to **TCGiant Sync → Settings** → configure your default eBay Category and click **Fetch Policies** to load your Business Policies.
2. Set the **Condition Type** — choose "Graded" (with Professional Grader, Grade, and optional Cert Number) or "Ungraded" (with card/coin condition) for TCG and Coins categories. Use "None (Legacy)" for other categories.
3. Save settings.
4. **Single product:** Open any WooCommerce product → **eBay Sync tab** → click **Push to eBay**. You can override the condition type per-product.
5. **Bulk:** Go to **WooCommerce → Products** → select products → **Bulk Actions → Push to eBay**.

> ⚠️ **Push to eBay requires eBay Business Policies** to be enabled on your seller account (Shipping, Returns, and Payment policies). Most seller accounts have this enabled automatically.

---

## ❓ Frequently Asked Questions

**Does this work for eBay-first OR WooCommerce-first setups?**  
Both. Import from eBay to WooCommerce, push from WooCommerce to eBay, or do both simultaneously. The plugin handles whichever direction you need.

**What is smart update detection?**  
When you push a product that already has an eBay Item ID (from a previous push or import), the plugin automatically uses `ReviseItem` to update the existing listing instead of creating a duplicate.

**Does this plugin require WooCommerce?**  
Yes. WooCommerce must be installed and active.

**How many products can I sync for free?**  
The free tier supports up to 50 active imported products. Push to eBay has no limit. Upgrade to Pro for unlimited imports.

**Is my eBay data secure?**  
Yes. TCGiant Sync uses eBay's official OAuth 2.0. Your credentials are never stored in plain text and are handled through a secure server-side relay.

**What happens when an item sells?**  
Whether a sale happens on eBay or WooCommerce, stock is adjusted on both platforms automatically to prevent overselling.

---

## 📦 Releases

| Version | Date | Key Changes |
|---------|------|-------------|
| **1.6.2** | 2026-07-15 | Fix all Coin ConditionDescriptor errors — correct numeric value IDs for graders, grades, and ungraded conditions |
| **1.6.1** | 2026-07-15 | Major performance fix for large stores (13,000+ items). Inline variation processing, async image downloads, reduced scheduling delays |
| **1.6.0** | 2026-07-10 | Browse eBay Categories drill-down, Saved Custom Categories, Coin ConditionDescriptor fix |
| **1.5.5** | 2026-07-10 | Fixed fatal error on settings and export pages caused by missing GRADERS constant |
| **1.5.4** | 2026-07-10 | Added Standard eBay Categories filter, fixed deep subcategory condition API errors, and added UI error handling for category fetch |
| **1.5.0** | 2026-07-08 | eBay ConditionDescriptor support for Trading Cards & Coins (Graded/Ungraded), Coins categories added to export dropdown |
| **1.4.8** | 2026-07-08 | Fix Item.Location export error, add Location & Postal Code settings |
| **1.4.7** | 2026-07-01 | Added native WooCommerce BIN field to Inventory tab for eBay SKU to Bin mapping |
| **1.4.6** | 2026-06-29 | eBay SKU → Bin Location mapping, Free Shipping guidance for baked shipping |
| **1.4.5** | 2026-06-29 | Fix UK/metric weight & dimensions, fix delta sync specs & photos |
| **1.4.4** | 2026-06-22 | Bake eBay shipping cost into WooCommerce product price |
| **1.4.3** | 2026-06-22 | Fix category filter matching, settings summary bar on Import page |
| **1.4.0–1.4.2** | 2026-06-18 | Delta Sync for scheduled imports, inline processing, 94% API reduction |
| **1.3.1** | 2026-06-16 | Security: per-installation unique webhook signing keys |
| **1.3.0** | 2026-06-16 | Import product weight & dimensions from eBay, new Data Mapping toggle |
| **1.2.3** | 2026-06-09 | Fixed Item Specifics/Attributes saving, rate limit auto-retry for large imports |
| **1.1.6** | 2026-06-08 | Added an option to "Preserve Categories" in settings |
| **1.1.5** | 2026-06-05 | Added Pushed vs Pulled metrics tracking, fixed telemetry data format bug |
| **1.1.4** | 2026-06-05 | WooCommerce HPOS and 10.0 Compatibility, Security enhancements |
| **1.1.3** | 2026-05-28 | Added Global Marketplace Support (eBay UK, CA, AU, etc.) |
| **1.1.2** | 2026-05-26 | Complete UI/UX overhaul. Added global tab navigation, animated progress bars, premium empty states, dark-mode custom scrollbars for logs, input field polish, and "Clear Recent Sales" functionality. |
| **1.1.1** | 2026-05-26 | Verified WP 7.0 compatibility. Fixed total sync counter in dashboard telemetry. License plan is now tracked securely. |
| [v1.1.0](../../releases/tag/v1.1.0) | 2026-05-11 | Smart duplicate SKU resolution, Activity Log improvements, and sync feedback UI |
| [v1.0.3](../../releases/tag/v1.0.3) | 2026-04-29 | Per-order sale sync dashboard panel, EndItem for qty=0 fix |
| [v1.0.2](../../releases/tag/v1.0.2) | 2026-04-24 | Push to eBay, multi-page dashboard, Business Policy integration |
| [v1.0.1](../../releases/tag/v1.0.1) | 2026-04-24 | WooCommerce category sync, silence 404s for local-only products |
| [v1.0.0](../../releases/tag/v1.0.0) | 2026-04-09 | Initial release |

---

## ⚖️ License

This project is licensed under the **GPL-2.0-or-later** license — see the [`LICENSE`](LICENSE) file for details.

*Upgrade to Pro at [tcgiant.com/pro](https://tcgiant.com/pro).*
