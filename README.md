# TCGiant Sync

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Requires PHP](https://img.shields.io/badge/PHP-7.4%2B-green.svg)](#)
[![Tested up to](https://img.shields.io/badge/WordPress-6.9-blue.svg)](#)

TCGiant Sync is a comprehensive WordPress/WooCommerce plugin that seamlessly bridges your eBay store and WooCommerce site. It enables automatic import, category mapping, and real-time inventory synchronization of trading card game (TCG) product listings to prevent overselling on either platform.

## 🚀 Key Features

### Free Tier (Up to 50 active listings)
* **Secure Connection:** Connect to eBay securely via official OAuth 2.0.
* **Automated Import:** Import up to 50 active eBay listings directly into WooCommerce.
* **Smart Mapping:** Automatic product mapping and category organization tailored for collectibles.
* **Real-Time Sync:** Bi-directional inventory synchronization ensures stock levels remain accurate. When a product sells on eBay or your website, stock is adjusted automatically across both platforms!
* **Live Dashboard:** Keep an eye on syncing status with a live monitoring dashboard. 
* **Detailed Logs:** Fully transparent activity logs for simplified troubleshooting.

### Pro Tier
* **Unlimited Syncing:** No cap on imported products or active syncs.
* **Priority Support:** Get direct assistance from the team.

*Upgrade your account at [tcgiant.com/sync](https://tcgiant.com/sync).*

## 📋 Requirements
- **WordPress:** Version `5.8` or higher
- **PHP:** Version `7.4` or higher
- **WooCommerce:** Must be installed and active.

## 🛠️ Installation

**Manual Installation:**
1. Download the latest `tcgiant-sync.zip`.
2. Extract the archive and upload the `tcgiant-sync` folder to your `/wp-content/plugins/` directory (or use the WordPress plugin uploader).
3. Activate the plugin through the **Plugins** menu in WordPress.

## ✨ Getting Started

1. Once activated, ensure **WooCommerce** is active.
2. Navigate to **TCGiant Sync** located in the sidebar of your WordPress admin panel.
3. In the Settings tab, click **Connect to eBay** to authorize the application using eBay's official OAuth 2.0 flow. (Your credentials are never stored).
4. *(Optional)* Select categories to filter which eBay listings should be imported.
5. Click **Import Now** to run the initial sync! The plugin will map your selected listings and establish real-time inventory webhooks to keep everything perfectly synchronized.

## ❓ Frequently Asked Questions

**Does this plugin require WooCommerce?**  
Yes, WooCommerce must be installed and active for TCGiant Sync to function.

**How many products can I sync for free?**  
The completely free tier supports up to 50 active, synced products. Upgrading to a Pro license removes this limit. 

**Is my eBay data secure?**  
Absolutely. Utilizing a secure relay and eBay's official OAuth 2.0 process means your actual credentials are never exposed, intermediated, or stored by us. 

**What happens when an item sells?**  
Whether an item sells on eBay or WooCommerce, TCGiant adjusts the stock counterpart immediately, significantly reducing any risk of double-selling rare and highly-sought after TCG collectibles! 

## ⚖️ License
This project is licensed under the **GPL-2.0-or-later** license - see the `LICENSE` file for details.
