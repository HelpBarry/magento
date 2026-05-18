# Changelog

All notable changes to this project will be documented in this file.

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
