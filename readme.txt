=== Camille – AI Personal Shopper for WooCommerce ===
Contributors: instamark
Tags: personal shopper, virtual try-on, fashion, chatbot, ai
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI personal shopper for fashion stores: Camille advises your customers, builds outfits from your catalog and shows them on a virtual model.

== Description ==

Camille is a personal shopper for your WooCommerce fashion store. Your customers ask her for advice in a chat window, as they would ask a sales assistant: what to wear for an occasion, what goes with a product, which size or colour to choose. Camille answers with products from your catalog only, and can show them on a virtual model.

* A chat button on every page of your shop, and a "Try with Camille" button on product pages.
* You choose who sees Camille: nobody, only you (shop administrators), only logged-in customers, or every visitor.
* Your catalog is sent to Camille in the background, 50 products at a time, without slowing your shop down. Each colour of a product becomes a separate item, with its own photos and price.
* A "Camille button" block and a `[camille_button]` shortcode to place a button anywhere.
* Admin screens in English, Italian, French, German and Spanish.

Camille works with credits. A new shop gets 300 free credits; more credits are bought in packs on pinci.ai. There is no subscription.

= External service =

This plugin connects your shop to **Pinci**, the service that runs Camille, provided by Instamark S.r.l. (Italy). Without it the plugin does nothing. Data is exchanged as follows:

* **When you connect the shop** (WooCommerce > Camille), the plugin sends the email of your Pinci account, your site address, the site name and the admin language to `https://woo.pinci.ai`, which emails you a confirmation code.
* **When you sync the catalog**, the plugin sends the published products you chose for Camille (WooCommerce > Camille) to `https://woo.pinci.ai`: name, description, categories, tags, brand, attributes, prices, stock status, product address and photo addresses. These are public data of your shop. No order, customer or personal data is sent.
* **When you save the billing details** (company name, VAT number, address, SDI code or PEC, invoice email), the plugin sends them to `https://woo.pinci.ai`. Instamark S.r.l. uses them only to invoice your credit purchases.
* **When you customise the personal shopper**, the plugin sends the name, the gender and, if you upload one, the photo you chose to `https://woo.pinci.ai`.
* **Once an hour, and when you open the settings page**, the plugin asks `https://woo.pinci.ai` whether Camille is on, how many credits are left and the usage limits you set for your customers. Once, after connecting, it receives the key it uses to sign the account number of logged-in visitors.
* **On your shop pages**, for the visitors you chose, the browser loads the chat window from `https://pinci.ai` and reads Camille's name and photo from `https://api.pinci.ai`. What a visitor writes in the chat, the photos they choose to upload for the virtual try-on and the product they open Camille from are processed by Pinci to answer them. For a logged-in visitor the chat also receives the number of their account on your shop, signed by the plugin, so the usage limits you set follow them; shop managers are marked as such and have no limits. The plugin does not send visitors' names, emails or orders.

Pinci terms of use: https://pinci.ai/terms
Pinci privacy policy: https://pinci.ai/privacy

The plugin adds a suggested paragraph to your privacy policy guide (Settings > Privacy).

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New > Upload, and activate it.
3. Go to WooCommerce > Camille, enter the email of your Pinci account (or create one on pinci.ai) and type the code you receive.
4. Press "Sync the catalog". The import runs in the background; the page shows its progress and any product left out (for example a product without a photo or a price).
5. Camille is visible to shop administrators first: try her on your shop while logged in, then choose who else sees her.

Your shop must be served over HTTPS.

== Frequently Asked Questions ==

= Does Camille slow my shop down? =

No. The chat button is a small script loaded at the end of the page, after everything else. The chat itself loads only when a visitor opens it. The catalog sync runs in the background with WooCommerce's own task system.

= What happens if Pinci is not reachable? =

Your shop keeps working as usual. Camille's button stays hidden or the chat shows that it is not available, and a catalog sync in progress waits and continues later.

= Which products does Camille know? =

Published simple and variable products that are visible in the catalog and not protected by a password. Sync again after adding products or changing prices.

= Can I change the text of the buttons? =

The block and the shortcode take your own text: `[camille_button label="Ask our stylist" mode="chat"]`. Use `mode="tryon"` (default) on product pages to open Camille with that product.

= Does it work with page caching? =

Yes. With "All visitors" cached pages include the chat button. With "Only administrators" or "Only logged-in customers" the button is printed for logged-in users, whom caching plugins usually do not cache.

= I disconnected the shop. Are my credits lost? =

No. Credits and catalog stay on your Pinci account. Connect again with the same email to pick up where you left off.

== Changelog ==

= 0.3.0 =
* Customise the personal shopper: name, gender, a portrait from the gallery or your own photo.
* New visibility "Administrators and chosen customers": choose customers on their profile or with the bulk actions in Users.

= 0.2.0 =
* Usage limits for customers: chats per day for visitors without an account, try-ons for visitors on or off, try-ons per day for each logged-in customer. Shop managers have no limits.
* Logged-in customers are recognised by Camille with a signed account number (no name or email).
* Choose which products Camille suggests: categories, price range, single products (product page or bulk actions).
* The sync shows what Camille is doing, step by step, until she has studied every new item.

= 0.1.0 =
* First version: connection with an email code, catalog sync, visibility settings, chat button, product button, block and shortcode.
