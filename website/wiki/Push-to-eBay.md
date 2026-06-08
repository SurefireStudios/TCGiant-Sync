# Push to eBay

Pushing products allows you to create eBay listings directly from WooCommerce. You can create a product entirely within WordPress, add your images and descriptions, and push it live to eBay with a single click.

---

## 1. Fetching Business Policies

Before you can push a product to eBay, eBay requires that every listing has a defined Shipping Policy, Return Policy, and Payment Policy.

1. Navigate to **TCGiant Sync > Settings**.
2. Scroll down to the **eBay Business Policies** section.
3. Click the **Fetch Policies** button.
4. The plugin will query your connected eBay account and pull down all your existing policies.
5. Select a default Shipping, Return, and Payment policy from the dropdowns. These will be automatically applied to any WooCommerce product you push to eBay (unless you manually override them on the specific product).
6. Click **Save Settings**.

## 2. Pushing a Single Product

To list an individual WooCommerce product on eBay:

1. Open any product in the WooCommerce dashboard via **Products > All Products**.
2. Scroll down to the **Product Data** meta box.
3. Click on the **TCGiant Sync** tab on the left.
4. You will see options to override the default eBay Category, Condition, and Business Policies for this specific item.
5. Click the **Push to eBay** button.
6. The product will be queued for export. You can view the progress on the main **TCGiant Sync > Dashboard** page.
7. Once successfully pushed, the product will be assigned an `_ebay_item_id`. You can find the live eBay listing URL directly in the TCGiant Sync tab.

## 3. Pushing in Bulk

If you have dozens of products to push, doing it one by one is tedious. 

1. Go to **Products > All Products**.
2. Check the checkboxes next to all the products you want to list on eBay.
3. At the top of the table, click the **Bulk Actions** dropdown.
4. Select **Push to eBay via TCGiant Sync**.
5. Click **Apply**.

The selected products will use the default eBay Category and Business Policies you configured in the Settings menu. They will be pushed to eBay sequentially in the background.

## 4. Revising Existing Listings

If a WooCommerce product already has an `_ebay_item_id` attached to it, clicking "Push to eBay" again will NOT create a duplicate listing. 

Instead, the plugin uses the eBay `ReviseItem` API to update the existing listing. It will automatically push up the latest Title, Description, Price, Images, and Stock Quantity.
