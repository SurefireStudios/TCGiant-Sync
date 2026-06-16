# Push to eBay

The Push to eBay feature lets you create eBay listings directly from WooCommerce. Build your products in WordPress — titles, descriptions, images, variations — and push them live to eBay with a single click or in bulk.

---

## Before You Start

### Prerequisites

1. **eBay Business Policies** must be enabled on your seller account. Most accounts have this already. If not, enroll at [bizpolicy.ebay.com](https://bizpolicy.ebay.com).
2. **Connect to eBay** via TCGiant Sync → Settings (if you haven't already).

### Setting Up Business Policies

Every eBay listing needs a Shipping, Return, and Payment policy attached to it. Here's how to configure them:

1. Go to **TCGiant Sync → Settings**.
2. Scroll to the **Export Defaults** section.
3. Click **Fetch Policies** — this pulls your existing eBay Business Policies live from your account.
4. Select a default **Shipping Policy**, **Return Policy**, and **Payment Policy** from the dropdowns.
5. Set a default **eBay Category ID** (you can override this per product).
6. Set a default **Condition** (New, Used, etc.).
7. Click **Save Settings**.

> **💡 Tip:** If "Fetch Policies" shows a 403 error, your OAuth token may have been issued before the account scope was added. Go to Settings → Reconnect to eBay, then try again.

---

## Pushing a Single Product

1. Go to **Products → All Products** and open any product.
2. Scroll down to the **Product Data** section.
3. Click the **TCGiant Sync** tab.
4. Optionally override the default eBay Category, Condition, or Business Policies for this specific item.
5. Click **Push to eBay**.

The product is queued for export and processed in the background. Check **TCGiant Sync → Dashboard** or **Logs** for the result.

Once pushed, the product is assigned an `_ebay_item_id` — you'll see the live eBay listing URL right in the TCGiant Sync tab.

---

## Pushing in Bulk

For larger batches:

1. Go to **Products → All Products**.
2. Select the products you want to push (checkboxes).
3. Open the **Bulk Actions** dropdown.
4. Select **Push to eBay via TCGiant Sync**.
5. Click **Apply**.

All selected products are queued and pushed sequentially using the default Export settings.

> **💡 Tip:** Bulk push uses Action Scheduler, which processes items in the background. You don't need to keep the page open.

---

## Variable Products (Variations)

TCGiant Sync fully supports pushing WooCommerce Variable Products to eBay as multi-variation listings:

- Each WooCommerce variation becomes an eBay variation (e.g., Size: Small/Medium/Large).
- Variation-specific prices, stock quantities, and SKUs are all mapped correctly.
- Images are assigned to the parent listing.

> **💡 Tip:** When importing eBay variations into WooCommerce, they're also correctly mapped as Variable Products with individual variation attributes.

---

## Smart Revision Detection

If a WooCommerce product already has an eBay Item ID (from a previous push or import), pushing it again will **not** create a duplicate listing.

Instead, the plugin uses eBay's `ReviseItem` API to update the existing listing with the latest:
- Title and Description
- Price
- Stock Quantity
- Images

This means you can safely use "Push to eBay" as an "update" button — it always does the right thing.

---

## Per-Product Overrides

Every product can override the global Export Defaults:

| Override | Description |
|---|---|
| eBay Category ID | Use a different eBay category for this specific product |
| Condition | Override the default condition (New, Used, Like New, etc.) |
| Shipping Policy | Use a different shipping policy |
| Return Policy | Use a different return policy |
| Payment Policy | Use a different payment policy |

Set these in the **TCGiant Sync** tab on any product edit screen.

---

## What Gets Pushed

| WooCommerce Field | eBay Listing Field |
|---|---|
| Product Name | Title |
| Product Description | Description (HTML) |
| Regular Price | Start Price |
| Stock Quantity | Quantity Available |
| Product Images | Gallery Photos |
| SKU | Custom Label |
| Product Attributes | Item Specifics |
| Variations | Multi-Variation Listing |

---

## Next Steps

- **[Import from eBay](Import-from-eBay)** — Pull eBay listings into WooCommerce
- **[Settings and Data Mapping](Settings-and-Data-Mapping)** — Configure export defaults and policies
- **[Troubleshooting and Logs](Troubleshooting-and-Logs)** — Diagnose push errors
