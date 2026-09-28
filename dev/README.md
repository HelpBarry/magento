# Testing the Bluebarry Magento module

Everything runs in Docker. You don't need PHP, Composer or Magento installed locally, only Docker and
Node.js (for Playwright).

```bash
dev/bin/setup          # build a production-mode Magento and install the module (first run ~15 min)
dev/bin/test-static    # syntax on PHP 8.1–8.5, XML vs Magento XSDs, Magento coding standard, PHPStan
dev/bin/test-unit      # PHPUnit (Test/Unit), against the working tree
dev/bin/test-e2e       # Playwright: quiz → checkout → queue consumer → Bluebarry API (mocked)
dev/bin/test-deploy-race   # zero-downtime first-install deploy (the 1.0.3 incident)
dev/bin/test-all       # all of the above for the current variant
dev/bin/test-matrix    # set up and test every variant in turn (about 20 min each)
```

After changing module code, run `dev/bin/deploy-module` to repackage and redeploy it. Then rerun the
tests.

Storefront: http://localhost:8080 · Admin: http://localhost:8080/admin (`admin` / `Admin12345!`) ·
Storefront behind the WAF: http://localhost:8081 · Requests the module sent: http://localhost:8099/__requests ·
RabbitMQ: http://localhost:15672 (`guest` / `guest`)

## How the setup matches production

Each point below is a difference between a merchant's live shop and a typical dev box, and each has
caused, or could cause, a production-only bug.

| Production reality | How this setup reproduces it |
|---|---|
| Merchants install a Composer package, not files copied into `app/code` | `scripts/package.sh` builds the zip. `deploy-module` installs it through Composer. |
| Shops run in **production mode**: compiled DI, deployed static content, and no XML schema validation | `deploy:mode:set production`, `setup:di:compile` and `setup:static-content:deploy` on every deploy. `test-static` validates the XML against the XSDs separately. |
| Queue consumers run in separate processes, sometimes on another server | Tests start `BluebarryConversionProcess` the way cron does, then check what reached the API. |
| RabbitMQ or MySQL queue, depending on the host | Variants `default` (RabbitMQ) and `mysql-queue`. |
| Redis sessions and cache | Redis for sessions, cache and full-page cache. |
| A WAF in front of the store (Cloud Armor, hosting WAFs) | OWASP CRS at paranoia level 2 on port 8081. `waf.spec.ts` covers the 1.0.2 incident. |
| Zero-downtime deploys, where the old release keeps running during and after `setup:upgrade` | `test-deploy-race` runs the new release's deploy while a copy of the old release keeps re-caching config into the shared Redis. It checks that the deploy succeeds (the 1.0.3 incident), then that a quiz order placed right after it is tracked once the documented post-deploy step (`cache:flush`) has run. `dev/bin/test-deploy-race dist/bluebarry-magento2-module-1.0.2.zip` reproduces the original failure. |
| The real Bluebarry API rejects payloads it can't bind | The mock at `data.bluebarry.ai` enforces the same request contract (unknown fields, GUID and string types) and answers 400 like the real API. |
| Real browsers, CSP, and cross-origin `postMessage` from the advisor iframe | Playwright serves the advisor stub *from* `https://advisor.bluebarry.ai` via request interception, so the origin check runs unmodified. CSP violations are collected. |
| Dutch shops, prices entered excl. tax, 21% VAT | Fixtures: NL 21% tax rule, a simple product and a configurable product. |

The module's API URL is hardcoded. Inside the stack, `data.bluebarry.ai` resolves to the mock
(`dev/mock-api`), which uses a certificate from a throwaway CA that only the PHP container trusts. The code
under test is exactly what ships, and no test traffic can reach real Bluebarry.

## Variants (the matrix)

`BB_ENV=<name>` selects `dev/env/<name>.env`. Each variant keeps its own Docker volumes, so you can switch
between them without reinstalling. Only one runs at a time, because they share ports.

| Variant | Magento | PHP | Queue |
|---|---|---|---|
| `default` | 2.4.8-p5 | 8.3 | RabbitMQ |
| `mysql-queue` | 2.4.8-p5 | 8.3 | MySQL (no RabbitMQ) |
| `legacy` | 2.4.6-p14 | 8.2 | RabbitMQ |
| `2.4.7` | 2.4.7-p9 | 8.3 | RabbitMQ |
| `latest` | 2.4.9 | 8.4 | RabbitMQ |

```bash
dev/bin/compose down                       # stop the current variant (keeps its data)
BB_ENV=mysql-queue dev/bin/setup
BB_ENV=mysql-queue dev/bin/test-e2e
```

CI (`.github/workflows/tests.yml`) runs `default` on every PR, and every variant plus the deploy-race
test on `main`, nightly and on demand.

## Useful commands

```bash
dev/bin/magento <command>                  # bin/magento in the container
dev/bin/shell                              # shell in the Magento root
dev/bin/shell -c 'tail -f var/log/debug.log'
dev/bin/compose logs -f mock-api           # every request the module sends, with contract errors
dev/bin/compose down -v                    # delete this variant completely
```

## What this can't cover

Third-party themes (Hyvä, headless), payment providers that create orders from webhooks, and hosting
quirks such as restricted consumer lists in `env.php`, egress firewalls and Adobe Commerce Cloud's
read-only filesystem still need a real staging shop before a release.

## Releasing

1. In each PR that changes the module, add a line under `## [Unreleased]` in `CHANGELOG.md`, grouped
   under `### Added`, `### Changed` or `### Fixed`.
2. Run **Actions → Release → Run workflow** on `main` and pick patch, minor or major. The workflow:
   - bumps `composer.json` and `etc/module.xml`
   - moves the Unreleased entries into a dated version section, and adds the Marketplace-style
     entry to `RELEASE_NOTES.md`
   - installs that exact version on a production-mode Magento and runs the static, unit and
     end-to-end tests
   - commits `Release x.y.z`, tags `vx.y.z` and publishes a GitHub release with the zip attached
3. Download the zip from the release and upload it to the Magento Marketplace. Paste that version's
   block from `RELEASE_NOTES.md` as the release notes.

Tick **Dry run** to build and test without committing or publishing; the zip is kept as a workflow
artifact. Dry runs work from any branch. `scripts/prepare_release.py` runs the same bump locally.
