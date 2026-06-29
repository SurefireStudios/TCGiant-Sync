# Import from eBay

The Import feature pulls your active eBay listings into WooCommerce as fully formed products — complete with titles, descriptions, images, prices, stock levels, attributes, variations, weight, dimensions, and categories.

---

## How It Works

```
eBay Store → Plugin fetches listing data via API → WooCommerce Products created/updated
```

Each eBay listing is matched to a WooCommerce product using its unique eBay Item ID. If the product already exists, the plugin updates it (based on your Data Mapping rules). If it's new, the plugin creates it.

---

## Running a Manual Import

1. Navigate to **TCGiant Sync → Import from eBay**.
2. Click **Fetch Inventory**.
3. The plugin queries eBay for all active listings in your selected categories.
4. A progress bar tracks the import in real-time.
5. Once complete, you'll see a summary of products created, updated, and any errors.

> **💡 Tip:** You can navigate away from the page — the import runs in the background via Action Scheduler and won't stop if you close the browser.

---

## Scheduling Automated Imports (Pro)

Pro users can set the plugin to import automatically:

1. Go to **TCGiant Sync → Settings**.
2. Under **Automation & Sync Schedule**, select a frequency:
   - Every 15 Minutes
   - Hourly
   - Twice Daily
   - Daily
3. Click **Save Settings**.

The plugin will poll eBay at the chosen interval, import any new listings, and update stock/price for existing ones.

> **💡 Tip:** WordPress cron relies on site visitors to trigger. If your site has very low traffic, set up a server-side cron job to hit `wp-cron.php` every few minutes for reliable scheduling. Your web host's control panel (cPanel, Plesk, etc.) usually has a "Cron Jobs" section for this.

---

## What Gets Imported

| eBay Field | WooCommerce Field | Notes |
|---|---|---|
| Title | Product Name | Controllable via "Overwrite Title" setting |
| Description | Product Description | Controllable via "Overwrite Description" setting |
| Current Price | Regular Price | Sale prices from Markdown Manager are detected automatically |
| Quantity Available | Stock Quantity | Always synced from eBay |
| Gallery Images | Product Images | Controllable via "Overwrite Images" setting |
| Item Specifics | Product Attributes | e.g., Brand, Material, Scale, Graded, etc. |
| eBay Variations | WooCommerce Variations | Full Variable Product support |
| Store Categories | WooCommerce Categories | Mapped automatically |
| SKU / ISBN / UPC / EAN | Product SKU | Smart fallback chain for SKU detection |
| Shipping Weight | Product Weight | Auto-converts units (lbs ↔ kg) |
| Package Dimensions | Product Dimensions (L×W×H) | Auto-converts units (in ↔ cm) |

---

## Large Inventory Tips (5,000+ Items)

If you have a large eBay store, here's what to expect:

### eBay API Rate Limits

eBay's Trading API allows approximately **5,000 API calls per day** (standard limit). Since each product import requires one `GetItem` call, this means:

| Inventory Size | Estimated Import Time |
|---|---|
| Under 1,000 | A few hours |
| 1,000 – 5,000 | 1 day |
| 5,000 – 10,000 | 2 days |
| 10,000 – 15,000 | 2–3 days |
| 15,000+ | 3+ days |

### What Happens When the Limit Is Hit

**Don't worry — the plugin handles this automatically:**

1. ⚡ When the daily API limit is reached, the plugin **pauses** and shows an amber "Rate Limited" status.
2. 🔄 All progress is **preserved** — nothing restarts from scratch.
3. ⏰ The plugin **auto-schedules a retry** in 5 minutes and keeps checking until the limit resets.
4. 🔘 A **"Resume Import"** button is also available on the Import page if you want to manually kick it.

> **💡 Tip:** You'll see the progress bar advance in chunks over a few days. This is completely normal. Once the initial import is complete, ongoing syncs are much faster because only changes (new/ended listings, stock/price updates) are processed — not the full catalog.

### Optimizing Large Imports

- **Filter by category:** Only import the eBay categories you actually need. This reduces API calls dramatically.
- **Run your first import overnight:** Start it in the evening and let it run through the night.
- **Check the logs:** Go to **TCGiant Sync → Logs** to see exactly which items have been imported and spot any issues early.

---

## Duplicate Prevention

The plugin uses multiple safeguards to prevent duplicate products:

1. **eBay Item ID matching:** Each product is tagged with `_ebay_item_id` metadata. If a product with that ID already exists, it's updated instead of duplicated.
2. **Smart SKU resolution:** If an eBay SKU is already in use by a different product, the plugin appends the eBay Item ID to create a unique SKU (e.g., `SKU-123-EBAY456789`).

---

## When eBay Listings End

When a listing ends on eBay (sold out, manually ended, or expired):

- By default, the corresponding WooCommerce product is **moved to trash** during the next sync.
- If you've configured **Preserve Categories** in Settings, products in those WooCommerce categories will instead have their stock set to 0 and be unlinked from the old listing — they stay published.

> **💡 Tip:** "Preserve Categories" is great for items you want to keep on your website even after the eBay listing ends — like products you only sell on your own site, or items you want to relist later.

---

## Shipping: eBay vs WooCommerce

**Your eBay shipping settings and WooCommerce shipping settings are completely independent.** The plugin imports product data (title, price, stock, etc.) but does NOT import or sync shipping methods or costs.

This means you can:
- ✅ Charge shipping on eBay but offer free shipping on your website
- ✅ Use flat rate on eBay but weight-based shipping on WooCommerce
- ✅ Have completely different shipping zones and methods on each platform

Your WooCommerce shipping zones and methods work exactly as configured — the plugin won't touch them.

### Baking eBay Shipping into Price

If you enable **"Add eBay Shipping Cost to Product Price"** in Settings, the eBay flat-rate shipping cost is added directly to the WooCommerce product price. For example, a $10 item with $5 eBay shipping becomes a $15 WooCommerce product.

To offer free shipping at checkout (since shipping is already in the price), go to **WooCommerce → Settings → Shipping → your zone → Add Shipping Method → "Free Shipping"**. Customers see one all-inclusive price with no separate shipping charge.

---

## SKU Handling

By default, the eBay SKU maps to the WooCommerce Product SKU with a smart fallback chain: eBay SKU → ISBN/UPC/EAN → EBAY-{ItemID}.

If your eBay SKU field contains **warehouse/shelf location codes** (e.g., "A3-B7") rather than product identifiers, you can change the **"eBay SKU Maps To"** setting to **"Bin Location"**. This stores the eBay SKU as a `_bin_location` custom field instead, and the WooCommerce SKU falls back to ISBN/UPC/EAN or EBAY-{ItemID}.

---

## Next Steps

- **[Push to eBay](Push-to-eBay)** — List WooCommerce products on eBay
- **[Settings and Data Mapping](Settings-and-Data-Mapping)** — Fine-tune what gets overwritten on re-imports
- **[Troubleshooting and Logs](Troubleshooting-and-Logs)** — Diagnose import issues
