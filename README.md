# Alynt Customer Groups

Alynt Customer Groups lets store owners create customer groups and assign targeted pricing rules to products and categories.

## Features

- Create and manage unlimited customer groups.
- Assign customers to groups in bulk.
- Configure product-level and category-level pricing rules.
- Support fixed discounts, percentage discounts, and scheduled pricing windows.
- Show adjusted prices across catalog, cart, and checkout views.
- Set a default group for ungrouped customers.
- Auto-expire scheduled pricing rules via background cron.

## Requirements

- WordPress 5.8 or later
- WooCommerce 5.0 or later
- PHP 7.4 or later

## Installation

1. Upload the plugin to `wp-content/plugins/alynt-customer-groups`.
2. Activate the plugin in WordPress.
3. Open `WooCommerce > Customer Groups` to configure groups and pricing rules.

## Usage

- Create customer groups from the Customer Groups admin screen.
- Assign users from the User Assignments screen.
- Create pricing rules from the Pricing Rules screen.
- Optionally set a default group for ungrouped customers.

## Configuration

### Customer Groups

Navigate to **WooCommerce > Customer Groups** to create and manage groups.

- **Add Group** — Enter a name and optional description. Groups are immediately available for user assignment and pricing rules.
- **Delete Group** — Removes the group and all its associated pricing rules. The default group cannot be deleted while it is active.
- **Set as Default** — Assigns a group to all customers who are not explicitly in any group. A custom label can be set for the frontend display.

### User Assignments

Navigate to **WooCommerce > Customer Groups > User Assignments** to assign registered customers to groups.

- Filter and search users, then use bulk actions to assign a group.
- A user can belong to only one group.

### Pricing Rules

Navigate to **WooCommerce > Customer Groups > Pricing Rules** to create discount rules.

- Rules can target specific products or product categories.
- Discount types: **Fixed** (currency amount) or **Percentage**.
- Rules can have an optional **start date** and **end date** for scheduled pricing windows. The cron task checks for expired rules every 5 minutes and deactivates them automatically.
- When a customer qualifies for both a product-specific rule and a category rule, the product-specific rule takes priority. When multiple category rules apply, the rule with the higher discount value wins (fixed preferred over percentage when values are equal).

### Default Group Settings

When a default group is active (`wccg_default_group_id` option), customers who are not assigned to any group receive that group's pricing. A custom display label (`wccg_default_group_custom_title`) can be set to override the group name shown on the frontend.

See [docs/SETTINGS.md](docs/SETTINGS.md) for the full options reference.

## FAQ

**Q: Can I assign a customer to multiple groups?**
A: No. Each customer can belong to only one group at a time. Use pricing rules on shared categories to cover multiple groups.

**Q: What happens if a product matches both a product-specific rule and a category rule?**
A: The product-specific rule always wins. If only category rules match, the rule with the highest discount value is applied (fixed discount is preferred over percentage when discount values are equal).

**Q: What happens when a scheduled rule expires?**
A: The background cron task (`wccg_check_expired_rules`) runs every 5 minutes. When it finds rules whose `end_date` has passed, it deactivates them and clears WooCommerce price caches so storefronts update immediately.

**Q: Will the plugin work without WooCommerce?**
A: No. WooCommerce 5.0 or later is required. The plugin checks for WooCommerce on activation and displays an admin notice if it is missing or inactive.

**Q: Does pricing apply to guest customers?**
A: Only if a default group is configured. Guest users (not logged in) receive default-group pricing when `wccg_default_group_id` is set to a valid group.

**Q: Are prices adjusted in the cart and checkout?**
A: Yes. Discounts are applied in catalog/product pages, the cart item price and subtotal columns, and carried through to checkout totals.

## Examples

- **Customer Groups screen** — Create a group such as `Wholesale`, optionally add a description, then mark it as the default group for unassigned customers.
- **User Assignments screen** — Filter users, select matching customers, choose a group, and bulk-assign them in one action.
- **Pricing Rules screen** — Create a fixed or percentage discount, target products or categories, and optionally add start/end dates for scheduled pricing.
- **Storefront result** — Eligible customers see the original price struck through, the discounted price, and the active group label on product and cart views.

## Developer Reference

- [docs/SETTINGS.md](docs/SETTINGS.md) — WordPress options reference
- [docs/HOOKS.md](docs/HOOKS.md) — Actions, filters, and AJAX endpoints

## Changelog Summary

See [CHANGELOG.md](CHANGELOG.md) for the full changelog.

- `1.1.0` adds default group support, scheduled pricing rules, pricing rule editing, and automatic expiration handling.
- `1.0.0` introduces customer groups, user assignments, product/category pricing rules, frontend price display updates, the sticky banner, and AJAX pricing rule management.

## License

Released under `GPL-2.0-or-later`. See `LICENSE` for the full license text.
