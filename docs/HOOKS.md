# Hooks Reference

All actions and filters registered or fired by Alynt Customer Groups.

---

## Custom Cron Actions

These are WordPress scheduled events registered by the plugin. Third-party code may also hook into them.

---

### `wccg_cleanup_cron`

Fires once daily (scheduled at 2 AM server time) to run database maintenance tasks.

**Parameters:** None

**Tasks performed:**
- Removes orphaned user-group assignments for deleted users
- Removes orphaned rule-product entries for deleted products
- Removes orphaned rule-category entries for deleted terms
- Deletes error log entries older than 30 days (non-critical) or 90 days (critical)

**Example:**
```php
add_action( 'wccg_cleanup_cron', function() {
    // Run additional cleanup alongside the plugin's built-in tasks.
} );
```

---

### `wccg_check_expired_rules`

Fires every 5 minutes to auto-deactivate pricing rules whose `end_date` has passed and clear WooCommerce price caches when rules change state.

**Parameters:** None

**Example:**
```php
add_action( 'wccg_check_expired_rules', function() {
    // Run additional logic after the expiration check.
} );
```

---

## WooCommerce Filter Hooks

These filters are used by the plugin to modify WooCommerce price output. Other plugins hooking into the same filters at different priorities may conflict.

---

### `woocommerce_get_price_html`

Used to apply group pricing display to simple product prices (strikethrough original, discounted price, group label).

**Parameters:**
- `$price_html` (string) — Existing formatted price HTML
- `$product` (WC_Product) — Product being rendered

**Return:** `string` Modified price HTML

**Priority:** Default (10)

---

### `woocommerce_variable_price_html`

Used to apply group pricing display to variable product price ranges.

**Parameters:**
- `$price_html` (string) — Existing formatted price HTML
- `$product` (WC_Product) — Variable product being rendered

**Return:** `string` Modified price HTML

**Priority:** Default (10)

---

### `woocommerce_available_variation`

Used to inject discounted prices into variation data returned via AJAX when a customer selects a variation.

**Parameters:**
- `$variation_data` (array) — Variation data prepared for JavaScript
- `$product` (WC_Product) — Parent variable product
- `$variation` (WC_Product) — Selected variation product

**Return:** `array` Modified variation data

**Priority:** Default (10)

---

### `woocommerce_variation_price_html`

Used to apply group pricing display to individual variation price HTML.

**Parameters:**
- `$price_html` (string) — Existing formatted price HTML
- `$variation` (WC_Product) — Variation being rendered

**Return:** `string` Modified price HTML

**Priority:** Default (10)

---

### `woocommerce_cart_item_price`

Used to show strikethrough original and discounted price in the cart item price column.

**Parameters:**
- `$price_html` (string) — Existing formatted unit price HTML
- `$cart_item` (array) — WooCommerce cart item data
- `$cart_item_key` (string) — Cart item key

**Return:** `string` Modified unit price HTML

**Priority:** Default (10)

---

### `woocommerce_cart_item_subtotal`

Used to show strikethrough original and discounted subtotal in the cart item subtotal column.

**Parameters:**
- `$subtotal_html` (string) — Existing formatted subtotal HTML
- `$cart_item` (array) — WooCommerce cart item data
- `$cart_item_key` (string) — Cart item key

**Return:** `string` Modified subtotal HTML

**Priority:** Default (10)

---

### `cron_schedules`

Registers the `wccg_five_minutes` interval (300 seconds) used by the expiration check cron.

**Parameters:**
- `$schedules` (array) — Existing cron schedule definitions

**Return:** `array` Modified schedule definitions

---

## WooCommerce Action Hooks

---

### `woocommerce_before_calculate_totals`

Used to apply group discounts to cart item prices before WooCommerce calculates order totals.

**Parameters:**
- `$cart` (WC_Cart) — The current WooCommerce cart object

**Priority:** Default (10)

---

### `woocommerce_delete_product_transients`

Fires after WooCommerce product transients are cleared so dependent caches can refresh.

**Parameters:** None

---

## Frontend Action Hooks

---

### `wp_body_open`

Used to render the sticky pricing banner near the opening of the page body.

**Parameters:** None

**Priority:** Default (10)

---

### `wp_footer`

Used as a fallback location for the sticky pricing banner when the active theme does not call `wp_body_open()`.

**Parameters:** None

**Priority:** `5`

---

### `wp_enqueue_scripts`

Used to enqueue the plugin's public-facing stylesheet.

**Parameters:** None

**Priority:** Default (10)

---

## Admin Action Hooks

---

### `admin_menu`

Used to register the WooCommerce > Customer Groups menu and its sub-pages (User Assignments, Pricing Rules).

**Parameters:** None

---

### `admin_enqueue_scripts`

Used to enqueue admin CSS and JavaScript on the plugin's admin pages.

**Parameters:**
- `$hook_suffix` (string) — Current admin page hook suffix

---

### `manage_product_posts_custom_column`

Used to render the "Group Pricing" column value on the Products list table.

**Parameters:**
- `$column` (string) — Current column name
- `$post_id` (int) — Product post ID

---

### `admin_notices`

Used to render dependency requirement notices in wp-admin when PHP, WordPress, or WooCommerce requirements are not met.

**Parameters:** None

---

## AJAX Endpoints

All endpoints require `manage_woocommerce` capability and verify `wccg_pricing_rules_ajax` nonce. These are admin-only (`wp_ajax_*`) — not available to unauthenticated users.

| Action | Handler | Description |
|--------|---------|-------------|
| `wccg_toggle_pricing_rule` | `ajax_toggle_pricing_rule()` | Enable or disable a single pricing rule |
| `wccg_delete_all_pricing_rules` | `ajax_delete_all_pricing_rules()` | Delete all pricing rules and their associations |
| `wccg_bulk_toggle_pricing_rules` | `ajax_bulk_toggle_pricing_rules()` | Enable or disable all pricing rules at once |
| `wccg_reorder_pricing_rules` | `ajax_reorder_pricing_rules()` | Update `sort_order` for a set of rules |
| `wccg_update_rule_schedule` | `ajax_update_rule_schedule()` | Update `start_date` / `end_date` for a rule |
| `wccg_update_pricing_rule` | `ajax_update_pricing_rule()` | Update rule fields via the edit modal |
| `wccg_get_rule_data` | `ajax_get_rule_data()` | Fetch rule data for the edit modal |

**Nonce:** `wccg_pricing_rules_ajax` (passed as `nonce` in the POST body)
