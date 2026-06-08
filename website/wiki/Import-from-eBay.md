# Import from eBay

The Import functionality allows you to automatically pull your active eBay listings into your WooCommerce store as fully formatted products. Stock levels are perfectly mapped, and variations (size, color, condition) are brought over seamlessly.

---

## Setting Up Your Import

Before running an import, you must tell the plugin which eBay store categories you want to pull.

1. Navigate to **TCGiant Sync > Settings**.
2. Scroll to the **Import Settings** panel.
3. **Category Selection:** You will see a list of your custom eBay Store Categories. Check the boxes for the categories you want to import. Any listings outside of these categories will be ignored.
4. Click **Save Settings**.

## Running a Manual Import

If you want to pull listings immediately (or if you are on the Free tier):
1. Navigate to **TCGiant Sync > Import**.
2. Click the **Fetch Inventory** button.
3. The plugin will query eBay for all active listings in your selected categories.
4. A progress bar will appear. It generally takes a few seconds to process 50 items.
5. Once complete, you will see a summary of how many new products were created, and how many existing products had their stock updated.

## Scheduling Automated Imports (Pro Feature)

If you are a Pro user, you don't need to click "Fetch Inventory" manually. You can set the plugin to run imports automatically in the background using WordPress Cron.

1. Navigate to **TCGiant Sync > Settings**.
2. Under the **Automation & Sync Schedule** section, select a sync frequency:
   - **Manual (Free)**
   - **Every 15 Minutes (Pro)**
   - **Hourly (Pro)**
   - **Twice Daily (Pro)**
   - **Daily (Pro)**
3. Click **Save Settings**.

The plugin will now poll eBay at the specified interval, pull in any brand new listings you've created on eBay, and update the WooCommerce stock levels for existing listings. 

## How Updates Work
If a listing is already in WooCommerce (identified by the `_ebay_item_id` meta key), the plugin will NOT create a duplicate product. Instead, it will update the stock quantity of the existing WooCommerce product to match what is live on eBay.
