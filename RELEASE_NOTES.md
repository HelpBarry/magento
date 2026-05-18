# Bluebarry Magento 2 Module 1.0.2 Release Notes

Release date: 2026-05-18

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
