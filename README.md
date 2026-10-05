# Bluebarry Product Recommendation Quiz Builder

This extension for Magento 2 integrates your store with [Bluebarry](https://bluebarry.ai), enabling the use of the advisor and conversion tracking for improved customer engagement and analytics.

## Features
- Injects Bluebarry advisor script into your storefront
- Records every paid order from a shopper who used bluebarry (quiz, search, recommendations, product chat, popups) and identifies the buyer's email, from the background so checkout never waits
- Connects each Magento website to bluebarry with its Tenant ID and API key, and shows the connection in the admin and in bluebarry
- Keeps bluebarry's copy of your catalog current without a product feed: products, variants, attributes, categories, prices, sale prices, images and stock
- Adds to the cart from a quiz result, the search widget and recommendation blocks, and updates the mini-cart (Luma and Hyvä)
- Turns on bluebarry search and its results page from bluebarry (Search > Launch), without settings in Magento
- Shows the product check button, product chat and recommendation blocks on product and cart pages when you switch them on in bluebarry, without editing your theme, and adds widgets to place them and your quizzes anywhere else
- Sends your orders (as they are paid, refunded or cancelled, and the last 12 months on request) and checkouts that gave an email, for best sellers, loyalty points, purchase segments and the order placed and abandoned checkout emails
- Tells bluebarry what shoppers look at (page views, product views, adds to the cart), with the shopper's consent under Magento's cookie restriction mode, for recently viewed, personalized order, behaviour segments, popup rules and product chat

## Installation

### Composer (Recommended)
1. Add the module to your `composer.json` or install via:
   ```bash
   composer require bluebarry/magento2-module
   ```
2. Enable the module:
   ```bash
   php bin/magento module:enable Bluebarry_Bluebarry
   php bin/magento setup:upgrade
   ```
3. Install cronjobs (if not already done):
   ```bash
   php bin/magento cron:install
   ```

### Manual
1. Copy the contents of this repository to `app/code/Bluebarry/Bluebarry`.
2. Run:
   ```bash
   php bin/magento module:enable Bluebarry_Bluebarry
   php bin/magento setup:upgrade
   ```
3. Install cronjobs (if not already done):
   ```bash
   php bin/magento cron:install
   ```

### Zero-downtime deployments
If you deploy with release directories and a symlink switch (Deployer, Hypernode Deploy, Adobe Commerce
Cloud, or similar), the previous release's cron jobs, queue consumers and PHP-FPM workers keep running for
a while after the switch. They share the Magento cache with the new release and fill it with configuration built from
the previous release's code. On a first install, that configuration doesn't know about this module, so
orders aren't tracked until the cache is rebuilt.

Once all of the previous release's PHP processes have stopped (cron jobs, queue consumers, and PHP-FPM
workers finishing their last requests), run:
```bash
php bin/magento cache:flush
```
Use `cache:flush`, not `cache:clean`: the previous release's entries aren't reliably removed by a tag-based clean. Start the new release's queue consumers after this flush, or restart them afterwards: a consumer started earlier may not find this module's queue configuration. Quiz orders placed between the release switch and this flush may not be tracked.

### Uninstall
```bash
php bin/magento module:uninstall Bluebarry_Bluebarry --remove-data
```
With `--remove-data`, Magento runs the module's own uninstall step, which tells bluebarry each website is gone and removes the module's tables. Without it, bluebarry notices after a week without a heartbeat. Clearing the Tenant ID or API key of a website in the settings also disconnects it right away.

## Configuration
1. Go to **Stores > Configuration > Bluebarry > General** in the Magento Admin.
2. Enter your **Tenant ID** (find it in your Bluebarry account integrations page).
3. Enter an **API Key**: create one in bluebarry under Integrations, Developer area, API keys. It is stored encrypted and connects the website to bluebarry. With several websites, switch the scope to set the Tenant ID and API key per website.
4. Save. Magento shows whether each website is connected, and **Connection** shows the last check. The module reports in daily from Magento's cron, so bluebarry shows the store as connected under Integrations.
5. Once a website is connected, the module sends your catalog to bluebarry: all of it right away, then every product that changes (saves, mass actions, imports, stock taken by orders), and all of it again every night for what changes without a save, such as sale prices that start or end. **Catalog** shows how many products are still waiting. It runs from Magento's cron in its own group (`bluebarry`), so cron must be installed; `php bin/magento bluebarry:catalog:sync --all` sends the whole catalog on demand. Orders and checkouts go from a group of their own (`bluebarry_orders`), every minute.
6. Search is switched on in bluebarry, under Search > Launch: the search box of every connected website then uses that configuration, and with **Full results page** on, bluebarry's results replace Magento's catalog search results page. The module reads these settings from bluebarry whenever bluebarry tells it they changed, through `bluebarry/command` (requests signed with the website's API key), and every hour, and refreshes the pages in the full page cache (and Varnish) that print them.
7. What bluebarry shows on product and cart pages is switched on in bluebarry too, under Integrations > Magento > On your store: the product check button and product chat under the add to cart button, a recommendation block below the product's details and one under the cart. The module keeps these settings like the search settings and prints the elements from them, so nothing is added to a shopper's page load. To place an element anywhere else, insert one of the module's widgets into a page or block, or add one under **Content > Widgets**: bluebarry quiz button, quiz popup, product check button, recommendations and product chat.
8. (Optional) Enable debug logging for conversion requests. The requests and API responses are written to `var/log/bluebarry.log`; errors also appear in `var/log/system.log`.

### What is synced
- Every simple, virtual, downloadable and bundle product, identified by its Magento product id. A configurable product's children are synced as variants grouped under it (its name, address and categories); configurable and grouped products themselves are not products in bluebarry.
- Names, addresses, images and prices as the website's default store view shows them to guests, in its display currency: the price shoppers pay (special prices and catalog price rules included) and the regular price while it is higher.
- Stock status and, while Magento manages the stock without backorders, the quantity that can still be sold.
- Attributes that are shown on the product page or filterable in the layered navigation, as `attr_<code>`, and the product's categories.
- Websites that share a Tenant ID send one catalog: the default website's, or else the first one's.

## Usage
- The Bluebarry advisor widget will appear on your storefront if Tenant ID is set.
- Conversion events are tracked automatically after checkout.

## Support
For support, contact [Bluebarry](https://bluebarry.ai) or your integration provider. 