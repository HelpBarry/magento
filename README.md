# Bluebarry Product Recommendation Quiz Builder

This extension for Magento 2 integrates your store with [Bluebarry](https://bluebarry.ai), enabling the use of the advisor and conversion tracking for improved customer engagement and analytics.

## Features
- Injects Bluebarry advisor script into your storefront
- Tracks conversions and sends order/session data to Bluebarry
- Admin configuration for Tenant ID and debug logging

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

## Configuration
1. Go to **Stores > Configuration > Bluebarry > General** in the Magento Admin.
2. Enter your **Tenant ID** (find it in your Bluebarry account integrations page).
3. (Optional) Enable debug logging for conversion requests.

## Usage
- The Bluebarry advisor widget will appear on your storefront if Tenant ID is set.
- Conversion events are tracked automatically after checkout.

## Support
For support, contact [Bluebarry](https://bluebarry.ai) or your integration provider. 