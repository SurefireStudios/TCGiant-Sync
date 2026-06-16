# Installation and Setup

Getting started with TCGiant Sync takes just a few minutes. This guide walks you through everything from installing the plugin to running your first import.

---

## 1. Requirements

| Requirement | Minimum Version |
|---|---|
| WordPress | 5.8+ |
| WooCommerce | 5.0+ |
| PHP | 7.4+ |
| eBay Account | Active Seller Account |

> **💡 Tip:** TCGiant Sync is fully compatible with WordPress 7.0, WooCommerce 10.0, and WooCommerce HPOS (High-Performance Order Storage).

---

## 2. Installing the Plugin

### Option A: From GitHub (Recommended)

1. Go to the [Releases page](https://github.com/SurefireStudios/TCGiant-Sync/releases).
2. Download the `tcgiant-sync.zip` file from the latest release.
3. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
4. Select the zip file and click **Install Now**.
5. Click **Activate**.

### Option B: Automatic Updates

Once installed, TCGiant Sync checks GitHub for new versions automatically. When an update is available, you'll see the standard WordPress "Update available" notification — just click update like any other plugin.

> **💡 Tip:** You'll see a new **TCGiant Sync** menu item in your WordPress sidebar after activation.

---

## 3. Connecting to eBay

TCGiant Sync uses eBay's official OAuth 2.0 authentication — your credentials are never stored by the plugin.

1. Navigate to **TCGiant Sync → Settings**.
2. Find the **eBay Connection** panel.
3. Click **Connect to eBay**.
4. You'll be redirected to eBay's login page. Log in with the seller account you want to sync.
5. Click **Agree and Continue** to grant the plugin access to manage your listings and inventory.
6. You'll be redirected back to your WordPress dashboard automatically.

✅ Your dashboard's **System Health** card will now show the connection as **Active**.

### Choosing Your eBay Region

If you sell on an international eBay site (UK, Canada, Australia, Germany, etc.):

1. Go to **TCGiant Sync → Settings**.
2. Under **eBay Site ID**, select your primary marketplace (e.g., `EBAY_GB` for eBay UK).
3. Click **Save Settings**.

This ensures the plugin uses the correct API endpoints and category structure for your region.

---

## 4. Configuring Your First Import

Before importing, tell the plugin which eBay categories to pull from:

1. Go to **TCGiant Sync → Settings**.
2. Under **Import Settings**, click **Fetch Categories** to load your eBay Store Categories.
3. Check the boxes for the categories you want to import.
4. Click **Save Settings**.

> **💡 Tip:** If you don't have an eBay Store subscription (free eBay accounts), the plugin will automatically use your eBay Primary Categories instead.

### Data Mapping (Optional but Recommended)

Under **Data Mapping (Re-imports)**, you can control what happens when an existing product is re-synced:

| Setting | Default | What It Does |
|---|---|---|
| Overwrite Title | No | Keep your WooCommerce title, or let eBay's title win |
| Overwrite Description | No | Keep your WooCommerce description, or let eBay's win |
| Overwrite Price | Yes | Always sync the latest eBay price |
| Overwrite Images | No | Keep your WooCommerce images, or re-pull from eBay |
| Overwrite Categories & Tags | No | Keep your WooCommerce categories, or re-map from eBay |
| Overwrite Weight & Dimensions | Yes | Always sync weight/dimensions from eBay |

> **💡 Tip:** Most sellers leave Price and Weight/Dimensions set to "Yes" (eBay wins) and everything else on "No" (WooCommerce wins), so they can customize titles and images in WooCommerce without losing their edits on the next sync.

---

## 5. Activating Your License (Pro)

If you purchased a Pro license:

1. Go to **TCGiant Sync → Settings**.
2. Scroll to the **License** section.
3. Enter your license key and click **Activate**.

Pro unlocks unlimited imports and automated scheduling. The free tier supports up to 50 imported products.

---

## Next Steps

- **[Import from eBay](Import-from-eBay)** — Pull your listings into WooCommerce
- **[Push to eBay](Push-to-eBay)** — Create eBay listings from WooCommerce products
- **[Settings and Data Mapping](Settings-and-Data-Mapping)** — Fine-tune every sync behavior
