# Changelog

All notable changes to Alynt Customer Groups will be documented in this file.

## [1.1.0] - 2026-01-20

### Added
- Default group functionality for ungrouped customers
- Scheduling functionality for pricing rules with start/end dates
- Admin modal for editing pricing rules
- Auto-expiration cron task for expired pricing rules
- 5-minute cron interval for rule expiration checks

### Changed
- Enhanced variable product pricing functionality
- Updated database queries to support product variations
- Improved price display hooks for variable products

### Fixed
- Updated user edit link to new admin page slug

### Security
- Improved error handling and validation

## [1.0.0] - 2025-11-01

### Added
- Customer groups management: create, rename, and delete unlimited groups
- User Assignments screen: assign and unassign registered customers to groups in bulk
- CSV export for selected user-group assignments
- Product-level and category-level pricing rules (including ancestor category matching)
- Fixed-amount and percentage discount types
- Priority logic: product-specific rules override category rules; fixed discounts take precedence over percentage discounts of equal value
- Adjusted price display across catalog, product pages, cart, and checkout (strikethrough original, discounted price, group label)
- Variable product and product variation price adjustments
- Group Pricing column on the WooCommerce Products list table
- Sticky notification banner showing the customer's active pricing group
- AJAX-powered rule management: toggle, reorder, delete all, and bulk enable/disable
- Per-request price cache to minimise database lookups
- Rate limiting for price calculations (100/min) and group changes (10/5 min)
- Daily cleanup cron removing orphaned assignments, rule associations, and old log entries
- Database error logging with severity levels (error, critical)
- Dependency checks for PHP 7.4+, WordPress 5.8+, and WooCommerce 5.0+
