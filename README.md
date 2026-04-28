# Giodc Extra Fee per Products

A WooCommerce plugin that adds **quantity-based cart fees** for selected products or product categories.

---

## Features

- Define fee rules targeting specific **products** or **product categories**
- Fees scale with quantity using up to **36 configurable tiers** (step-function logic)
- Custom **cart fee label** shown to customers at checkout
- Rules can be **enabled or disabled** individually
- **WPML compatible** — works correctly on multilingual sites via the `wpml_object_id` filter
- Clean admin interface under **WooCommerce → Qty Fee Rules**

---

## How it works

Each fee rule defines:

1. **Rule type** — target individual products or a product category
2. **Objects** — which products or categories the rule applies to
3. **Tiers** — a quantity threshold and a fee amount per tier (up to 36 tiers)
4. **Fee label** — the text shown on the cart/checkout page

When a customer adds matching products to the cart, the plugin evaluates the total quantity against the tier thresholds and applies the corresponding fee automatically.

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+

---

## Installation

1. Upload the `giodc-extra-fee-per-products` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins** in WordPress admin
3. Navigate to **WooCommerce → Qty Fee Rules** to create your first rule

---

## File structure

```
giodc-extra-fee-per-products/
├── giodc-extra-fee-per-products.php   # Main plugin file
├── includes/
│   ├── class-giodc-fee-admin.php      # Admin UI & AJAX handlers
│   ├── class-giodc-fee-cart.php       # Cart fee calculation
│   ├── class-giodc-fee-rules.php      # Database CRUD
│   └── class-giodc-fee-wpml.php       # WPML compatibility
└── assets/
    ├── css/admin.css                  # Admin styles
    └── js/admin.js                    # Admin interface logic
```

---

## Database

The plugin creates a custom table `{prefix}giodc_fee_rules` on activation to store all fee rules and their tier configurations.

---

## License

GPLv2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
