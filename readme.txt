=== TCGiant Sync ===
Contributors: tcgiantteam
Tags: ebay, woocommerce, sync, inventory, tcg
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.13.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your eBay TCG listings to WooCommerce automatically — and push WooCommerce products back to eBay as new listings. Import products, map categories, keep inventory in sync, and create listings in both directions.

== Description ==

TCGiant Sync bridges your eBay store and WooCommerce, enabling automatic import and synchronization of trading card game (TCG) product listings.

**Free Features:**

* Connect to eBay via secure OAuth 2.0
* Import up to 50 active eBay listings to WooCommerce
* Automatic product mapping and category organization
* Real-time inventory synchronization
* Live sync dashboard with status monitoring
* Activity logging for troubleshooting
* **Push to eBay** -- create new eBay listings directly from WooCommerce (single product or bulk)
* **Unified "eBay Listing" tab** -- guided step-by-step flow: Item Type, Category, Condition, Push
* **Category auto-suggestion** -- eBay suggests the best leaf categories from your product title
* **Visual card selectors** for Item Type (Trading Cards / Coins) and Condition (Graded / Ungraded)
* **Pre-push readiness checklist** validates category, condition, policies, images, and title length
* Per-product eBay Category and Condition overrides
* **Per-product Listing Type & Duration** — choose Fixed Price or Auction, with duration from 1 day to GTC, per product or as global defaults
* **Per-product Shipping Policy** — override the default fulfillment policy on individual products
* **"All Other" item type** — list non-collectible products (electronics, clothing, etc.) with simple conditions, no grading required
* eBay ConditionDescriptor support — Graded (PSA, BGS, SGC, CGC, etc.) and Ungraded conditions for Trading Cards and Coins categories
* Business Policy management (Shipping, Returns, Payments) with one-click fetch

**Pro Features (requires license key):**

* Unlimited product imports
* Priority support

== Installation ==

1. Upload the `tcgiant-sync` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure WooCommerce is installed and active.
4. Navigate to **TCGiant Sync** in the admin menu.
5. Click **Connect to eBay** to authorize your eBay account.
6. Select your store categories and start syncing!
7. To push listings to eBay, configure Export Defaults in Settings (Category ID + Business Policies), then use the **Push to eBay** button on any product.

== Frequently Asked Questions ==

= Can I push WooCommerce products to eBay? =

Yes! Open any product and click the **"eBay Listing"** tab. Follow the guided 4-step flow: choose Item Type, select a Category (or let eBay auto-suggest one from your title), set the Condition, then review the readiness checklist and click Push. You can also bulk-push from the WooCommerce Products list.

= What is the "eBay Listing" tab? =

The eBay Listing tab is a unified product panel that replaces the old "Grading & Condition" and "TCGiant Sync" tabs. It walks you through 4 steps: (1) Item Type, (2) Category, (3) Condition, (4) Push to eBay. Each step uses visual card selectors instead of dropdowns, and includes a readiness checklist to catch issues before you push.

= What are eBay Business Policies? =

Business Policies are eBay's system for managing reusable Shipping, Return, and Payment settings across your listings. Your seller account must be enrolled to use the Push to eBay feature. Most accounts are enrolled automatically — if not, you can enroll at bizpolicy.ebay.com.

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and active for TCGiant Sync to function.

= How many products can I sync for free? =

The free tier supports up to 50 active synced products. Upgrade to Pro for unlimited imports.

= Is my eBay data secure? =

Yes. TCGiant Sync uses eBay's official OAuth 2.0 authentication. Your credentials are never stored in plain text.

= How does inventory sync work? =

When a product sells on either eBay or WooCommerce, the plugin automatically adjusts stock levels on the other platform to prevent overselling.

= What types of products are supported? =

TCGiant Sync is optimized for trading card game (TCG) collectibles and coins. It includes full eBay ConditionDescriptor support for graded items (PSA, BGS, SGC, CGC, and 17 other graders) and ungraded items (Near Mint, Excellent, etc. for cards; Uncirculated, Fine to Very Fine, etc. for coins). It works with any eBay listing type.

== Screenshots ==

1. Dashboard overview showing sync status and statistics.
2. eBay connection settings with one-click OAuth.
3. Category filter selector for targeted imports.
4. Live activity log with real-time updates.
5. Push to eBay export settings with Business Policy selector.
6. "eBay Listing" tab showing the unified step-by-step push flow with readiness checklist.
7. Category auto-suggestion pills from product title.

== Changelog ==

= 3.13.0 - 2026-09-01 =
**Deleting the plugin no longer deletes your settings**
* Removing the plugin used to take its own records with it: your settings, your eBay connection, and the links recording which product came from which listing. Those links cannot be rebuilt, so a later sync would re-import products it already had.
* They are now kept unless you ask for them to go. New setting: "Also delete the settings and eBay links?", set to No.
* Products and orders were never deleted by this and still are not.
* Deleting one version of the plugin now leaves any other installed version completely alone.

**Fixes to what uninstalling clears**
* FIX: Products queued to be pushed to eBay were never cancelled — the plugin cancelled a job by a name it has never used.
* FIX: The weekly full sync added in 3.12.0 was not cleared either.

**Reporting**
* FIX: Pushing products sent one report per product — five thousand requests for a five thousand product push, arriving at the same place your site renews its eBay connection. Now one report per sync.
* Reporting can be switched off with the TCGIANT_SYNC_DISABLE_TELEMETRY constant or the tcgiant_sync_telemetry_enabled filter.
* The report is signed with the key your site was issued when it connected.

**eBay account deletion**
* FIX: Deletion messages passed on to your shop were signed with the wrong key, so every shop rejected them and nothing noticed. They are now signed correctly.
* Shops that connected before per-site keys existed had one invented locally, which could never match. Press Connect to eBay once if yours is affected.

**The connection test**
* The test now only contacts an unrelated site when our own addresses have already failed, which is the only case where it changes the verdict.

= 3.12.0 - 2026-08-30 =
**Sold items now leave your shop on their own**
* Products are removed when their eBay listing sells out, without waiting for a manual full sync. Until now only a full sync did this, so stores on the scheduled sync kept sold items on the shelf.
* Only listings that actually sold out. One that ran out of time or that you ended yourself keeps its product and any unsold stock.
* Products you pushed to eBay yourself are never removed, and neither is anything in your Preserve Categories.
* Removed products go to the Trash, so anything can be restored.
* New setting if you would rather it did not: "Remove products when their eBay listing sells out?"

**A weekly full sync**
* A complete sync now runs weekly. The ordinary sync only sees the last 48 hours, so listings that ended while a site was unreachable were never noticed again.
* Respects your Auto-Sync setting — nothing runs on its own if you have that disabled.

= 3.11.1 - 2026-08-30 =
**Free tier**
* FIX: Going over the free product limit still stopped syncing altogether. 3.7.12 was meant to fix this and did not — it corrected the checks inside the import loops, but every way into those loops still refused before reaching them.
* A store over the limit received nothing at all: no stock, no prices, no ended listings, and no removal of products whose listings had finished.
* All four ways of starting a sync now run; only new listings are held back.

= 3.11.0 - 2026-08-28 =
**Connecting to eBay**
* Groundwork for stores whose hosting address is judged badly by security software in front of our service, where neither host can resolve it. The plugin can now be pointed at a different way of reaching us.
* Only ever used after both ordinary routes have been answered with a browser check. Stores that connect normally never touch it.
* No alternate address is set in this release, so nothing changes for anyone yet.
* The connection test reports on that route too, when one is configured.

= 3.10.0 - 2026-08-28 =
**New: Image Cleanup**
* NEW: An Image Cleanup page that finds products holding duplicate copies of their own photographs and puts the originals back.
* The downloaded copies had replaced your pictures as the product image and gallery, so deleting whatever was unused would have deleted exactly the wrong ones.
* Your photographs are restored first, and only then are the copies removed. Nothing you uploaded is ever deleted.
* Both sets are shown as pictures before you press anything.

= 3.9.5 - 2026-08-28 =
**Images**
* FIX: Photographs you uploaded were downloaded back from eBay and attached a second time shortly after a push — a duplicate of every picture named -1-1.jpg beside your -1.jpg, with no author, because a background task created them.
* Your own pictures are now recognised as yours and left alone.
* Products imported from eBay are unaffected and still download their pictures as before.
* "Overwrite Images" still takes precedence if you want eBay’s copies to win.
* Duplicates already created are not removed automatically. They are the ones with no author, added minutes after a push.

= 3.9.4 - 2026-08-28 =
**Connecting to eBay**
* FIX: The plugin identifies itself when contacting us rather than arriving as a generic WordPress client.
* FIX: Calls are spaced a couple of seconds apart rather than sent in quick succession — several requests inside a second is the shape automated abuse takes, and protection in front of our service was answering it with a browser check the plugin cannot pass.
* The connection test was the worst offender, firing four checks in about a second. The tool built to diagnose this was provoking it. It now paces itself.
* Stores connecting normally wait for nothing; the pause only applies after an attempt has been interrupted.

= 3.9.3 - 2026-08-28 =
**Connection test**
* The test can now identify when the fault is ours, and says so rather than leaving you to work it out.
* A reply arriving at all proves whatever answered held a certificate your server trusts for our name, and that cannot be held without the private key that exists only on our equipment. So if your requests reach our address over a verified connection presenting our own certificate, the interruption is inside our hosting.
* In that case it tells you there is nothing for you or your host to change, and asks you to send us the result.

= 3.9.2 - 2026-08-28 =
**Connection test**
* The test now reports which security certificate your server receives from our address, with its fingerprint.
* This settles who is interrupting a connection, which no earlier check could. A reply arriving at all proves whatever answered presented a certificate your server trusts for our name — so either it holds our real certificate and stands at our end, or something on your network is decrypting traffic and impersonating us.
* Nothing is sent by the check; the connection is opened to look and closed again.

= 3.9.1 - 2026-08-28 =
**Connection test**
* The test now reports which address your server thinks ours has. A server that looks our name up wrongly produces exactly the same readings as one being blocked, while its requests quietly go somewhere else entirely.
* If the name resolves to a private address, or to your own server, it says so: the requests never left, and no firewall is involved.
* FIX: A successful name lookup could be counted as the connection service having answered.

= 3.9.0 - 2026-08-28 =
**Connection test**
* You can now see the whole of whatever answered, on screen, with a Copy button — the summary is trimmed to stay readable and the log keeps one line, so there was previously no way to send us the full reply.
* Every response header is recorded now, rather than a handful chosen in advance. Set-Cookie was among the missing ones, and it usually identifies a security product more clearly than anything else in the exchange.
* Cookie names are kept because they identify the product; cookie values are not recorded.

= 3.8.9 - 2026-08-27 =
**Connection test**
* The log now records the address the intercepting page loads its icon and scripts from — that address belongs to whoever runs the equipment, and identifies them.
* It was previously lost, because the 300-character excerpt cuts off at exactly the point that address begins.
* This settles cases where two parties each check their own equipment, find nothing, and are both being truthful.

= 3.8.8 - 2026-08-27 =
**Connection test**
* FIX: When traffic to us specifically was being stopped, the test told you to take it to your host. It cannot tell whose equipment is responsible — a rule at your end and a rule at ours look identical from where it stands.
* It now says so and asks you to send the result to us first, so we can rule out our own end before you approach your host.

= 3.8.7 - 2026-08-27 =
**Connection test**
* The test now also tries one address that is nothing to do with us — asking only our own addresses could never separate "cannot reach us" from "cannot reach anything", and those have different owners.
* If unrelated sites answer while all of ours are intercepted, the block is aimed at us specifically. If nothing gets out at all, the interference is general. The test now says which.
* The check sends nothing about your site and runs only when you press the button.

= 3.8.6 - 2026-08-26 =
**Connection test**
* The test now checks a third address: the one used to report sync totals. It has nothing to do with connecting, which is why it helps — it separates a server that cannot reach us at all from one that reaches us fine but has the connection requests singled out and stopped.
* Those need different fixes, and the test now says which it is rather than reporting both as "cannot reach".
* Nothing is written or recorded by the check.

= 3.8.5 - 2026-08-26 =
**Navigation**
* The tabs on each page now list the same pages as the menu on the left, in the same order — Listings and Stock Review were missing from them.
* Listings and Stock Review now show the tabs too. They had none, so the only way off those pages was the left-hand menu.
* The tabs wrap onto a second line rather than overflowing on a narrow window.

= 3.8.4 - 2026-08-26 =
**Stock Review**
* FIX: Settling a product whose listing ended without selling looked like it did nothing. It worked correctly — eBay reported the item unsold, so the stock was rightly left alone — but the product still matched "ended and showing stock" and stayed on the list.
* The screen now remembers what it has already checked with eBay, so anything settled leaves the list either way.
* If the listing goes back up for sale that is forgotten, so it can be reviewed again if the new listing ends badly.
* FIX: A product is only recorded as settled once the new quantity is confirmed saved. If something else on the site blocks the change, it stays on the list and the log says so.
* The quantity is confirmed before the in-stock status is touched — previously a failed quantity write still left the product marked out of stock, removing it from the one list that could have caught it.

= 3.8.3 - 2026-08-26 =
**eBay connection controls**
* The connection card now reads Connect to eBay, Test connection, Disconnect — clearing action last.
* FIX: A part-finished connection left a site looking unconnected while still holding leftover details, and the button to clear them was hidden in exactly that state. It now appears as "Reset connection" whenever there is anything to clear.
* FIX: Disconnecting left a half-finished handshake in place, which is what makes the next attempt come back refused. It is now cleared too, so reconnecting genuinely starts fresh.

= 3.8.2 - 2026-08-26 =
**Knowing why a connection failed**
* NEW: A "Test connection" button on the Settings page. It asks whether your server can reach our connection service, and names whatever answered if something is in the way — which is the detail your host needs.
* FIX: If eBay sent you back without completing the connection, nothing said so; the page looked unchanged, which reads as the button doing nothing. The reason is now shown on screen and logged.
* FIX: The dashboard showed a red dot and the word "Error" with no reason, which lived only in the log. It now says what stopped the last run.
* Reaching the free product limit and stopping a sync by hand now explain themselves too.
* Pressing "Connect to eBay" is recorded in the log, so it is possible to tell whether the button reached the site at all.

= 3.8.1 - 2026-08-25 =
**Connecting to eBay**
* FIX: Some hosts run security software that challenges the request the plugin makes to collect your eBay tokens, answering with a "checking your browser" page. That page expects a browser to retry after passing a check, which the plugin cannot do — so affected stores could never finish connecting, however many times they tried.
* The plugin now retries the same request by a route those filters do not object to. Stores that were already connecting are unaffected and make exactly one request as before.
* Token renewal takes the same route, so a store that connected while the filter was quiet no longer breaks at its first renewal.
* If both routes are blocked, the log still names the server that answered.

= 3.8.0 - 2026-08-24 =
**New: Stock Review**
* NEW: A Stock Review page listing any product whose eBay listing has ended but which still shows stock, with a button to settle them from eBay. This is the recovery step for anything sold before 3.7.11.
* Settling asks eBay what each listing held and what sold, then sets your stock to the difference — sold out leaves nothing, while a listing you ended yourself keeps whatever was unsold.
* Where eBay will not report the figures, your stock is left alone rather than guessed at. Nothing changes without you pressing the button.
* An admin warning now appears when products are in this state, because the failure is otherwise silent.

**Stock checking**
* The weekly stock check now also reports ended listings that still show stock — it could only see live listings before, which is exactly where this fault does not appear.
* The weekly check is now a visible setting. It was always on with no way to turn it off.
* A listing that has run its course is now settled even when the product does not track a quantity.

= 3.7.12 - 2026-08-24 =
**Stock**
* FIX: The hourly ended-listing check marked products Ended without settling their stock, so a sale that ended a listing could still leave the item showing as available. 3.7.11 fixed this in the scheduled sync; this fixes the same fault in the hourly check. Both now share one rule and cannot drift apart again.
* A listing whose run has expired has its stock settled before being marked Ended. If eBay will not report the figures, the log says so and your stock is left alone rather than zeroed on a guess.

**Free tier**
* FIX: Going over the free product limit stopped the sync completely rather than just declining new imports — stock, prices and ended listings all stopped updating on products already imported.
* The limit now caps how many products a store holds. Products already imported keep syncing normally; only new listings are held back. Queued imports are no longer cancelled, as that queue carries updates to existing products too.

**Performance**
* The free-limit check counted every product on the store once per listing. It now runs at most once per sync, and not at all on Pro.

= 3.7.11 - 2026-08-24 =
**Stock**
* FIX: Selling an item on eBay did not reduce its WooCommerce stock when the sale ended the listing. A listing with one of something ends as soon as it sells, so this affected every single-quantity listing — for coins, cards and other one-off items, that is all of them.
* The plugin recorded the listing as ended but left the stock alone, and the separate post-sale stock adjustment had been switched off for eBay-linked products because the ordinary sync sets the quantity from eBay. True of a running listing; false of an ended one, which is never re-imported.
* Stock is now settled from eBay's own figures whenever a listing ends — sold out leaves nothing in stock, while a listing you ended yourself keeps whatever was unsold.
* Items sold while this was happening will still show in stock and need correcting by hand. Look for products marked Ended that still show a quantity.

= 3.7.10 - 2026-08-24 =
**Connecting to eBay**
* When a web page is returned instead of data during connection, the log now records which server produced it, plus the page title and its opening characters.
* Saying that "something" intercepted the request left both the merchant and their host searching with nothing to go on, and both could honestly report their own end looked fine. The reply itself carries the answer — our connection service runs LiteSpeed, so a page from anything else came from in between, and such pages usually name the product that produced them.

= 3.7.9 - 2026-08-21 =
**Syncing**
* FIX: Syncing could stop permanently on a busy store — the reason new listings were still not arriving. eBay refuses to return more than 3000 changes at once and asks for a shorter period instead. The plugin always asked for the same 48 hours, so a store with more changes than that was refused every time; and because a failed sync keeps its place rather than skipping anything, the next attempt asked for the same period and was refused again. One store sat five days behind with nothing new coming in.
* The plugin now halves the period and tries again until eBay accepts, exactly as eBay asks. The sync position never moves while this happens, so nothing is skipped.
* Once the backlog clears it returns to the full 48 hours.
* Syncing resumes on its own after updating; run Fetch Inventory if you would rather not wait for the catch-up.

= 3.7.8 - 2026-08-20 =
**New listings**
* FIX: New eBay listings could be absorbed into an existing product instead of added, so the new stock never appeared. This is the remaining half of the 3.6.1 fault, which only covered the case where the older listing was still running.
* When a new listing shared a SKU with an existing product, the plugin assumed a relist and moved that product onto the new listing. Right when the SKU is the seller own reference; wrong when it is a barcode, since one part number covers every listing of that part — so two different items were merged.
* Worst where the eBay SKU is mapped to Bin Location, because the WooCommerce SKU then comes from the barcode. On a store whose listings end and are replaced constantly, it happened repeatedly and silently.
* The plugin now tracks where each SKU came from and only re-points a product when the SKU is the seller own reference.
* After updating, run Fetch Inventory once — absorbed listings will be created as their own products. Existing duplicates (SKU ending in a dash and the eBay item number) are left alone.

= 3.7.7 - 2026-08-19 =
**Connecting to eBay**
* FIX: The activity log claimed the tokens had been collected and then immediately reported that they had not. The success message was written before the result was known; it is now written only once the connection has succeeded.
* A connection blocked before it reaches us is now named as such. If a web page comes back instead of data — what bot protection and security filters return — the log says the request was intercepted, rather than blaming the connection service.

**Logs**
* FIX: A message containing line breaks broke the log. Each entry is one line by design, so the extra lines showed as separate entries with no time or type — an error page quoted in a message became a dozen meaningless rows. Messages are now kept to one line.

= 3.7.6 - 2026-08-17 =
**Connecting to eBay**
* CRITICAL: Fixed a fatal error that could take a site down while connecting or reconnecting an eBay account. When the final step failed, the code meant to report the problem contained a typing mistake and crashed instead, leaving a broken WordPress admin recoverable only over FTP. Reloading reproduced it.
* Affects 3.7.0 to 3.7.5, and only when the connection step failed for some other reason — which is why most stores never saw it.
* The message now reports what actually went wrong, and no longer uses the mechanism that failed: an error handler is the last thing that should be able to break.
* If affected, update and connect again — the real reason for the failure will now be shown on screen and in the activity log.

= 3.7.5 - 2026-08-17 =
**Product screen**
* NEW: The eBay panel on a product now says whether the listing is still live. An ended listing looked identical to an active one, so there was no way to tell from the product that it had finished — whether you ended it yourself or it ran out.
* Listings carry an Active or Ended badge; an ended one shows when it finished and turns amber rather than green.
* An ended listing also explains that pushing will create a new listing with a new item number, since eBay does not allow an ended one to be revised. That was always the behaviour, it just was not stated.
* The product screen and the Products list now decide "ended" the same way and can no longer disagree.

= 3.7.4 - 2026-08-12 =
**Installing**
* FIX: A second copy of the plugin left in place would take the whole site down. Two copies in wp-content/plugins — the normal folder plus something like "tcgiant-sync-2" from an interrupted manual install — both tried to set up the same things and PHP stopped dead, taking out the WordPress admin too. The only way back was to delete a folder over FTP, and reinstalling brought it straight back because the other copy was still there.
* The second copy now stands aside quietly and the site keeps working, with an admin notice explaining which folder to remove.
* Currently affected: delete every TCGiant Sync folder from wp-content/plugins, then install this version fresh. Settings, connection and synced products live in the database and are not affected by removing the folder.

= 3.7.3 - 2026-08-12 =
**Stock**
* FIX: Selling a variation in WooCommerce did not reduce its stock on eBay. An item number names the listing but not which variation within it, and eBay needs the variation SKU as well — which the plugin was not sending, so the stock change had nothing to land on.
* Affects older listings created directly on eBay. Listings managed through eBay's inventory system were already handled correctly.
* The SKU is sent only for products that really are variations — on an ordinary listing eBay only accepts one if the listing was set up to be tracked that way.
* Nothing to do; the next stock change on an affected product syncs as it should.

= 3.7.2 - 2026-08-12 =
**Auto-relist**
* FIX: Auto-relisting a listing with variations would have relisted it empty. eBay relists those through a different call from ordinary listings, and the plugin used the ordinary one — which eBay accepts, recreating the listing and silently discarding every variation, then reporting success. Same fault fixed for pushing in 3.7.1, in the last place it still applied.
* Only stores with "Auto-relist ended listings" on and variable products were exposed.
* FIX: Auto-relist skipped its own stock check on variable products, which do not usually track stock themselves — their variations do. Sold-out variable products were being sent to eBay and refused. They are now left alone.

= 3.7.1 - 2026-08-12 =
**Variable products**
* FIX: "Overwrite Price" was ignored for variations. It has always been respected for ordinary products, but variation prices were written from eBay on every sync whatever the setting said — so prices set in WooCommerce were undone hourly, and turning the setting off changed nothing because it was never consulted. Variations now follow the same rule. A variation created for the first time still takes eBay's price.
* FIX: Changes to variations never reached eBay. eBay handles listings with variations through a different call from ordinary ones, and the plugin used the ordinary one — which eBay accepts, applying everything else and quietly discarding the variations. Prices updated, new variations never appeared, and nothing in the log explained it. Listings with variations, and fixed-price listings generally, now use the call eBay intends.
* Push your variable products again to send the variations that were dropped. Nothing needs re-importing.
* Also fixed: new listings were sent with their duplicate-protection reference twice over.

= 3.7.0 - 2026-08-12 =
**Connecting to eBay**
* Your eBay tokens no longer travel through the browser. Connecting used to finish by sending them back in the web address, which wrote them into browser history and your own server access logs, where they stay — and an eBay refresh token stays valid for months.
* The connection now finishes with a single-use collection slip. Your site exchanges it for the tokens over a direct request no browser sees. The slip expires after fifteen minutes, only answers to the site it was issued for, and is spent the first time it is presented.
* Nothing about your eBay application changes: same keys, same registered address, same sign-in screen. Only the final step between our connection service and your site is different.
* Existing connections keep working and need no action.

**Syncing**
* Catching up on a backlog is now reliable regardless of the order eBay answers in. Work resumes by position in the list of changes, and eBay does not promise to return that list the same way twice — so a resumed run could skip or repeat items. The order is fixed before anything is processed.

= 3.6.2 - 2026-08-12 =
**Syncing**
* FIX: Syncing could stop completely and stay stopped — the real reason new listings were not arriving on some stores. A large batch of changes was worked through in a single request, fetching each item individually; that request times out part way, so the sync never finishes, never records its position, and never clears the "in progress" marker. Every later scheduled sync was then turned away by it. One store was six days behind and falling further.
* Changes are now processed in batches of 40, resuming where the last finished. The position only advances once the whole window is complete, so an interruption costs a repeat rather than a gap.
* A sync showing no progress for 30 minutes is presumed failed and replaced. The file lock always released itself this way; the "in progress" marker did not, and had become the blocker.
* Syncing resumes by itself after updating. Run Fetch Inventory if you would rather not wait for the catch-up.

**Connection**
* FIX: Intermittent "No valid eBay token" errors. The connection service sometimes returned a server warning ahead of its reply, making the reply unreadable, so a valid token was discarded and the site reported itself disconnected. The token is now recovered, and the warning logged so the cause can be addressed.

**Products**
* FIX: Listings whose barcode field holds a stand-in such as "N/A" or "None" were all given that as their SKU and collided with each other. Those values now count as no barcode, and the eBay item number is used instead.

**Logs**
* Times are now shown in your own timezone while the log continues to be written in the site timezone. Hover any time to see what the file recorded.

= 3.6.1 - 2026-08-12 =
**New listings**
* FIX: A second cause of new eBay listings never appearing, separate from 3.6.0 and affecting stores that do not filter by category at all. When a new listing carried the same SKU as an existing product, the plugin assumed a relist and moved that product across to the new listing — so the new stock never appeared as its own product, and the one it took over was detached from the listing it was actually selling and overwritten with the newcomer's title, price and images. Both silent.
* Easy to hit when "eBay SKU Maps To" is set to Bin Location, since the WooCommerce SKU then falls back to the UPC or EAN, and a manufacturer barcode is not unique to one listing.
* eBay is now asked whether the older listing has actually finished before anything is moved. If it is still live the new listing becomes its own product with a unique SKU; if eBay cannot be reached, nothing is moved. Only happens on a genuine SKU collision, once each.

= 3.6.0 - 2026-08-12 =
**New listings**
* FIX: New eBay listings were not appearing in WooCommerce. With category filtering on, items are checked against your chosen categories before import — but the hourly sync asks eBay only for what changed, and eBay often answers with a partial record that omits the category, so those items failed the check and were dropped before the follow-up request that would have identified them. Newly listed items arrive through that route almost every time.
* Items are now looked up in full before being judged, and only when their categories really are missing. If eBay cannot be reached the item is imported rather than dropped.
* If listings are missing, run Fetch Inventory once to collect them.

**Variable products**
* FIX: Variations duplicated on every sync of a variable product, most visibly when one sold. Variations were recognised only by their eBay SKU and eBay leaves that blank on most variations, so every sync built a fresh set and retired the previous one — hidden copies with the same attributes and prices, stock moving to the newest. The "Variation #... is no longer on eBay" lines for variations that were still there were this happening.
* Variations are now recognised by their combination of options. Existing ones are reused; one genuinely removed from eBay is still retired.
* Hidden duplicates already created are left alone — they may be attached to past orders. They are the variations set to Private with zero stock.

**Images**
* FIX: Products given photographs from a completely different listing. On creation the plugin adopts the images of a deleted product with the same SKU instead of downloading again — but a SKU is often a barcode or seller reference shared across listings, so the wrong photographs were taken, and marked finished so nothing corrected them.
* Images are now only adopted when they are demonstrably the same photographs.
* Already affected: turn on "Overwrite Images" under Settings → Import Settings → Data Mapping and run an import from eBay.

**Coins**
* Coin listings pushed before 3.5.6 are still missing their grading details, Certification, Grade, Year and Circulated/Uncirculated on eBay — fixing the code changed what future pushes send, but did nothing for what had already gone out, and nothing about them looks wrong from inside WooCommerce.
* The plugin now identifies those listings and offers to send them again, with a count and one button. No eBay lookups are needed to find them, and the notice clears itself once they are updated. Nothing is sent until you press the button.

**Logs**
* The activity log now states which clock its timestamps use. They have always been in your WordPress timezone, not UTC — if they look wrong, change it under Settings → General.

= 3.5.6 - 2026-08-07 =
**Coins**
* FIX: Coin listings were only recognised as coins when the category was one of five top-level eBay categories. Nobody lists at the top level — a real coin goes in a sub-category such as "Coins & Paper Money > Coins: US > Mint Sets", and those were not recognised, so coin handling quietly did not apply: no grading descriptors, no Certification, Grade or Year, no Circulated/Uncirculated. Only setting Item Type to Coins by hand on each product rescued it.
* The full category path is already recorded when you pick a category, so it is now used. Anything under "Coins & Paper Money" counts as coins at any depth. Setting Item Type by hand still works and still wins for custom category IDs typed in directly.
* Card categories are unaffected and can never be read as coin categories.

**Housekeeping**
* Removed three unused leftovers in the API and order code. No change in behaviour.

= 3.5.5 - 2026-08-07 =
**Images**
* FIX: Turning on "Overwrite Images" still did nothing on existing products. 3.5.4 made the setting work, but only for products the plugin had already decided to revisit — and a product is only revisited when its eBay photo addresses change. For the products the setting exists to fix, those addresses had not changed, so they were skipped before the setting was consulted. Switching it on now marks every product to be looked at once.
* After updating: switch it on under Settings → Import Settings → Data Mapping, then run an import from eBay. Products are corrected in the background. Nothing is re-downloaded — images already in your Media Library are reused.
* This happens once per change of the setting, not on every sync.

= 3.5.4 - 2026-08-06 =
**Images**
* FIX: The "Overwrite Images" setting did nothing. It was read and then never acted on, so a product that already had a main image kept it whatever the eBay listing showed, and eBay photos were only ever appended to the gallery behind it. Turning it on now makes the eBay photos authoritative — first photo as the main image, gallery rebuilt from the listing.
* This is why a product could show a photo that is not on its eBay listing with no way to correct it except by deleting the image by hand. Left off, your own images are still untouched, exactly as before.
* Images already in your Media Library are never deleted when the setting is on — only unlinked from the product, since they may be your own uploads or used elsewhere.

= 3.5.3 - 2026-08-06 =
**Stock**
* FIX: Two units taken off WooCommerce stock for every one sold on eBay. eBay has already deducted the quantity by the time the plugin hears about a sale, and the sync writes eBay's figure straight into WooCommerce — then WooCommerce deducted a second unit of its own when the eBay order was imported. Imported eBay orders no longer trigger that second deduction.
* Turning off "Reduce WooCommerce stock on eBay sale?" did not help, because it only governed a third path. That setting now states what it does: it applies only to products not linked to an eBay listing. Linked products always take their quantity from eBay directly.

**Variable products**
* FIX: Variable products duplicated when one sold on eBay. eBay's SKU for a variable listing usually belongs to a variation rather than the parent, and the plugin refused to match a SKU held by a variation — so it did not recognise the product it had already imported and created a second one, same attributes and price, with a SKU ending in the eBay item number. It now matches the variation's parent product, which was the intent all along.
* FIX: A product's own variation is no longer mistaken for a SKU clash, which renamed the parent's SKU on every sync of a variable listing.
* Existing duplicates are not removed automatically — only you can say which copy to keep. The duplicate is the one whose SKU ends in "-" followed by the eBay item number.

**Syncing**
* FIX: Listings added or changed while the connection was down were never imported, and no later sync went back for them. A failed scheduled sync still moved its position forward as though it had succeeded, so the period it never read was stepped over permanently. A failed sync now holds its position and re-reads that period on the next run.
* FIX: The same loss over longer outages. eBay allows only 48 hours of changes per request, so a site offline for longer catches up in stages — but each stage jumped the position to the present rather than to the end of the stage just read, discarding the backlog it was working through. It now resumes exactly where it left off.
* If products went missing during an outage, run Fetch Inventory once to bring them in.
* FIX: Scheduled syncs could be skipped silently. Each delta sync claimed a lock and never released it, so the next run reported "Stale lock detected. Breaking lock". At the default 15 minute interval that was only noise, but on a shorter interval every run after the first was refused and skipped without saying so — as was a manual Fetch Inventory started within ten minutes of a delta sync. The lock is now released on every exit, including errors.
* Connection failures are now diagnosable: "Token Refresh Error Body: null" only meant the reply could not be read. The log now records the HTTP status and the start of the actual response.

**Settings**
* FIX: Import Settings and Push to eBay Settings sit side by side again. An unclosed field in the markup was swallowing the fields below it and pushing the second panel out of the two-column layout.

= 3.5.2 - 2026-08-03 =
**Listings**
* FIX: GTC listings shown as Ended while still live on eBay. eBay renews a Good 'Til Cancelled listing roughly every 30 days and moves its end date forward, but the plugin treated the last end date it saw as final. So about a month after a listing was last synced it was marked Ended and offered a "Relist" that would have created a duplicate alongside the listing still running. eBay is now asked before anything is marked ended, and a listing reported as still active is put back to Active.
* Fixed-length listings are unaffected and cost no extra API calls. Only GTC listings and older items with no recorded duration are checked, at most 40 an hour, and each check pushes the next one out to that listing's next renewal.
* An eBay outage can no longer mark listings as ended: a failed check leaves the listing untouched.

**Coins**
* FIX: "eBay requires these item specifics ... Circulated/Uncirculated" blocking coin listings even with the condition set. The condition was sent to eBay, but never as the item specific eBay asks for, so the requirement could not be satisfied from the condition selector. It is now derived from the condition already chosen: Uncirculated for uncirculated coins and for Mint State, Proof and Specimen grades; Circulated otherwise, including "About Uncirculated" which is a circulated grade despite the name.
* A product attribute you set yourself still takes precedence.
* Item specific names no longer need to be typed exactly. "Circulated / Uncirculated" and "circulated-uncirculated" are recognised as the same field and corrected to eBay's spelling before sending. Previously a near miss passed the plugin's own check and was then rejected by eBay.

**Bulk tools**
* NEW: Change listing format in bulk from the Products list: "eBay: set format to Fixed Price / GTC" and "eBay: set format to Auction / 10 days" in the Bulk actions menu. Built for the cycle of running 10-day auctions, moving unsold items to a 30-day GTC, then switching back at a new price.
* Setting the format does not contact eBay. Select the same products again and choose "Push to eBay" to apply it: eBay cannot change a live listing's format, so each becomes a new listing.

**Images**
* FIX: Products showing only one photo when the eBay listing has several. Until an image was downloaded, the plugin represented the listing with a single stand-in — the first photo only — so the others did not exist as far as WooCommerce was concerned. The whole set is now represented immediately: first photo as the main image, the rest as the gallery.
* This is why only *some* products were affected: anything already downloaded had its full gallery, anything still queued showed one photo until its turn came.
* With "Disable background image localization" (keep eBay-hosted URLs) nothing is ever downloaded, so every product showed exactly one photo permanently. Those now show their full gallery.
* Existing products repair themselves — a background pass restores the full photo set on any product left showing a single image, a hundred at a time whenever someone is in the WordPress admin. No sync, no re-fetch and no eBay API usage: every photo address was already stored locally.
* Products with downloads still outstanding are put back in the queue rather than given eBay-hosted stand-ins, so a proper downloaded image is never replaced by one.
* Note: a product you had deliberately reduced to a single photo will have the full eBay set restored to it.
* FIX: Listings whose summary data arrives without usable picture addresses are now fetched in full from eBay rather than imported with no images.
* Housekeeping: eBay-hosted stand-ins are removed once every photo for a product has downloaded successfully. They are kept while any download is still pending, so a product never temporarily loses photos.

= 3.5.0 - 2026-07-31 =
**Deleted products**
* FIX: Products you delete no longer come back. Deleting left no record that you had done so, and because the plugin ignored items in the Trash it could not tell a deliberate deletion from a listing it had never seen — so it created the product again. A product in the Trash with the same eBay Item ID is now recognised as a deliberate deletion.
* FIX: Items that have sold or ended are no longer re-imported. The hourly sync asks eBay for everything that changed recently, and a sale counts as a change — so items were reported at exactly the moment they sold and imported as new products. This was the most common way a deleted item reappeared.
* Products that already exist for an ended listing are still marked as ended; only creating new ones is prevented.
* Deliberately skipped items are no longer counted as errors.

**Notes**
* Emptying the Trash removes the record of a deletion. If the eBay listing is still live it will be imported again as a new product — to remove an item for good, end the listing on eBay first.
* Developers: `tcgiant_sync_reimport_deleted_products` restores the previous behaviour.

= 3.4.3 - 2026-07-31 =
**Connecting to eBay**
* The window to complete a connection is now 30 minutes rather than 15, so pausing on eBay's consent screen no longer causes an otherwise valid connection to be rejected. This was described in the 3.4.2 notes but did not make it into that build.

= 3.4.2 - 2026-07-31 =
**Connecting to eBay**
* CRITICAL: Fixed being unable to connect or reconnect your eBay account, which failed with "Invalid callback data or state. Debug -> Code: NO | State: ...". Affected every version from 3.1.3 onwards, and could not be worked around — reinstalling or unlinking on eBay's side made no difference.
* Cause: 3.1.3 added a security check that sent an extra "state" value to the connection service. That service uses the presence of that value to distinguish an incoming eBay callback from a request to start a connection, so every attempt was misread as a broken callback. It is no longer sent.
* The security protection from 3.1.3 is unaffected — the safeguard is a record kept on your own site that a connection was started from the Settings page, and that is unchanged.

= 3.4.1 - 2026-07-31 =
**Relisting**
* FIX: You can now relist an ended listing. Pushing a product whose listing had ended always failed with "You are not allowed to revise ended listings", and there was no way out — the product kept its old Item ID, so every attempt revised the same ended listing again. Ending a listing effectively made the product unlistable. Pushing now creates a fresh listing automatically.
* FIX: You can now change listing type after ending. eBay does not allow a listing's format to change, so Auction to Fixed Price requires a new listing — this now happens automatically as part of the push.
* The decision comes from eBay's own response rather than locally stored status, so a listing that is still live can never be duplicated by mistake. Other errors are reported as before.
* The button reads "Relist on eBay" instead of "Update" when a listing has ended.
* The previous Item ID is kept for reference and the product is marked Active again once relisted.

= 3.4.0 - 2026-07-31 =
**New**
* NEW: "Imported Image Size" setting — choose Original, 1200px, 800px or 600px for images downloaded from eBay. eBay serves every size from the same address, so a smaller choice is requested rather than downloading the full file and shrinking it afterwards. That saves download time as well as disk space, and reduces every thumbnail WordPress generates. Roughly 100-300KB per image at Original against 50-120KB at 800px.
* Only affects images downloaded from that point on; existing images are untouched, and deduplication still tracks eBay's original address so nothing is needlessly re-downloaded.
* Developers: adjust JPEG quality for imported images with the `tcgiant_sync_image_quality` filter. Scoped to eBay imports only.

= 3.3.5 - 2026-07-30 =
**Images**
* FIX: Turning image downloading back on now actually restarts it. With "Disable background image localization" switched on, the background task stopped and was never re-scheduled — so switching the setting back appeared to do nothing, and Process Queue could not help either because no scheduled task remained. The plugin now detects images waiting with nothing scheduled and restarts the queue automatically.
* Also recovers from a lost schedule for any other reason, such as a site migration or a host clearing scheduled tasks.

= 3.3.4 - 2026-07-30 =
**Images**
* CRITICAL: Fixed broken images on sites using "Disable background image localization" (keep eBay-hosted URLs). Images never displayed at all in this mode. The eBay address was stored in the WordPress field meant for a file path, and WordPress treats that as a location inside your uploads folder — producing addresses like "https://yoursite.com/wp-content/uploads/https://i.ebayimg.com/..." which cannot load. Product images, galleries and admin thumbnails were all affected.
* Existing products are corrected automatically — no re-import needed. Images should appear immediately after updating.
* The bad file-path value is cleaned off affected images in the background so other plugins reading that field are not given a web address where they expect a filename.

= 3.3.3 - 2026-07-30 =
**Images**
* FIX: Products stuck showing an eBay-hosted image repair themselves. A failed download left a temporary stand-in pointing at eBay, which stops working once that listing ends — and the plugin could not tell it apart from a real image, so it never revisited the product. Since eBay's image addresses never change, neither a re-sync nor a full re-fetch could fix it. Affected products are now re-queued automatically on the next sync. No action needed.
* FIX: "Re-sync images only" genuinely re-downloads now. It previously skipped products whose eBay image addresses had not changed — exactly when you would be reaching for it.

= 3.3.2 - 2026-07-30 =
**Operations buttons**
* FIX: "Prune Inventory" posted the same action as "Fetch Inventory", so it started a full import instead of a prune. It now has its own action, accurate wording and a confirmation.
* FIX: "Process Queue" only ran Action Scheduler jobs — continuing a page scan and downloading images are WordPress scheduled tasks, so on a site with broken cron it did nothing for the two things that actually stall. It now runs both and reports what it ran.
* FIX: "Fetch Inventory", "Resume Import" and "Sync Specific Items" always reported success even when declined because a sync was already running or the import limit was reached.
* FIX: "Process Queue" no longer bounces you to the Dashboard from the Import screen, or claim to have processed an empty queue.
* FIX: The REST sync endpoint reported success under the same conditions and now returns a proper error.

= 3.3.1 - 2026-07-30 =
**Security**
* Credentials are no longer written to the activity log. A failed token refresh logged the relay's full response verbatim, which could place an access token, refresh token or the relay signing key into a plaintext file that ends up in support bundles and backups.

**New**
* NEW: Listings created from WooCommerce now include your product attributes as eBay item specifics. Previously they went up with almost none, which eBay penalises in search — and for categories with mandatory aspects, rejects.
* NEW: Required item specifics are checked before pushing, naming exactly which are missing instead of failing at eBay with a generic error. Skipped if eBay's metadata service is unreachable, so an outage can never block listing.
* NEW: "Host listing images on eBay?" setting. Listings normally reference images on your own site, which requires eBay to reach it — that fails on password-protected sites, behind bot protection, and breaks on a domain change. Uploads to eBay Picture Services instead, cached per image, with fallback to the site URL if an upload fails.

**Background tasks**
* FIX: Page scanning no longer stalls between batches — the immediate-continue request was being ignored because the scanner runs from cron itself, so each batch waited for the next scheduled tick.
* FIX: Background work now runs on hosts that disable WP-Cron. Those sites never ran image localization at all, leaving products on eBay-hosted images that stop working when the listing ends.
* NEW: "Background Tasks" indicator in System Health, so a misconfigured host is visible instead of silently doing nothing.

= 3.2.1 - 2026-07-30 =
* Re-release of 3.2.0 so every install receives the complete update. The 3.2.0 release went out a few minutes before its final two fixes landed, so some sites may have downloaded a 3.2.0 build missing the product-duplication fix and the Settings page layout fix — and with an identical version number, would never have been offered a correction.
* No functional changes beyond 3.2.0. Harmless if you already have a complete 3.2.0.

= 3.2.0 - 2026-07-30 =
**Data safety**
* CRITICAL: Duplicating a product no longer copies its eBay listing link. WooCommerce copies all custom fields on duplicate, so the copy inherited the original's eBay Item ID and appeared already listed — while pointing at the original's live listing. Pressing Update on the copy would have overwritten the original's real listing, and End Listing would have closed it. Duplicates now start unlinked. Export settings (category, condition, grader, policies) are still copied.
* CRITICAL: Products duplicated BEFORE this fix still carry the stale link. Push and End Listing are now blocked for any product sharing an eBay Item ID, an admin notice lists the affected products, and a new "Unlink from eBay" button clears the bad link. Unlinking is local only and never changes the eBay listing.
* CHANGED: Variations that disappear from an eBay listing are no longer deleted — they are set to private and out of stock. A partial eBay response previously destroyed them permanently along with their links to past orders. Retiring is reversible; deleting was not.
* FIX: eBay order values are parsed properly. Amounts arriving as structured values (whenever eBay includes a currency attribute) were cast directly to a number, silently producing $1.00 orders. Totals, shipping, line prices and quantities now share the product importer's normalizer.
* FIX: Order import reads every page — it previously requested only the first 100 orders per day and ignored the rest.

**Fixed**
* FIX: Settings page two-column layout — "Import Settings" and "Push to eBay Settings" were stacking vertically instead of sitting side by side. The Import card was missing its closing tag, so the Push card nested inside it. This is the same markup fault the 3.1.1 and 3.1.2 CSS changes were chasing.
* FIX: The Listings page and auto-relist scheduler were reading a snapshot frozen at install time; the listings table was never written to after the initial migration. It is now kept current on every import.
* FIX: Store categories are no longer lost for an hour after a failed lookup, which caused products to import uncategorised.
* FIX: Bulk "Push to eBay" no longer reports success before contacting eBay. Queued and completed are now counted separately.
* FIX: The progress bar's colour gradient, lost in 3.1.2 when the stripe overlay replaced it instead of layering over it.
* FIX: Saving settings could fail on nested values.
* FIX: Job status lookups now require administrator permissions.

**Security & privacy**
* The activity log filename now includes a per-site random token. The log directory relies on an .htaccess rule that nginx ignores, so the log — containing product titles, SKUs and raw eBay API responses — was readable at a predictable URL on nginx.
* eBay-supplied business policy names are escaped before display.

**Maintenance**
* Removed ~760 lines of unreachable code (the pre-3.0.0 image downloader and pre-2.0.0 page scanner). The old scanner counted progress differently, which broke "Resume Import" after an interrupted sync.
* Action Scheduler cleanup now removes matching log rows instead of leaving them to accumulate.
* PHPStan static analysis reports zero findings, and runs in CI with a syntax check on every change.

= 3.1.4 - 2026-07-30 =
**Performance**
* PERFORMANCE: Added a database index for the plugin's metadata lookups. Image deduplication and eBay Item ID matching previously scanned every matching row in wp_postmeta on every lookup — roughly 80,000 full scans during a 10,000-product sync. Applied once, automatically, and skipped safely if the database user lacks permission.
* PERFORMANCE: The image deduplicator no longer duplicates attachment records. Reusing an image across products created a new media library entry each time, bloating the library and leaving copies that broke if the original file was deleted.
* PERFORMANCE: The scan's active-listing tracker is no longer autoloaded — on a 10,000-item store it grew to ~300KB and was loaded on every page request, front-end included, for the duration of the sync.
* PERFORMANCE: Bulk job progress no longer rewrites the whole job history after every batch (~1,000 rewrites of a large option for a 5,000-product push).
* PERFORMANCE: Log writes are buffered and flushed once per request rather than appending to the file on every line, and routine info lines are no longer duplicated into the WooCommerce log.
* NEW: "Log Detail" setting under Settings → Scheduling. "Warnings and errors only" substantially reduces disk activity on large stores.

**Reliability**
* FIX: eBay requests retry transient failures. A single DNS blip, dropped connection or eBay 5xx used to fail an entire sync job outright.
* FIX: eBay HTTP 429 responses are recognised as rate limiting and honour the Retry-After header.
* FIX: The daily API call counter is incremented atomically — concurrent workers were overwriting each other's counts, so the daily budget safeguard engaged later than intended.
* FIX: Log rotation now looks in the directory the plugin actually writes to; the daily cleanup had been checking a path that never contained logs.

**Uninstall**
* FIX: Uninstalling now actually removes the plugin's data. The routine referenced option names that never existed, so deleting the plugin left everything behind — including the stored eBay access token, refresh token and relay signing key. Products and orders are deliberately left untouched.

= 3.1.3 - 2026-07-30 =
**Security**
* SECURITY: The eBay OAuth callback accepted account credentials without verifying who sent them — no nonce, no capability check. Any logged-in user, or an administrator following a crafted link, could replace the site's stored eBay tokens and relay signing key, redirecting the store's listings and stock pushes to another eBay account. Connecting now goes through a nonce-protected handler with a one-time state token, and the callback requires an administrator plus a pending handshake.
* SECURITY: Removed the hardcoded fallback key used to verify eBay Marketplace Account Deletion notifications. The key shipped inside the plugin, so it could be used to forge notifications against sites that had not yet been issued a per-site key — and that endpoint erases customer data from orders. If notifications stop being accepted after updating, reconnect your eBay account from Settings to be issued a key.
* SECURITY: Deletion notifications older than 5 minutes are now rejected, preventing replay of a captured request.

**Critical fixes**
* CRITICAL: Importing from eBay could permanently END live eBay listings. Imported stock was being pushed straight back to eBay, and anything mapping to 0 available stock triggered auto-end — so sold-out-but-active listings were closed simply by importing them. Stock that originates from eBay is no longer pushed back.
* CRITICAL: That same loop queued one wasted eBay API call per imported product. A 10,000-item import no longer generates 10,000 pointless stock pushes.
* CRITICAL: A single failed API request part-way through a scan was treated as "no more pages", so the scan ended early and the orphan pruner trashed every product on the pages never reached. Failed requests are now reported as errors, progress is kept, and pruning is blocked unless the scan completed cleanly.
* CRITICAL: Orphan pruning gained two more safety nets — it refuses to run when no active eBay Item IDs were recorded, and aborts if it would retire more than 20% of your eBay-linked products at once.
* CRITICAL: Image migration on relist could strip images off live products when two active listings shared one seller SKU. It now only harvests images from trashed products.
* CRITICAL: Background image localization marked products "done" even when every download failed, losing those images permanently. Failures are now retried up to 3 times with a 10-minute backoff, and permanent 404/403 errors are recognised rather than retried forever.
* CRITICAL: The weekly inventory reconciliation crashed on every run (it called a method that does not exist), taking down other scheduled tasks in the same cron tick. It now works, checks every page instead of only the first 200 listings, and compares available stock rather than listed quantity.

**Fixes**
* FIX: Relisting always failed with "The UUID can only contain Numbers and Letters from A to F" — the v3.1.1 UUID fix missed the relist call, so auto-relist and bulk relist never worked.
* FIX: eBay Marketplace Account Deletion requests matched no orders and erased nothing, because the handler searched a meta key the plugin never writes.
* FIX: The setup wizard never showed eBay as connected and never advanced past step 1.
* FIX: Order-driven stock reductions and orphan pruning no longer echo redundant calls back to eBay.

= 3.1.2 - 2026-07-29 =
* FIX: Settings page two-column layout — removed 53 duplicated CSS rules that were overriding the grid definitions.
* FIX: Images are preserved when eBay relists an item under a new Item ID and SKU, instead of being re-downloaded.
* Performance: Global image deduplication — a previously downloaded image is reused across products.
* Performance: Image localization processes 50 products per batch (up from 10), cutting total localization time for large stores.

= 3.1.1 - 2026-07-29 =
* FIX: UUID format error on Push to eBay — eBay requires 32 hex characters without hyphens.
* FIX: "No changes allowed on ended listings" error — stock sync now handles already-ended listings gracefully and marks local status as Ended.
* FIX: Settings page two-column layout — added the missing CSS definitions for tc-card, tc-row-2col and tc-row-3col.
* FIX: Stock was being synced to eBay for products that no longer exist in WooCommerce. All SKU lookups now exclude trashed products.

= 3.1.0 - 2026-07-29 =
* Feature: Setup Wizard — multi-step guided setup (Connect → Import → Export → Done).
* Feature: REST API — five endpoints under /tcgiant-sync/v1/ for listings, listing detail, sync status, start sync and orders.
* Feature: Job Queue — AJAX batch processor with progress tracking for bulk operations.
* Feature: Bulk actions on Listings — bulk Push, End and Relist with a modal progress bar.
* Feature: Verify button on the product edit screen — calls VerifyAddItem to show estimated fees and catch errors before listing.
* Feature: Auto-Relist Scheduler — daily check for ended listings with stock remaining. Off by default.
* Feature: eBay Order column on the WooCommerce Orders list (HPOS and legacy compatible).
* Feature: UUID duplicate prevention on AddItem, so a network timeout and retry cannot create two listings.

= 3.0.1 - 2026-07-29 =
* CRITICAL FIX: Products were not matching by SKU when eBay changed the Item ID (old listing expired, seller created a new one), creating duplicate products with mangled SKUs like "SKU-123456789".
* FIX: Trashed WooCommerce products were blocking SKU matching and causing false "Duplicate SKU detected" warnings.
* FIX: Trashed products could be matched by _ebay_item_id. The lookup now excludes them.

= 3.0.0 - 2026-07-29 =
* CRITICAL: Deferred image pipeline — products receive eBay image URLs immediately during sync and images are downloaded to the media library in the background. Eliminates the 7,600+ queued image jobs that blocked scanning for days.
* Feature: Full eBay order import via GetOrders, polling every 15 minutes, with address and line-item mapping, deduplication by eBay Order ID, and HPOS compatibility.
* Feature: Tracking number push to eBay when a WooCommerce order is marked Completed.
* Feature: Real-time WooCommerce → eBay stock sync for manual edits, CSV imports and REST API changes.
* Feature: Listings admin page — filterable, sortable, searchable table of all eBay-linked products.
* Feature: Best Offer support with auto-accept and auto-decline thresholds.
* Feature: GPSR compliance fields (manufacturer, address, EU responsible person).
* Feature: Custom database table for eBay listing data with indexed columns.
* Performance: File-based concurrency lock, cron rate limiting, and a daily maintenance task.

= 2.1.0 - 2026-07-28 =
* CRITICAL FIX: Scan resume now uses WP-Cron instead of Action Scheduler. When PHP timed out, the resume action was getting stuck behind 2,800+ image/import jobs in the AS queue for hours. WP-Cron events fire directly on the next page load, bypassing the AS batch queue entirely.
* Scan batching: Processes 10 pages per execution then immediately schedules the next batch. A 47-page store completes in ~5 minutes across 5 batches, regardless of PHP time limits.
* Feature: End eBay listings directly from WordPress — new "⛔ End Listing" button on product list and edit screens with confirmation dialog.
* Feature: SKU format setting — choose between plain Item Number (185727729088) or EBAY- prefix (EBAY-185727729088) for new imports. Default is now plain number.
* Feature: Staging environment detection — automatically detects staging/dev sites and blocks write API calls (push, revise, end) to prevent accidental changes to live listings.
* Fix: Relay server now sends User-Agent header (TCGiant-Sync-Relay/2.1) to prevent Cloudflare/WAF from blocking deletion notifications as bot traffic.

= 2.0.0 - 2026-07-27 =
* BREAKING: Complete page scan architecture overhaul. Scanning no longer uses per-page Action Scheduler jobs (which got stuck behind thousands of import/image jobs). Instead, all pages are scanned in a single direct PHP loop with sleep(2) between API calls. A 47-page store now completes scanning in ~3 minutes instead of 4+ days.
* Performance: Added IncludeVariationSpecifics and HideVariations=false to GetSellerList API call. eBay now returns complete variation data inline, dramatically reducing the number of GetItem fallback calls needed.
* Reliability: Auto-resume on PHP timeout — if the server kills the scan loop, it saves progress and auto-schedules a resume within 30 seconds.

= 1.9.1 - 2026-07-24 =
* Performance: GetItem fallback imports moved to separate Action Scheduler group ('tcgiant_sync_imports'). This was the second bottleneck — 600 GetItem calls were blocking the page scan from advancing. Now three fully parallel queues: page scanning, product imports, and image downloads.

= 1.9.0 - 2026-07-24 =
* Feature: Persistent shipping policies — eBay policies now stored permanently in wp_options instead of 1-hour transients. No more "forgetting" after an hour.
* Feature: Sortable & filterable eBay column — dropdown filter above the WooCommerce Products list with options: Listed on eBay, Not on eBay, Ended, Auctions Only, Fixed Price Only. Column is also sortable.
* Feature: Listing type badges — each product now displays its eBay listing type (Auction/Fixed Price), duration (GTC, 10 Days, etc.), and end date directly in the product list column.
* Feature: Automatic ended-listing detection — hourly cron checks for products whose eBay end time has passed and marks them as "Ended" with a red ⛔ indicator.
* Data: eBay listing metadata (ListingType, ListingDuration, EndTime, ListingStatus) now stored during import for all future syncs.

= 1.8.5 - 2026-07-24 =
* Performance: Critical fix — image downloads now run in a separate Action Scheduler group ('tcgiant_sync_images') so they no longer block page scanning and product imports. Previously, 700+ image download jobs would queue ahead of the next page scan, causing the sync to stall for hours. Page scanning and image downloads now run in parallel.

= 1.8.4 - 2026-07-23 =
* Performance: Dramatically faster page scanning for large stores. Previous logic waited up to 5+ minutes between pages (3s × queued items). Now caps next-page delay at 30s max and GetItem fallback stagger at 60s. A 47-page store that previously took hours to scan now completes much faster.

= 1.8.3 - 2026-07-23 =
* Feature: Dashboard now shows full live sync status — page progress (e.g. "Page 5/47 - All Categories"), Products/Queued/Pending Jobs counters, progress bar during import phase, and a spinning "Sync in Progress" indicator. All stats auto-update via AJAX polling.

= 1.8.2 - 2026-07-23 =
* Feature: Added "Import All Listings" toggle to the Settings page. A prominent green switch at the top of Import Filters makes it clear and easy to import every active eBay listing regardless of category. Toggling it off reveals the Custom and Standard category filter sections for selective imports.

= 1.8.1 - 2026-07-23 =
* Fix: Per-product shipping policy dropdown now correctly reads cached policies from eBay — previously empty due to a transient key mismatch.
* Feature: Added "Year" field for Coins & Paper Money listings. eBay requires the Year item specific for all coin categories; a new text input appears when the item type is set to Coins (both graded and ungraded).

= 1.8.0 - 2026-07-22 =
* Fix: UUID format corrected — eBay requires exactly 32 hex characters (no hyphens). `wp_generate_uuid4()` produces 36 characters with hyphens; the fix strips them. This was blocking ALL listing pushes with the error "The UUID can only contain Numbers and Letters from A to F and must be 32 characters long."

= 1.7.9 - 2026-07-22 =
* Feature: Added explicit "Disconnect" button on the Settings page to easily clear stored OAuth tokens when switching eBay seller accounts.

= 1.7.8 - 2026-07-22 =
* Fix: Adjusted GetSellerList EndTime scan range from 3650 days to 120 days to comply with eBay Trading API maximum date range restrictions, resolving "time range has exceeded 121 days" error during full syncs.

= 1.7.7 - 2026-07-22 =
* Fix: Token refresh timeout increased from 5s to 30s to prevent transient cURL error 28 timeouts on relay requests.
* Fix: Corrected false-positive rate limit classification where generic eBay error messages containing the word "exceeded" (e.g. token expiration) were misidentified as rate limit hits.
* Feature: Automatic token refresh retry when eBay returns an expired or invalid IAF token during API requests.

= 1.7.6 - 2026-07-22 =
* Feature: Text-based coin grade aliases — "Gem BU", "Choice BU", "Superb Gem", "Gem Proof", "Choice Proof", "Choice AU", "Choice XF", and "Choice VF" are now available in the graded coin dropdown. Each maps to its Sheldon-scale equivalent (e.g. Gem BU ≈ MS 65) for eBay submission.
* Fix: UUID duplicate prevention — every AddItem request now includes a unique UUID. If a network timeout causes a retry, eBay deduplicates the request instead of creating a duplicate listing.

= 1.7.5 - 2026-07-22 =
* Improvement: eBay daily API call limit updated from 5,000 to 50,000 following Application Growth Check approval.
* Feature: Centralized API usage tracking — sites report daily call counts to the relay server, enabling global usage monitoring.
* Feature: Dashboard "API Calls Today" stat card with color-coded progress bar and usage chart.
* Feature: Per-product Listing Type (Fixed Price / Auction) and Duration (GTC, 30, 10, 7, 5, 3, 1 days) overrides with global defaults on Settings page.
* Feature: Per-product Shipping Policy override — select a different fulfillment policy for individual products.
* Feature: "All Other" item type for non-collectible products — skips grading fields, uses simple conditions.
* Improvement: Auction listings enforce Quantity = 1 and validate duration compatibility.
* Improvement: Duration dropdown dynamically filters to valid options based on listing type.
* Fix: Adaptive throttling for large store imports (10,000+ items) with exponential backoff on rate limits.

= 1.7.2 - 2026-07-18 =
* Fix: Resolved image import timeouts for products with many variants. Image downloads are now processed in chunked batches (2 at a time) to prevent PHP execution limits from silently killing the process.
* Feature: Variation-specific images from eBay are now imported and assigned to the correct WooCommerce variation thumbnail.
* Feature: New "Sync Specific Items" tool on the Import page — enter eBay Item IDs to sync individual products without running a full catalog scan.
* Feature: New "Re-sync images only" checkbox — re-download images for specific products without touching titles, prices, or stock.
* Feature: Auto-retry for failed image downloads — transient network failures get a single automatic retry after 60 seconds.
* Improvement: Image download error logs now include the product ID, variation ID, and the full URL that failed for easier debugging.
* Improvement: Each product now tracks image sync status (`_tcgiant_image_status` meta) with expected vs actual download counts to detect partial imports.
* Improvement: HTTP timeout for image downloads capped at 30s (down from WordPress default 300s) to prevent queue stalls on slow connections.
* Improvement: Memory limit bumped during image processing to handle large eBay photos on shared hosting.
* Fix: Emergency Stop now also cancels pending image download jobs (previously orphaned chunked chains would keep running).
* Fix: Starting a new full/delta sync now clears any leftover image download jobs from previous syncs.

= 1.7.1 - 2026-07-17 =
* Fix: Export <Country>, <Currency>, and <Site> XML tags now use marketplace-derived values instead of hardcoded US/USD.
* Fix: Full sync inline processing now requires ItemSpecifics and PictureDetails before processing inline. Products missing either are routed to GetItem fallback, ensuring attributes and images import reliably.
* Improvement: MARKETPLACES constant now includes country, currency, and site fields for all 8 supported markets.
* Improvement: Settings page placeholder text updated with international examples (e.g., "London, UK", "SW1A 1AA") and marketplace dropdown hint clarifies it controls export Country/Currency/Site.

= 1.7.0 - 2026-07-17 =
* Feature: Unified "eBay Listing" tab replaces the separate "Grading & Condition" and "TCGiant Sync" tabs. All export fields are now in a single panel with a guided 4-step flow.
* Feature: Step-by-step push wizard: Item Type -> Category -> Condition -> Push to eBay. Sections use progressive disclosure and auto-expand as you complete each step.
* Feature: Visual card selectors for Item Type (Trading Cards / Coins) and Condition (Graded / Ungraded) replace plain dropdowns.
* Feature: Category auto-suggestion via eBay's Taxonomy API. Click "Suggest Category from Title" and select from clickable suggestion pills.
* Feature: Pre-push readiness checklist validates category, condition, business policies, images, and title length before queuing.
* Feature: Category browser now filters by item type -- Coins items start at "Coins & Paper Money" root, TCG items start at "Toys & Hobbies" root.
* Feature: Clear Error button to dismiss export errors and retry cleanly.
* Improvement: Smart AJAX polling with adaptive intervals -- reduces server load on non-export pages.
* Improvement: Transient caching for sync stats (30s) and static caching for category lists.
* Improvement: Pre-push validation runs synchronously before queuing, preventing invalid listings from entering the export queue.
* Improvement: All icons use WordPress Dashicons instead of emoji for reliable cross-platform rendering.
* Fix: Grader and grade field name mismatches for item-type-specific suffixes (_tcg, _coins) now resolve correctly.
* Fix: Category-item type mismatch detection warns when a Coins category is set for a TCG item and vice versa.

= 1.6.3 - 2026-07-15 =
* Feature: New "Push to eBay" column on the WooCommerce Products list table. View eBay category, item ID link, push date, and error status for every product at a glance.
* Feature: Inline "Push to eBay" / "Update" buttons in the product list — one-click push without opening the product editor.
* Feature: AJAX-powered push queue with real-time status feedback ("Queuing…", "✔ Queued!", or error messages) inline.
* Improvement: Red "No eBay category" warning for products that need configuration before export.
* Improvement: Category column resolves human-readable names from built-in lists, saved custom categories, and per-product overrides.

= 1.6.2 - 2026-07-15 =
* Fix: Resolved all Coin ConditionDescriptor errors when pushing graded and ungraded coins to eBay. Grader, Letter Grade, and Numeric Grade values now use the correct eBay conditionDescriptorValueIds instead of raw text strings.
* Fix: Ungraded Coin Condition now uses eBay descriptor Name 2 with correct value IDs instead of the TCG descriptor Name 40001.
* Fix: Coin Certification Number now uses descriptor Name 5 (not 2) with a 20-character max per eBay requirements.
* Fix: TCG graders (Ace, AGS, DSG, Majesty, GRAAD, Arena Club, AiGrading) now use correct numeric IDs. Removed 3 delisted graders (PCA, TCG, ARK).
* Improvement: Added all 16 eBay-recognized coin grading companies with correct numeric IDs.
* Improvement: Added complete Sheldon scale numeric grade ID mapping (70 through 1).
* Feature: Category browser now remembers and displays the selected category name persistently (e.g., "✔ US Coins (253)") on Settings, Product Edit, and Export pages.

= 1.6.1 - 2026-07-15 =
* Performance: Dramatically faster full syncs for large stores (10,000+ items). Variation items are now processed inline when GetSellerList returns complete data, eliminating thousands of individual GetItem API calls.
* Performance: Image downloads in the GetItem fallback path are now scheduled asynchronously, preventing long blocking operations from clogging the Action Scheduler queue.
* Performance: Reduced per-item stagger delay from 3 seconds to 1 second for items that still require GetItem fallback.
* Performance: Reduced next-page scanning delay from 5 seconds to 2 seconds when all items on a page are processed inline.

= 1.5.6 - 2026-07-10 =
* Fix: Resolved another fatal error (`Undefined constant GRADES`) in the settings page that was preventing Javascript from loading and rendering the "Load Store Categories" button inert.

= 1.5.5 - 2026-07-10 =
* Fix: Resolved a fatal error (`Undefined constant GRADERS`) on the settings and export pages caused by the recent splitting of the graders list into separate Trading Cards and Coins arrays.

= 1.5.4 - 2026-07-10 =
* Feature: Added "eBay Standard Categories to Import" filter to settings to allow importing specific standard categories (e.g. Trading Card Games, Coins: US) without requiring an eBay Store subscription.
* Improvement: Replaced the manual eBay Category ID text input on the product page with a searchable dropdown of supported Standard Categories, while retaining a "Custom Category ID" option for subcategories.
* Fix: ConditionDescriptor validation now checks the "Item Category" (e.g. Coins, Trading Cards) set in the Grading tab instead of just checking the numeric Category ID, resolving issues where custom/deep subcategories would trigger an "Invalid Category" API error.
* Fix: Added robust error handling and UI feedback to the "Load Store Categories" button if the API request fails or the seller lacks a Store subscription.

= 1.5.0 - 2026-07-08 =
* Feature: Added eBay ConditionDescriptor support for Trading Cards and Coins categories. eBay now mandates structured condition data (Graded vs. Ungraded) for these categories — listings missing this data will be blocked.
* Feature: New "Condition Type" setting with Graded (Professional Grader + Grade + optional Cert Number) and Ungraded (card/coin condition) options that generate the required `ConditionDescriptors` XML.
* Feature: 21 professional grading companies supported (PSA, BGS, SGC, CGC, CSG, and more) with correct eBay numeric value IDs.
* Feature: Category-aware ungraded conditions — Trading Cards show Near Mint, Excellent, Very Good, Poor, etc.; Coins show Uncirculated, Extremely Fine to AU, Fine to VF, Below Fine.
* Feature: Per-product condition descriptor overrides in the TCGiant Sync product tab.
* Feature: Added Coins categories (US, World, Canada, Ancient, Medieval) to the export category dropdown.
* Improvement: Export validation now checks for required ConditionDescriptor fields when the target category is TCG or Coins.
* Improvement: Export status page displays structured condition info (e.g., "Graded · PSA · 10").

= 1.4.8 - 2026-07-08 =
* Fix: Resolved "No <Item.Location> exists" error when pushing products to eBay. The required `<Location>` and `<PostalCode>` XML tags were missing from the AddItem/ReviseItem API call.
* Feature: Added "Item Location" and "Postal Code" fields to the Push to eBay settings page.
* Improvement: Export validation now catches missing Location and Postal Code before hitting the eBay API, providing a clear error message instead of a raw API error.

= 1.4.7 - 2026-07-01 =
* Feature: Added a native WooCommerce "BIN" field to the product inventory tab. This visually surfaces the `_bin_location` meta field, enabling users who map eBay Custom SKU to Bin Location to view and edit warehouse bin codes directly inside WooCommerce.

= 1.4.6 - 2026-06-29 =
* Feature: New "eBay SKU Maps To" setting with two modes: "Product SKU" (default) or "Bin Location". When set to Bin Location, the eBay SKU is stored as a `_bin_location` custom meta field instead of the WooCommerce SKU — useful for sellers who use the eBay SKU field for warehouse/shelf location codes (e.g., "A3-B7"). The WooCommerce SKU automatically falls back to ISBN/UPC/EAN or EBAY-{ItemID}.
* Improvement: Added Free Shipping guidance tip under the "Add eBay Shipping Cost to Product Price" setting, directing users to configure a WooCommerce Free Shipping zone for an all-inclusive pricing setup.

= 1.4.5 - 2026-06-29 =
* Fix: Weight and dimensions are now correctly imported for non-US marketplaces (UK, CA, AU, DE, FR, IT, ES). Metric values from eBay (e.g., 200g, 34cm) were incorrectly treated as imperial and double-converted. The plugin now uses the configured marketplace to determine the source unit system.
* Fix: Item Specifics (specs/attributes) now import correctly during scheduled delta syncs. The GetSellerEvents API call was missing the IncludeItemSpecifics flag.
* Fix: Product images now import reliably during delta syncs. If GetSellerEvents omits image or spec data, the plugin automatically falls back to a GetItem call.

= 1.4.4 - 2026-06-22 =
* Feature: New "Add eBay Shipping Cost to Product Price" setting in Import Settings. When enabled, the eBay flat-rate shipping cost is automatically added to the WooCommerce product price on import (e.g., $10 item + $5 shipping = $15). Free shipping and calculated shipping items are unaffected.

= 1.4.3 - 2026-06-22 =
* Fix: Resolved category filter not matching any items during full sync. The eBay GetSellerList API was not returning Storefront and PrimaryCategory data because OutputSelector tags were overriding DetailLevel. Switched to DetailLevel=ReturnAll to guarantee all item fields are returned.
* Fix: Import log now distinguishes between total items scanned vs. items matched by the category filter (e.g., "Scanned 100 items, 3 matched").
* UX: Added a contextual settings summary bar on the Import page showing active category filter, auto-sync interval, and data mapping settings at a glance with a direct link to edit.
* UX: Dashboard "Recent Activity" log now renders at full width.

= 1.3.1 - 2026-06-16 =
* Security: Replaced the hardcoded webhook signing key with a unique, cryptographically random per-installation key. Each site now receives its own signing key during OAuth connection, preventing forged Marketplace Account Deletion requests.
* Improvement: Legacy installations continue to work using the previous key until they re-authenticate.

= 1.3.0 - 2026-06-16 =
* Feature: Import product weight and package dimensions (length, width, height) from eBay listings into WooCommerce. Values are automatically converted to match your store's configured weight and dimension units.
* Feature: Added "Overwrite Weight & Dimensions" toggle in the Data Mapping settings section, allowing users to control whether weight/dimensions are updated on re-imports (defaults to Yes).
* Improvement: Import activity log now shows imported weight alongside existing product details for verification.

= 1.2.3 - 2026-06-09 =
* Fix: eBay Item Specifics (specs) are now correctly saved as WooCommerce Product Attributes. Fixed a WooCommerce compatibility issue where custom attributes were silently discarded during save.
* Fix: Import now survives eBay API rate limits. When the daily API call limit is reached during a large import (6,000+ items), the sync pauses and auto-retries instead of restarting from scratch.
* Feature: Added "Resume Import" button for manual recovery after rate limiting. Progress is fully preserved across pauses.
* Improvement: Import log now shows the number of attributes detected per product.

= 1.2.2 - 2026-06-08 =
* Fix: Addressed an issue where custom variation attribute keys were incorrectly formatted, causing them to not link to parent product attributes.
* Improvement: Renamed the "eBay Sync Log" product data tab to "TCGiant Sync" to make export category and condition overrides more discoverable.
* Feature: Added a new setting to "Import Item Specifics as Product Tags", allowing all eBay Item Specifics to be imported as WooCommerce Product Tags in addition to Product Attributes.
* Improvement: Enhanced pricing sync logic to automatically detect Markdown Manager sales. If an original retail price is detected, WooCommerce will now correctly populate the Regular Price and Sale Price fields, automatically enabling the Sale badge.
* Fix: Corrected the 3-column layout on the dashboard and removed the hardcoded background color to seamlessly blend with the WordPress admin interface.

= 1.2.1 - 2026-06-08 =
* Feature: Complete UI/UX overhaul of the admin Dashboard.
* Feature: Added a persistent "Getting Started Checklist" onboarding widget for new users.
* Feature: Implemented a dedicated "Activity Logs" submenu page to view up to 1,000 historical events without dashboard clutter.
* Improvement: Truncated the Dashboard Activity Log to only display the 10 most recent events with a "View All" link to the new Logs page.
* Improvement: Reorganized dashboard layout into a clean 3-column "At a Glance" metrics row and a dedicated "Quick Actions" hub.

= 1.2.0 - 2026-06-08 =
* Feature: Full support for bidirectional Variation syncing! You can now map eBay Variations directly into WooCommerce Variable products. The plugin also properly pushes WooCommerce Variable products natively to eBay.
* Feature: The Mapper now dynamically extracts and maps *all* available eBay Item Specifics into WooCommerce Attributes (e.g. Brand, Material, Scale), ensuring robust native support for non-TCG categories.

= 1.1.7 - 2026-06-08 =
* Feature: Added automatic mapping of standard eBay PrimaryCategory to WooCommerce categories (useful for standard/free eBay accounts without Store Categories).
* Feature: eBay SKUs now accurately map from ISBN, UPC, or EAN values found in Product Listing Details or Item Specifics if the custom label is blank.
* Security: Implemented stricter capability checks for several informational admin AJAX endpoints.

= 1.1.6 - 2026-06-08 =
* Feature: Added an option to "Preserve Categories" in settings. Items in these WooCommerce categories will no longer be sent to the trash when they sell out on eBay; instead, their stock will be set to 0 and they will be unlinked from the old eBay listing.

= 1.1.5 - 2026-06-05 =
* Feature: Added Pushed and Pulled product metrics to the dashboard and telemetry payload to accurately differentiate sync direction.
* Fix: Addressed an issue where `thecardboardshop.com` and other instances reported as 'free' with '0 synced' by correcting JSON payload formatting and validation logic in the telemetry ping.

= 1.1.4 - 2026-06-05 =
* Feature: Added WooCommerce HPOS (High-Performance Order Storage) compatibility.
* Compatibility: Declared WooCommerce compatibility up to 10.0.
* Fix: Scheduled cron events on `init` to prevent early translation loading warnings.
* Fix: Corrected telemetry ping payload formatting.
* Security: Added permission callback to webhook REST API endpoint.
* Security: Sanitized autoloader file names to prevent path traversal.

= 1.1.3 - 2026-05-28 =
* Feature: Added Global Marketplace Support. You can now select your primary eBay regional site (e.g., UK, Canada, Australia) in settings to import and push listings internationally.

= 1.1.2 =
* Feature: Added modern horizontal tab navigation across all admin pages.
* UX: Implemented "Premium Empty States" for Recent Sales and Activity Logs.
* UX: Added CSS animations (barber pole effect) to progress bars during sync operations.
* UX: Custom dark-mode sleek scrollbars added to the Activity Log viewer.
* Polish: Form inputs and buttons updated with subtle hover and focus states.
* Feature: Added "Clear" button to Recent Sales widget on Dashboard.

= 1.1.1 - 2026-05-26 =
* Compatibility: Tested and verified for WordPress 7.0.
* Feature: Telemetry now includes license plan data.

= 1.1.0 - 2026-05-11 =
* Feature: Smart duplicate SKU resolution during import. Automatically detects if an eBay SKU is already used by a different product and appends the eBay Item ID to prevent WooCommerce overwriting.
* Feature: Activity Log is now fully responsive and available on all plugin pages (Dashboard, Import, Export).
* Feature: Visual feedback and auto-hide added to the "Recent Sales — Sync to eBay" dashboard widget upon successful manual push.
* Feature: Dashboard automatically filters out recently pushed orders so they don't persist after success.
* Feature: Added telemetry ping to track successful exports.

= 1.0.3 - 2026-04-29 =
* Feature: "Recent Sales — Sync to eBay" dashboard panel showing last 10 WooCommerce orders with a per-order Push to eBay button.
* Feature: Direct AJAX order sync — bypasses Action Scheduler and WP-Cron entirely to prevent accidental eBay→WooCommerce re-import side effects.
* Fix: eBay Trading API now correctly handles qty = 0 — instead of sending an invalid ReviseInventoryStatus request, the listing is ended via EndItem (NotAvailable).
* API: Added end_item() Trading API method for closing sold-out listings.

= 1.0.2 - 2026-04-24 =
* Feature: Added "Push to eBay" exporter module — create eBay listings directly from WooCommerce.
* Feature: Bulk push via WooCommerce Products list bulk action (Action Scheduler powered).
* Feature: Per-product Push to eBay button in the product edit screen (eBay Sync Log tab).
* Feature: Per-product eBay Category ID and Condition overrides with global defaults fallback.
* Feature: Export Defaults settings section — default Category ID, Condition, and Business Policy selectors.
* Feature: One-click Fetch Policies from eBay — populates Shipping, Return, and Payment policy dropdowns live.
* Feature: Smart re-push detection — updates existing listings via ReviseItem instead of creating duplicates.
* Feature: eBay Business Policies prerequisite notice with context-aware red warning / blue confirmation states.
* API: Added Trading API AddItem and ReviseItem support.
* API: Added REST Account API policy fetching (fulfillment, return, payment).

= 1.0.1 - 2026-04-24 =
* Fix: Suppressed API error logging for WooCommerce-only items that don't exist on eBay.
* Feature: Added WooCommerce category selectors to lock down inventory outgoing sync.

= 1.0.0 - 2026-04-09 =
* Initial release.
* eBay OAuth 2.0 connection via secure relay.
* Product import with automatic WooCommerce mapping.
* Inventory synchronization between eBay and WooCommerce.
* Freemium licensing with Pro upgrade path.
* Admin dashboard with live status polling and logs.
* Store category filtering for targeted imports.
* Marketplace Account Deletion notification support.

== Upgrade Notice ==

= 3.12.0 =
Sold items now leave your shop automatically instead of waiting for a manual full sync. Removed products go to the Trash, and there is a setting to turn it off.

= 3.11.1 =
Important for anyone on the free tier. Going over the product limit was still stopping all syncing, not just new imports — 3.7.12 did not actually fix this.

= 3.11.0 =
Groundwork only — nothing changes unless an alternate connection address is configured for your site.

= 3.10.0 =
Adds an Image Cleanup page that restores your own product photographs and removes the duplicates created before 3.9.5.

= 3.9.5 =
Fixes your own product photographs being duplicated after a push to eBay. Recommended for anyone who pushes products rather than only importing them.

= 3.9.4 =
Recommended for anyone who cannot connect to eBay. The plugin now identifies itself and paces its calls, which stops hosting protection mistaking it for automated abuse.

= 3.9.3 =
The connection test now recognises when a blocked connection is our fault rather than yours, and says so.

= 3.9.2 =
The connection test now identifies the certificate your server receives from us, which settles who is interrupting a blocked connection. Useful only if you have had trouble connecting.

= 3.9.1 =
The connection test now checks where your server thinks our address is, which no previous version examined. Useful only if you have had trouble connecting.

= 3.9.0 =
The connection test now shows the complete reply on screen with a Copy button, and records every response header. Useful only if you have had trouble connecting.

= 3.8.9 =
The connection test now records the address identifying whoever is intercepting traffic. Useful only if you have had trouble connecting.

= 3.8.8 =
Corrects the connection test, which named your host as the culprit when it cannot actually tell which end is responsible.

= 3.8.7 =
The connection test can now tell whether a block is aimed at us specifically or is stopping all outgoing traffic. Useful only if you have had trouble connecting.

= 3.8.6 =
Improves the connection test so it can tell a blocked network apart from a filter singling out the connection requests. Useful only if you have had trouble connecting.

= 3.8.5 =
Cosmetic: the page tabs now match the left-hand menu, and Listings and Stock Review have tabs of their own.

= 3.8.4 =
Fixes Stock Review appearing to do nothing for listings that ended without selling. Recommended if you have used that screen.

= 3.8.3 =
Adds a Reset connection button for sites stuck part way through connecting, and makes disconnecting clear the handshake so reconnecting actually starts fresh.

= 3.8.2 =
Adds a "Test connection" button and makes failed connections explain themselves instead of showing a red dot. Worth having if you have ever had trouble connecting to eBay.

= 3.8.1 =
Fixes stores that could never finish connecting to eBay because their host answered the plugin with a security challenge page. Only affects stores seeing that error; everyone else is unchanged.

= 3.8.0 =
Adds a Stock Review page that finds products whose eBay listing has ended but which still show stock, and settles them from eBay. Run it once after upgrading to correct anything sold before 3.7.11.

= 3.7.12 =
Completes the stock fix from 3.7.11 by covering the hourly ended-listing check as well. Also fixes the free product limit stopping the sync outright instead of only holding back new imports.

= 3.7.11 =
Important if you sell single-quantity items. Fixes WooCommerce stock not reducing when an eBay sale ends the listing, which risks overselling. Items already sold will still show in stock and need correcting by hand.

= 3.7.10 =
Diagnostic only. If connecting an eBay account fails because a web page is returned instead of data, the log now names the server that produced the page.

= 3.7.9 =
Important for busy stores. Fixes syncing stopping permanently when a 48 hour period holds more changes than eBay will return at once, which left some stores days behind with no new listings arriving.

= 3.7.8 =
Fixes new eBay listings being merged into an existing product that happens to share a barcode, instead of being added. Affects stores whose WooCommerce SKUs come from barcodes, including anyone mapping the eBay SKU to Bin Location. Run Fetch Inventory once after updating.

= 3.7.7 =
Makes a failed eBay connection diagnosable: the log no longer reports success and failure together, names a request that was intercepted before reaching us, and keeps each entry to a single line.

= 3.7.6 =
Critical. Fixes a fatal error that could take a site down while connecting an eBay account, leaving FTP as the only way to recover. Affects 3.7.0 to 3.7.5.

= 3.7.5 =
Adds an Active or Ended badge to the eBay panel on each product, so you can see at a glance whether a listing is still live.

= 3.7.4 =
Fixes a second copy of the plugin taking the whole site down, including the WordPress admin, which previously required FTP access to recover from.

= 3.7.3 =
Recommended if you sell variable products. Fixes variation stock not reducing on eBay when it sells in WooCommerce, which risks overselling.

= 3.7.2 =
Recommended if you use "Auto-relist ended listings" with variable products. Relisting one would have recreated it on eBay with every variation discarded, reported as a success.

= 3.7.1 =
Important for variable products. Fixes "Overwrite Price" being ignored for variations, and variation changes never reaching eBay because the wrong eBay call was used. Push your variable products again after updating.

= 3.7.0 =
eBay tokens no longer pass through the browser when connecting, so they stop being recorded in browser history and server access logs. Existing connections keep working and need no action.

= 3.6.2 =
Important. Fixes syncing stopping completely and staying stopped after a large batch of eBay changes, which left some stores days behind with no new listings arriving. Also fixes intermittent "No valid eBay token" errors. Syncing resumes by itself after updating.

= 3.6.1 =
Fixes new eBay listings being swallowed by an existing product that happens to share a SKU, instead of being imported. Affects stores whose WooCommerce SKUs come from a UPC or EAN. Run Fetch Inventory once after updating.

= 3.6.0 =
Fixes new eBay listings never being imported when category filtering is on, variations duplicating on every sync of a variable product, and products being given photographs from unrelated listings. Run Fetch Inventory once after updating to collect anything that was missed.

= 3.5.6 =
Coin listings in sub-categories such as "Coins: US > Mint Sets" are now recognised as coins. Previously only five top-level categories were, so grading descriptors and coin item specifics were skipped unless you set Item Type to Coins on every product by hand.

= 3.5.5 =
Required if you turned on "Overwrite Images" in 3.5.4 and nothing happened. The setting worked, but existing products were skipped before it was consulted. Update, switch it on, and run an import from eBay.

= 3.5.4 =
Makes the "Overwrite Images" setting work — it previously did nothing, so a product that already had a main image kept it whatever eBay showed. Turn it on if a product is displaying a photo that is not on its eBay listing, then re-sync that product.

= 3.5.3 =
Fixes listings added during a connection outage never being imported, WooCommerce stock dropping by two for every one sold on eBay, variable products being duplicated when they sell, and scheduled syncs being skipped without reporting it. Existing duplicate products are not removed automatically — the duplicate is the one whose SKU ends in the eBay item number.

= 3.5.2 =
Fixes GTC listings being wrongly shown as Ended (relisting one would have created a duplicate), unblocks coin listings held up by the Circulated/Uncirculated requirement, adds bulk listing-format changes, and fixes products showing only one photo. Existing products repair themselves in the background, so no sync or re-fetch is needed.

= 3.5.0 =
Fixes deleted products reappearing after the item sold on eBay. Deleting a product left no record of the deletion, and the hourly sync treats a sale as a change — so a sold item was reported by eBay at exactly the moment you deleted it, and imported straight back. Both causes are fixed. Note that emptying the Trash removes the record of a deletion.

= 3.4.3 =
Minor follow-up to 3.4.2. Extends the time you have to complete an eBay connection from 15 to 30 minutes — described in the 3.4.2 notes but missing from that build. If 3.4.2 already reconnected you successfully, nothing here affects you.

= 3.4.2 =
Update immediately if you cannot connect or reconnect your eBay account. Connecting failed with "Invalid callback data or state" on every version from 3.1.3 onwards, with no workaround. Fixed — connect from Settings as normal after updating.

= 3.4.1 =
Important if you end listings. Previously a product whose eBay listing had ended could not be listed again — every push failed with "You are not allowed to revise ended listings" and there was no way to recover. Pushing now creates a fresh listing automatically, which also makes it possible to switch between Auction and Fixed Price.

= 3.4.0 =
Adds an "Imported Image Size" setting. If disk space matters, set it to 800px before importing images — eBay serves that size directly, so files are around half to a third the size with no visible difference on a product page. Existing images are unaffected.

= 3.3.5 =
Includes the 3.3.4 image fix, plus: switching "Disable background image localization" back off now actually restarts downloading. Previously the background task had been left unscheduled, so re-enabling the setting did nothing.

= 3.3.4 =
Important if you use "Disable background image localization" (keep eBay-hosted URLs) — images never displayed at all in that mode, because the eBay address was being stored in a field WordPress treats as a file path inside your uploads folder. Existing products are corrected automatically on update, with no re-import needed.

= 3.3.3 =
No action needed after updating. Products left showing an eBay-hosted image because a download failed are now detected and repaired automatically on the next sync — previously nothing could fix them, including a full re-fetch. Also makes "Re-sync images only" actually re-download.

= 3.3.2 =
Fixes the Operations buttons on the Import screen. "Prune Inventory" was starting a full import instead of a prune, "Process Queue" could not reach the scheduled tasks that actually stall, and several buttons reported success even when the action had been declined.

= 3.3.1 =
Adds product attributes as eBay item specifics on push — listings previously went up with almost none, hurting search placement and causing rejections in categories with required aspects. Required aspects are now checked before pushing and named if missing. Adds optional image hosting on eBay for sites eBay cannot reach. Also fixes background tasks stalling between scan batches, and never running at all on hosts with WP-Cron disabled.

= 3.2.1 =
Install this even if you already updated to 3.2.0. A small number of sites may have downloaded an incomplete 3.2.0 during a brief window, missing the product-duplication fix and the Settings layout fix, and would never have been offered a correction because the version number was the same. No other changes.

= 3.2.0 =
Data-safety and maintenance release. IMPORTANT: fixes product duplication copying the eBay listing link — duplicated products appeared already listed and pointed at the original's live eBay listing, so pressing Update or End Listing on a copy would have changed or closed the original's real listing. Products duplicated before this update are detected and blocked, with a new "Unlink from eBay" button to clear them. Variations removed from an eBay listing are now retired rather than deleted, so a bad eBay response can no longer destroy them. Fixes eBay order values being misread as $1.00 in some cases, order import missing anything past the first 100 per day, and the Listings page showing data frozen at install time. Also removes ~760 lines of dead code and hardens the activity log against public access on nginx.

= 3.1.4 =
Performance and reliability release, recommended for all stores and important for large ones. Adds a database index and removes several hot spots that made large syncs slow: a ~300KB option being autoloaded on every page request, duplicated media library records, and tens of thousands of individual log writes per import. eBay requests now retry transient network failures instead of failing an entire sync, and rate-limit responses are handled properly. Also fixes uninstall, which previously left all plugin data behind — including your stored eBay tokens.

= 3.1.3 =
Security and data-loss release — update immediately. Fixes an unauthenticated path that allowed the site's eBay credentials to be replaced, removes a hardcoded key that let forged eBay account-deletion requests erase customer data from orders, and stops imports from permanently ending live eBay listings. Also prevents a failed API call mid-scan from trashing large parts of your catalogue, stops image downloads being silently abandoned after a single timeout, and fixes relisting, which never worked. If eBay deletion notifications stop being accepted after updating, reconnect your eBay account from Settings.

= 1.7.2 =
Critical fix for sellers with multi-variant products: image imports were silently timing out for products with many variant photos. Images are now downloaded in safe chunks with automatic retry on failure. Also adds "Sync Specific Items" and "Re-sync Images Only" tools for fine-grained control over what gets updated. Recommended for all users, especially international sellers.

= 1.7.0 =
Major UX rework for Push to eBay! The old "Grading & Condition" and "TCGiant Sync" tabs are merged into a single "eBay Listing" tab with a guided 4-step wizard. New features include category auto-suggestion from your product title, visual card selectors, a pre-push readiness checklist, and smart validation that catches errors before they hit eBay. Plus performance improvements with smarter AJAX polling and transient caching.

= 1.6.3 =
New "Push to eBay" column on the WooCommerce Products list! See eBay category, item ID, push status, and errors for every product at a glance. Push or update any product to eBay with one click directly from the products table — no need to open each product individually.

= 1.6.2 =
Critical fix for coin and TCG sellers: Graded coins and several TCG graders were sending text strings instead of eBay's required numeric conditionDescriptorValueIds, causing export failures. All descriptor IDs are now verified against the eBay Metadata API. Also adds category browser name persistence — you'll now see the selected category name alongside the ID.

= 1.6.1 =
Major performance improvement for large eBay stores. Full syncs that previously took 2+ days for 13,000+ item stores now complete in 2-4 hours. Variation items are processed inline instead of requiring individual API calls, and image downloads no longer block the import queue. Recommended for all users with 1,000+ eBay listings.

= 1.4.8 =
Fixes the "No <Item.Location> exists" error that blocked all Push to eBay exports. Adds new Item Location and Postal Code fields to the export settings. After updating, go to TCGiant Sync → Settings → Push to eBay Settings and fill in your city/state and ZIP code.

= 1.4.7 =
Adds a visual "BIN" field to the WooCommerce Inventory tab for sellers routing their eBay Custom SKUs to the Bin Location custom field.

= 1.4.6 =
New import setting: "eBay SKU Maps To" lets you route eBay SKU to a Bin Location custom field instead of WooCommerce SKU — great for sellers who use eBay SKU for warehouse shelf codes. Also adds Free Shipping setup guidance for the baked-shipping workflow.

= 1.4.5 =
Critical fix for non-US marketplaces: weight and dimensions were being double-converted (metric treated as imperial). Also fixes missing Item Specifics and product images during scheduled delta syncs.

= 1.4.4 =
New feature: "Add eBay Shipping Cost to Product Price" setting. Enable it in Import Settings to automatically combine eBay item price + flat-rate shipping into a single WooCommerce price on import.

= 1.4.3 =
Critical fix for stores using category filters: imports were scanning all pages but matching zero items because eBay's API was not returning category data. This release also adds a contextual settings summary bar on the Import page and fixes the dashboard activity log width.

= 1.3.1 =
Security fix: the webhook signing key used for Marketplace Account Deletion notifications is now unique per installation. We recommend reconnecting to eBay via Settings to activate the new per-site key.

= 1.3.0 =
New feature: product weight and package dimensions (length × width × height) are now automatically imported from eBay and saved to your WooCommerce products. Values auto-convert to match your store's units. A new "Overwrite Weight & Dimensions" data mapping toggle controls re-import behavior.

= 1.2.3 =
Critical fix for large eBay stores (6,000+ listings): imports now survive API rate limits and resume from where they left off instead of restarting from scratch. Also fixes Product Attributes not saving after import.

= 1.1.0 =
Major reliability improvement for eBay stores with duplicate SKUs. The importer now gracefully detects duplicate SKUs and auto-appends eBay Item IDs to ensure all products are successfully imported without overwriting each other. Also includes UI improvements for manual sync feedback and a fully responsive Activity Log across all views.

= 1.0.3 =
Adds per-order "Sync to eBay" panel to the dashboard. Sold a card on WooCommerce? Click Push to eBay on that order to instantly update eBay stock — no queue, no cron. Also fixes a crash when stock hits 0 (now correctly ends the eBay listing instead of sending an invalid qty=0 API call).

= 1.0.2 =
Major feature release: Push to eBay exporter module. Create and update eBay listings directly from WooCommerce — individually or in bulk. Requires eBay Business Policies to be enabled on your seller account.

= 1.0.1 =
Allows strict WooCommerce-first category sync mapping.

= 1.0.0 =
Initial release of TCGiant Sync.
