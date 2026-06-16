# Troubleshooting and Logs

TCGiant Sync includes built-in diagnostics and detailed logging to help you understand exactly what's happening and resolve issues quickly.

---

## The Activity Log

Every action the plugin takes — importing a product, pushing to eBay, syncing stock, hitting an API error — is recorded in the Activity Log with a timestamp and color-coded severity.

### Where to Find Logs

| Location | What You See |
|---|---|
| **Dashboard → Activity Log** | The 10 most recent events (quick overview) |
| **TCGiant Sync → Logs** | Full history — up to 1,000 events with detail |

### Reading Log Entries

Each log entry shows:

```
[OK] 2026-06-16 14:22:31 — Imported: "PSA 10 Charizard VMAX" -> WC #1234 ($299.99, Qty: 1, 8 attrs, 0.50lbs)
```

- **[OK]** = Success (green) | **[X]** = Error (red) | **[!]** = Warning (amber) | **[Log]** = Info (gray)
- Product name, WooCommerce product ID, price, stock, attribute count, and weight (if available)

> **💡 Tip:** The log automatically rotates to prevent database bloat. Older entries are pruned to keep the log under 1,000 entries.

---

## System Health Dashboard

The **Dashboard** page includes a System Health panel that shows:

| Indicator | What It Means |
|---|---|
| **eBay Connection** | Whether your OAuth token is active |
| **Connected Store** | Your eBay store name |
| **Token Health** | Time until your access token expires (auto-refreshes) |
| **Auto-Sync Scheduler** | Whether a cron schedule is active and when the next run is |

If any indicator is red, check the specific issue below.

---

## Common Issues

### 1. "OAuth Token Expired" or "Unauthorized" Errors

eBay access tokens expire every 2 hours, but the plugin refreshes them automatically. If you see persistent auth errors:

- **Fix:** Go to **Settings → Connect to eBay** and re-authenticate. This also activates the latest security improvements.
- **Why it happens:** Occasionally, eBay revokes tokens during account changes or security updates.

### 2. Products Failing to Push to eBay

The Activity Log will show the specific eBay error. Common causes:

| Error | Fix |
|---|---|
| Missing Item Specifics | Some eBay categories require specific attributes (Brand, MPN, etc.). Add them as Product Attributes in WooCommerce. |
| Missing Business Policies | Go to Settings → Export Defaults → Fetch Policies and assign all three policies. |
| 403 Permission Denied | Your token lacks the `sell.account` scope. Reconnect to eBay via Settings. |
| Duplicate listing | The product already has an eBay Item ID. The plugin should auto-detect this — if not, check for duplicate `_ebay_item_id` meta values. |

### 3. Import Stuck at "Importing" but Nothing Is Happening

This can occur if Action Scheduler jobs stalled:

1. Check **TCGiant Sync → Dashboard** for the "Processing Queue" button.
2. Click it to manually trigger pending Action Scheduler jobs.
3. If that doesn't work, click **Stop Sync** and then start a new import.

> **💡 Tip:** The dashboard has a self-healing mechanism — if it detects no pending jobs but the status says "importing," it will auto-complete.

### 4. Rate Limited (Large Imports, 5,000+ Items)

If you're importing a large inventory and see an amber **"Rate Limited"** status:

- **This is normal.** eBay allows ~5,000 API calls/day. The plugin automatically pauses, preserves all progress, and retries.
- **No action needed** — just wait for the limit to reset (usually within hours).
- **Optional:** Click the **Resume Import** button on the Import page to manually retry.

See the [Import from eBay → Large Inventory Tips](Import-from-eBay#large-inventory-tips-5000-items) section for detailed time estimates.

### 5. Automated Sync Isn't Running

If stock isn't updating automatically on a Pro license:

1. Check **System Health** on the Dashboard — is the scheduler showing "Active"?
2. Verify your sync schedule is set to something other than "Manual" in Settings.
3. **WordPress cron requires site traffic to trigger.** If your site gets very few visitors, cron jobs may be delayed.

**Fix for low-traffic sites:** Set up a real server-side cron job:

```
*/5 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

Add this in your web host's control panel (cPanel → Cron Jobs, Plesk → Scheduled Tasks, etc.) to trigger WordPress cron every 5 minutes regardless of traffic.

### 6. Duplicate Products After Import

If you see duplicate WooCommerce products:

- The plugin uses eBay Item IDs to prevent duplicates. If you previously imported products with a different plugin or manually, they won't have the `_ebay_item_id` meta key — the plugin won't know they already exist.
- **Fix:** Either delete the old duplicates, or manually add the `_ebay_item_id` custom field to the existing products with the correct eBay Item ID.
- For duplicate SKUs, the plugin automatically appends the eBay Item ID to create a unique SKU.

### 7. Weight/Dimensions Not Importing

If your eBay listings show weight and dimensions on eBay but they're not appearing in WooCommerce:

- Ensure **"Overwrite Weight & Dimensions"** is set to **Yes** in Settings → Data Mapping.
- Weight and dimensions come from eBay's `ShippingPackageDetails`. If the seller didn't fill these in on the eBay listing, there's nothing to import.
- Check the import log — it shows the weight when detected (e.g., `0.50lbs`).

---

## Tips for a Smooth Experience

1. **Always check the logs first.** 90% of issues are explained in plain English right in the Activity Log.
2. **Start with a small category.** If you have a huge inventory, test with one small category first to verify everything maps correctly before importing everything.
3. **Set Data Mapping rules before your first import.** Decide which platform "wins" for each field type before importing — it's harder to fix after 5,000 products are imported.
4. **Use Preserve Categories** for items you want to keep on your website even after they sell out on eBay.
5. **Back up your database** before large imports, just in case.

---

## Getting Support

If you've checked the logs and can't resolve an issue:

1. Go to **TCGiant Sync → Logs**.
2. Copy the recent error messages.
3. Email us at **hello@tcgiant.com** with:
   - Your site URL
   - The error messages from the log
   - What you were trying to do when the issue occurred

We typically respond within 24 hours.
