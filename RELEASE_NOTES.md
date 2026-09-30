# Bluebarry Magento 2 Module 2.0.0 Release Notes

Release date: 2026-09-30

2.0.0:
Stability: Stable Build
Description:
Release 2.0.0 (2026-09-30)

Improvements
- Orders in bluebarry: each order is sent once it is paid (a new purchase when placed within 14 days) and again when it is refunded or cancelled; bluebarry's Orders page imports the last 12 months, per website, in batches. A checkout where the shopper gave an email (or is signed in) is sent a minute later for the abandoned checkout flow, and again once it became an order. All from the cron's `bluebarry_orders` group, never while a shopper waits; `bin/magento bluebarry:orders:sync [--import]` runs it now. Orders and conversions are keyed by the order number
- Page tracking for bluebarry's SDK: each page says what it is (product and the variant it opens with, category, search, cart, checkout) and gives product chat its product or category. Only what the address decides, so it is right from the full page cache; the cart comes from the shopper's own customer data. Every add to the cart is noted in a short-lived cookie that the SDK reports. With Magento's cookie restriction mode on, nothing is tracked or noted until the shopper allowed cookies
- Search from bluebarry: switching search on in bluebarry (Search > Launch) points every connected website's search box at that configuration, and **Full results page** makes bluebarry's results replace Magento's catalog search results page (Magento's results stay for visitors without JavaScript). The settings are read from bluebarry every 10 minutes and right away when bluebarry asks through `bluebarry/command` (signed with the website's API key); the pages that print them leave the full page cache and Varnish by their tag
- Add to cart from bluebarry: the quiz, the search widget and recommendation blocks add through `bluebarry/cart/add` (bluebarry's SDK calls it), which adds each line the way the product page would: a configurable product's variant with its options, a bundle with its default selections. It needs the form key, reports sold-out and missing lines, and the mini-cart updates on Luma and Hyvä
- Catalog sync: once a website is connected, its products, variants, attributes, categories, prices, sale prices, images and stock go to bluebarry, so no product feed is needed. Changes are queued as they happen (one insert, including imports and mass actions) and sent by a cron job in its own group; the whole catalog is sent on connecting and every night. **Catalog** on the settings page shows what is still waiting, and `bin/magento bluebarry:catalog:sync [--all]` sends it on demand
- **API Key** setting (encrypted, per website) that connects the website to bluebarry. Saving the settings checks the connection and shows the result; **Connection** shows the last check per website. The module reports in daily from Magento's cron and after an upgrade, so bluebarry shows the store as connected, and `module:uninstall --remove-data` (or clearing a website's credentials) tells bluebarry the store is gone
- Every paid order from a shopper who used bluebarry is recorded, not only quiz orders: search, recommendations, product chat and popups attribute through the visitor's `bb_uid` cookie, and the buyer's email is identified on every recorded order
- Conversions are sent from the background with retries (after 1, 4, 9 and 16 minutes), and a cron job in the `bluebarry_orders` group sends what no queue consumer picked up. It stops starting new deliveries after 40 seconds, leaving the rest due for the next run. `bin/magento bluebarry:conversions:send-due` runs that job on demand
- Installation notes for zero-downtime deployments: flush the cache once the previous release's PHP processes (cron jobs, queue consumers, PHP-FPM workers) have stopped, or orders from a first install aren't tracked until the next cache rebuild
- An order counts once it's paid in full (processing or complete, with nothing left due); bank transfer, check and cash on delivery orders count once they're invoiced, also when they were shipped first. With Magento's cookie restriction mode on, an order is only linked when the shopper allowed cookies
- Conversion amounts follow the same definition as bluebarry's other integrations: line amounts after discounts, and a grand total that includes tax and shipping. Items are named by their catalog product id (the variant for a configurable product)

Bug Fixes
- Dynamic bundle catalog prices match Magento's guest storefront minimum, including fixed selection quantities, available stock, child tier prices, parent discounts, currency conversion and each selection's tax and rounding. Percentage discounts follow Magento 2.4.7's rounding and preserve the special-price fallback when Magento ignores a 100% parent tier
- Adding a bundle to the cart includes the sole available selection of a required dropdown or radio option, even when it is not marked as the default
- Simple products in custom multi-source inventory stocks use the quantity available after reservations, so cancelling the last reserved unit restores their reported availability while Magento's inventory consumer catches up. Stock thresholds and backorder settings still apply
- Product deletions remain pending for a connected website whose API key is temporarily unreadable. Other websites continue syncing, and the affected website receives the deletions when its key is restored
- Queuing the whole catalog holds the same lock as catalog sync, preserving pending deletions and progress. A busy `bluebarry:catalog:sync --all` reports that the catalog is locked instead of overwriting a running sync's state
- Clearing or invalidating a guest checkout email withdraws its pending delivery. Delivery history is retained across withdrawals and interrupted sends so an accepted checkout still completes when an order is placed. Corrected and autofilled emails are retried
- An explicit product-chat opt-out is preserved while the shared advisor SDK continues loading for other enabled features
- Magento's Content Security Policy allows connections to the Bluebarry API and search hosts, preventing their bootstrap requests from being blocked
- Magento 2.4.6 through 2.4.9 compatibility covers console command return types, store-specific stock configuration and generated cart-cookie metadata factories
- Configurable products and bundles are reported once, at the price the shopper paid. Their child lines are no longer sent as extra items, and dynamic-price bundles no longer double the reported revenue
- Conversions are reported in the order's currency instead of always EUR
- "Write conversion requests to debug log" now works in production mode: the requests are written to `var/log/bluebarry.log`, which Magento's production mode does not suppress. Errors also still go to `system.log`
- Conversions are no longer lost on shops without RabbitMQ. The conversion queue now uses the shop's own queue connection (RabbitMQ when configured, otherwise the MySQL queue) instead of requiring RabbitMQ

1.0.3:
Stability: Stable Build
Description:
Release 1.0.3 (2026-09-24)

Bug Fixes
- Changed the AMQP conversion queue binding to a wildcard topic so first-install deployments do not fail during `setup:upgrade` when a live release refreshes the communication configuration

1.0.2:
Stability: Stable Build
Description:
Release 1.0.2 (2026-05-18)

Improvements
- Sends a best-effort Bluebarry identify request after post-checkout conversion processing to link the customer email to the quiz session

Bug Fixes
- Renamed the storefront session update POST parameter from `session_id` to `bb_session_id` to avoid WAF/OWASP session-fixation false positives while preserving existing session storage behavior
- Aligned Magento module setup version with the Composer package version

1.0.1:
Stability: Stable Build
Description:
Release 1.0.1 (2026-03-16)

Bug Fixes
- Fixed tax percentage being sent as string instead of number
- Removed unnecessary type cast

1.0.0:
Stability: Stable Build
Description:
[1.0.0] - 2025-05-12

- Initial release of Bluebarry for Magento 2 integration
- Advisor script injection on storefront
- Conversion tracking and reporting to Bluebarry API
- Admin configuration for Tenant ID and debug logging
