# Settings and Data Mapping

This page covers every setting available in **TCGiant Sync → Settings**, organized by section.

---

## eBay Connection

| Setting | Description |
|---|---|
| **Connect to eBay** | Initiates the OAuth 2.0 flow to securely connect your eBay seller account. |
| **Disconnect** | Removes stored tokens and disconnects from eBay. Products remain in WooCommerce. |
| **eBay Site ID** | Your primary eBay regional marketplace (US, UK, Canada, Australia, Germany, etc.). Determines API endpoints and category structures. |

> **💡 Tip:** If you switch eBay regions, reconnect to eBay afterward to ensure your token has the correct marketplace scope.

---

## Import Settings

### Category Selection

Choose which eBay Store Categories to import. Only listings within checked categories will be pulled into WooCommerce.

- **eBay Store Categories:** Available if you have an eBay Store subscription. Click **Fetch Categories** to load them.
- **Primary Categories:** If you don't have an eBay Store, the plugin automatically falls back to eBay's standard Primary Categories.

### WooCommerce Category Mapping

Map eBay categories to specific WooCommerce categories. When a product is imported from a mapped eBay category, it's automatically placed into the corresponding WooCommerce category.

### Import Item Specifics as Product Tags

When enabled, all eBay Item Specifics (e.g., "Brand: Pokemon", "Set: Base Set") are also imported as **WooCommerce Product Tags** in addition to Product Attributes. This is great for:
- SEO (tags appear in URLs and are indexable)
- WooCommerce filtering and search
- Faceted navigation on your storefront

---

## Data Mapping (Re-imports)

These toggles control what happens when an **existing** product is re-synced. They don't affect the first import — on first import, all fields are always populated.

| Setting | Default | eBay Wins (Yes) | WooCommerce Wins (No) |
|---|---|---|---|
| **Overwrite Title** | No | Title is updated from eBay every sync | Your WooCommerce title is preserved |
| **Overwrite Description** | No | Description is pulled from eBay | Your WooCommerce description is preserved |
| **Overwrite Price** | Yes | Price always matches eBay | You can set independent WooCommerce pricing |
| **Overwrite Images** | No | Images are re-downloaded from eBay | Your WooCommerce images are preserved |
| **Overwrite Categories & Tags** | No | Categories are re-mapped from eBay | Your WooCommerce categories are preserved |
| **Overwrite Weight & Dimensions** | Yes | Weight/dimensions updated from eBay | Your manual WooCommerce values are preserved |

> **💡 Tip:** A common setup is: Price and Weight = "Yes" (always accurate from eBay), everything else = "No" (so you can customize your WooCommerce storefront without edits being overwritten).

### Sale Price Detection

The plugin automatically detects eBay Markdown Manager sales. If an item has both an original price and a discounted price on eBay, WooCommerce's **Regular Price** and **Sale Price** fields are populated accordingly — automatically enabling the "Sale" badge on your storefront.

---

## Preserve Categories

Products in these WooCommerce categories are **protected from deletion** when their eBay listing ends.

Normal behavior: When an eBay listing ends (sold out, expired, or manually ended), the corresponding WooCommerce product is moved to trash during the next sync.

With Preserve Categories: Instead of being trashed, the product's stock is set to 0 and it's unlinked from the old eBay listing. The product stays published in WooCommerce.

**Use cases:**
- Products you also sell through channels other than eBay
- Items you want to keep visible on your website as "out of stock" rather than disappearing
- Products you plan to relist on eBay later

---

## Automation & Sync Schedule

| Schedule | Tier | Description |
|---|---|---|
| Manual | Free | Import only when you click "Fetch Inventory" |
| Every 15 Minutes | Pro | Near real-time sync |
| Hourly | Pro | Good balance of freshness and API usage |
| Twice Daily | Pro | Light API usage, good for stable inventories |
| Daily | Pro | Minimal API usage |

> **💡 Tip:** For high-volume stores (1,000+ items sold per week), "Every 15 Minutes" or "Hourly" is recommended. For stores with slower turnover, "Daily" is sufficient and conserves API calls.

### Order Sync (Bidirectional Stock)

When enabled, WooCommerce sales automatically reduce stock on eBay. Combined with eBay→WooCommerce stock sync, this creates a true bidirectional inventory system.

---

## Export Defaults (Push to eBay)

These settings apply to all products pushed to eBay unless overridden on the individual product.

| Setting | Description |
|---|---|
| **Default eBay Category ID** | The eBay category ID for new listings |
| **Default Condition** | New, Used, Like New, Very Good, Good, Acceptable |
| **Shipping Policy** | Your eBay shipping Business Policy |
| **Return Policy** | Your eBay return Business Policy |
| **Payment Policy** | Your eBay payment Business Policy |

Click **Fetch Policies** to load your current eBay Business Policies into the dropdowns.

> **💡 Tip:** You can override any of these on a per-product basis in the **TCGiant Sync** tab on the product edit screen.

---

## License

| Field | Description |
|---|---|
| **License Key** | Enter your Pro license key to unlock unlimited imports and scheduled automation |
| **Activate / Deactivate** | Toggle your license on or off |

Free tier: Up to 50 imported products, manual import only, unlimited Push to eBay.

---

## Next Steps

- **[Import from eBay](Import-from-eBay)** — Start pulling your listings
- **[Push to eBay](Push-to-eBay)** — Create eBay listings from WooCommerce
- **[Troubleshooting and Logs](Troubleshooting-and-Logs)** — Diagnose any issues
