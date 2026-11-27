=== Camille – AI Fashion Shopping Assistant & Virtual Try-On for WooCommerce ===
Contributors: instamarksrl
Tags: ai shopping assistant, virtual try-on, ai chatbot, fashion, product recommendations
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI fashion shopping assistant. Camille builds outfits from your catalog and shows them on your customer's own photo with virtual try-on.

== Description ==

Camille is an AI fashion shopping assistant for your WooCommerce store. She lives in a chat window on your shop and helps your customers the way a good sales assistant does in a boutique. She asks what they are looking for, suggests products from your catalog, puts together complete outfits and, with virtual try-on, shows how they look on the customer's own photo.

Camille only suggests what you sell. She works from your own catalog, follows your prices and stock, and never recommends sold-out products.

= For your customers =

* Advice in a chat. What to wear for an occasion, what goes with a product, which cuts and colours flatter them.
* Complete outfits built from your catalog, with a link to every product.
* Virtual try-on. The customer sends a photo of themselves and sees a single garment or a whole outfit on it.
* A "Try with Camille" button on product pages, to start from the product they are looking at.

= For you =

* Choose who sees Camille: nobody, only you (shop administrators), chosen customers, logged-in customers or every visitor. Start by trying her yourself.
* Choose which products she suggests, by category, price range or single product.
* Give her a name, a gender and a face, from a gallery of portraits or with your own photo.
* Set usage limits for visitors and customers, so credits go to real shoppers.
* Read the conversations and try-ons of your customers in WooCommerce > Camille and on each customer profile.
* A "Camille button" block and a `[camille_button]` shortcode to place a button anywhere.
* Admin screens in English, Italian, French, German and Spanish.

= Light on your shop =

The chat button is a small script loaded after the rest of the page, and the chat loads only when a visitor opens it. Your catalog reaches Camille in the background, 50 products at a time. Changes arrive within minutes, and a full sync runs every night. Each colour of a product becomes a separate item, with its own photos and price. After the first sync the product photos are copied in the background, product after product; the settings page shows how far the copy is.

= Pricing =

Camille works with credits. A new shop gets 300 free credits, and more credits are bought in packs on pinci.ai. There is no subscription.

= External service =

This plugin connects your shop to **Pinci**, the service that runs Camille, provided by Instamark S.r.l. (Italy). Without it the plugin does nothing. Data is exchanged as follows:

* **When you connect the shop** (WooCommerce > Camille), the plugin sends the email of your Pinci account, your site address, the site name and the admin language to `https://woo.pinci.ai`, which emails you a confirmation code.
* **When the catalog is synced** (when you press the button, every night, and a few minutes after you change a product), the plugin sends the published products you chose for Camille (WooCommerce > Camille) to `https://woo.pinci.ai`: name, description, categories, tags, brand, attributes, prices, stock status and product address. It also sends a copy of each product photo, read from your site's disk and reduced to at most 1600 pixels, once per photo (again only if the photo is replaced), in the background after the sync. Camille uses these copies to study the products and for the virtual try-on, so no firewall, CDN or bot protection of your hosting can block them. When the first copy is over, the plugin tells the service once, so Camille can email you that she is ready. A photo that cannot be read from disk is sent by its address on your site. These are public data of your shop. No order, customer or personal data is sent.
* **When you save the billing details** (company name, VAT number, address, SDI code or PEC, invoice email), the plugin sends them to `https://woo.pinci.ai`. Instamark S.r.l. uses them only to invoice your credit purchases.
* **When you customise the personal shopper**, the plugin sends the name, the gender and, if you upload one, the photo you chose to `https://woo.pinci.ai`.
* **When you open the Conversations or Virtual try-ons tab, or a customer profile**, the plugin reads the chats and try-ons of your customers from `https://woo.pinci.ai`. Pinci knows a customer only by the WordPress user number: the names and emails you see come from your own site and are never sent.
* **Once an hour, and when you open the settings page**, the plugin asks `https://woo.pinci.ai` whether Camille is on, how many credits are left, the usage limits you set for your customers and the name and photo of your personal shopper. It keeps a small copy of the photo in your uploads folder (`wp-content/uploads/camille-personal-shopper`), so your visitors' browsers do not contact Pinci to show the chat button. Once, after connecting, it receives the key it uses to sign the account number of logged-in visitors.
* **On your shop pages**, for the visitors you chose, the browser contacts Pinci only when the visitor opens the chat: the chat window is loaded from `https://pinci.ai`. (If a virtual try-on started in the chat is still running when the visitor moves to another page, the chat is loaded again in the background to finish it.) What a visitor writes in the chat, the photos they choose to upload for the virtual try-on and the product they open Camille from are processed by Pinci to answer them. For a logged-in visitor the chat also receives the number of their account on your shop, signed by the plugin, so the usage limits you set follow them; shop managers are marked as such and have no limits. The plugin does not send visitors' names, emails or orders.

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

Published simple and variable products that are visible in the catalog and not protected by a password. After the first sync, new, changed, sold-out, hidden and deleted products reach Camille within a few minutes, and a full sync runs by itself every night around 3 (shop time). Sold-out products stay in Camille's catalog but she does not suggest them.

= Can I change the text of the buttons? =

The block and the shortcode take your own text: `[camille_button label="Ask our stylist" mode="chat"]`. Use `mode="tryon"` (default) on product pages to open Camille with that product.

= Does it work with page caching? =

Yes. With "All visitors" cached pages include the chat button. With "Only administrators" or "Only logged-in customers" the button is printed for logged-in users, whom caching plugins usually do not cache.

= I disconnected the shop. Are my credits lost? =

No. Credits and catalog stay on your Pinci account. Connect again with the same email to pick up where you left off.

== Screenshots ==

1. Camille in the chat on a product page, suggesting an outfit from the shop's catalog.
2. A virtual try-on. The customer sees a whole outfit on their own photo (the person shown here is an AI-generated image).
3. Camille on a phone.
4. Status, credits and catalog sync in WooCommerce > Camille.
5. Choose the name, gender and portrait of your personal shopper.
6. Choose who sees Camille and set usage limits.
7. The chats and try-ons of your customers.

== Credits ==

Fonts shipped with the plugin: Manrope (The Manrope Project Authors) and Cormorant Garamond (The Cormorant Project Authors), both under the SIL Open Font License 1.1, see assets/fonts.

== Changelog ==

= 0.5.3 =
* The catalog sync is quick again on large shops: the products are sent at once, and their photos are copied afterwards in the background, four at a time.
* Camille studies each product once its photos are copied, so it is studied only once.
* The catalog panel shows how far the photo copy is, and you get an email when Camille is ready.

= 0.5.2 =
* Product photos are copied to Camille when the catalog is synced. Virtual try-ons no longer depend on your hosting letting outside servers download the photos.

= 0.5.1 =
* The name and photo of your personal shopper are copied to your site: visitors' browsers contact Pinci only when they open the chat.
* Tested with WordPress 7.1 and WooCommerce 11.3.

= 0.5.0 =
* Camille follows the shop by herself: products added, changed, sold out, hidden, drafted or deleted reach her about a minute later, without a new sync.
* A full catalog sync runs by itself every night around 3 (shop time) and catches the changes made outside WooCommerce's screens.
* Sold-out products are no longer suggested to customers; they come back when restocked.

= 0.4.1 =
* Language button in the header of WooCommerce > Camille: each shop manager can show the plugin in Italian, English, French, German or Spanish, whatever the language of WordPress.
* The plugin texts in Italian, German and Spanish now use the informal form, as the chat does.

= 0.4.0 =
* New Conversations and Virtual try-ons tabs in WooCommerce > Camille: the chats of your customers with the photos and try-ons, linked to the WordPress customer who made them.
* The customer profile shows the customer's chats and try-ons, and can block the try-ons of one customer.
* New look in Camille's style, with the settings split into sections: Status and catalog, Personalisation, Visibility, Usage limits, Billing.

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
