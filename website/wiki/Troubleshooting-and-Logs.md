# Troubleshooting and Logs

TCGiant Sync includes built-in logging and diagnostics to help you understand exactly what the plugin is doing in the background.

---

## The Activity Log

Whenever the plugin fetches an import, pushes a product, or automatically syncs stock quantities, it writes a message to the Activity Log. 

**Where to find it:**
- **Dashboard Widget:** Navigate to **TCGiant Sync > Dashboard**. The bottom section displays the 10 most recent activity logs, color-coded by severity (Info, Success, Warning, Error).
- **Full Logs Page:** Navigate to **TCGiant Sync > Logs** to see the complete history. 

*Note: To prevent the database from ballooning, the plugin automatically rotates logs and truncates them to the last 200 lines or 500KB (whichever comes first).*

## Common Issues

### 1. "OAuth Token Expired" or "Unauthorized" Errors
eBay OAuth tokens expire periodically for security reasons. 
- **The Fix:** Go to **TCGiant Sync > Settings**, scroll to the eBay Connection panel, and click **Connect to eBay** again to refresh your tokens.

### 2. Products Failing to Push to eBay
If you try to push a product and the Activity Log shows an error, it is almost always related to missing required data on eBay's end.
- **Missing Item Specifics:** Depending on the eBay category, certain Item Specifics (like "Brand", "MPN", or "Graded") are mandatory. Make sure these are filled out in your WooCommerce Product Attributes.
- **Missing Business Policies:** Ensure you have fetched and assigned a Shipping, Return, and Payment policy in the TCGiant Sync settings.

### 3. Automated Sync Isn't Running
If your stock isn't updating automatically and you are on a Pro tier:
- Check **System Health** on the Dashboard.
- Ensure your sync schedule is set to something other than "Manual" in the Settings.
- WordPress cron jobs rely on site traffic to trigger. If your WooCommerce site receives very little traffic, the cron job might be delayed. Consider setting up a server-side cron job to ping `wp-cron.php` regularly.

### Getting Support
If you've checked the logs and cannot resolve an issue, please reach out to our support team at **hello@tcgiant.com** and include a copy of the recent error messages from your Logs page.
