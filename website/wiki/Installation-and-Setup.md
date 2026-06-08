# Installation and Setup

Getting started with TCGiant Sync takes just a few minutes. This guide walks you through installing the plugin and securely connecting your WooCommerce store to your eBay account.

---

## 1. Requirements

Before installing, ensure your environment meets the following minimum requirements:
- **WordPress:** Version 5.8 or higher.
- **WooCommerce:** Version 6.0 or higher.
- **PHP:** Version 7.4 or higher.
- An active eBay Seller Account.

## 2. Installing the Plugin

If you downloaded the plugin from GitHub:
1. Navigate to the [Releases](https://github.com/SurefireStudios/TCGiant-Sync/releases) page.
2. Download the `tcgiant-sync.zip` file for the latest release (e.g., v1.2.1).
3. In your WordPress Admin dashboard, go to **Plugins > Add New**.
4. Click **Upload Plugin** at the top of the page.
5. Select the `tcgiant-sync.zip` file you downloaded and click **Install Now**.
6. Once installed, click **Activate**.

You will now see a new **TCGiant Sync** menu item in your WordPress sidebar.

## 3. Connecting to eBay

To allow WooCommerce to pull listings and update stock on eBay, you need to grant it access via eBay's secure OAuth flow.

1. In WordPress, navigate to **TCGiant Sync > Settings**.
2. Locate the **eBay Connection** panel.
3. Click the **Connect to eBay** button.
4. You will be redirected to the eBay login screen. Log in to the seller account you wish to sync.
5. Click **Agree and Continue** to grant TCGiant Sync permission to manage your listings and inventory.
6. You will be seamlessly redirected back to your WordPress dashboard. 

Your Settings page should now display a success message, and the **System Health** card on the main dashboard will indicate that your connection is "Active".

## Next Steps
- Head over to the **[Import from eBay](Import-from-eBay)** guide to pull in your existing inventory.
- Or, check out the **[Push to eBay](Push-to-eBay)** guide to start exporting WooCommerce products.
