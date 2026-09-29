# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]
### Added
- **API Key** setting (encrypted, per website) that connects the website to bluebarry. Saving the settings checks the connection and shows the result; **Connection** shows the last check per website. The module reports in daily from Magento's cron and after an upgrade, so bluebarry shows the store as connected, and `module:uninstall` tells bluebarry the store is gone.
- Every paid order from a shopper who used bluebarry is recorded, not only quiz orders: search, recommendations, product chat and popups attribute through the visitor's `bb_uid` cookie, and the buyer's email is identified on every recorded order.
- Conversions are sent from the background with retries (after 1, 4, 9 and 16 minutes), and a cron job sends what no queue consumer picked up. `bin/magento bluebarry:conversions:send-due` runs that job on demand.
- Installation notes for zero-downtime deployments: flush the cache once the previous release's PHP processes (cron jobs, queue consumers, PHP-FPM workers) have stopped, or orders from a first install aren't tracked until the next cache rebuild.
### Changed
- An order counts once it's paid in full (processing or complete, with nothing left due); bank transfer, check and cash on delivery orders count once they're invoiced, also when they were shipped first. With Magento's cookie restriction mode on, an order is only linked when the shopper allowed cookies.
- Conversion amounts follow the same definition as bluebarry's other integrations: line amounts after discounts, and a grand total that includes tax and shipping. Items are named by their catalog product id (the variant for a configurable product).
### Fixed
- Configurable products and bundles are reported once, at the price the shopper paid. Their child lines are no longer sent as extra items, and dynamic-price bundles no longer double the reported revenue.
- Conversions are reported in the order's currency instead of always EUR.
- "Write conversion requests to debug log" now works in production mode: the requests are written to `var/log/bluebarry.log`, which Magento's production mode does not suppress. Errors also still go to `system.log`.
- Conversions are no longer lost on shops without RabbitMQ. The conversion queue now uses the shop's own queue connection (RabbitMQ when configured, otherwise the MySQL queue) instead of requiring RabbitMQ.

## [1.0.3] - 2026-09-24
### Fixed
- Changed the AMQP conversion queue binding to a wildcard topic so first-install deployments do not fail during `setup:upgrade` when a live release refreshes the communication configuration.

## [1.0.2] - 2026-05-18
### Added
- Sends a best-effort Bluebarry identify request after post-checkout conversion processing to link the customer email to the quiz session.

### Fixed
- Renamed the storefront session update POST parameter from `session_id` to `bb_session_id` to avoid WAF/OWASP session-fixation false positives while preserving existing session storage behavior.
- Aligned the Magento module setup version with the Composer package version.

## [1.0.1] - 2026-03-16
### Fixed
- Fixed tax percentage being sent as string instead of number.
- Removed unnecessary type cast.

## [1.0.0] - 2025-05-12
### Added
- Initial release of Bluebarry Magento 2 integration
- Advisor script injection on storefront
- Conversion tracking and reporting to Bluebarry API
- Admin configuration for Tenant ID and debug logging
