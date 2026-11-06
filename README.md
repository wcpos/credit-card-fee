# WooCommerce POS Credit Card Fee

Configurable always and automatic card fees for WooCommerce POS orders.

## Features

- An optional always fee added when a POS order is created
- A card fee that follows the selected payment gateway on the POS order-pay page
- Both percentages use discounted product line totals, excluding tax, shipping and other fees
- Configurable fee names, percentages, card gateways and shared tax status
- Session-based AJAX security and payment selection preserved across reloads

## Installation

1. Download the plugin
2. Upload to your WordPress `/wp-content/plugins/` directory
3. Activate the plugin through the WordPress admin

## Usage

1. An administrator configures the rules under **WooCommerce → Settings → Advanced → POS fees**.
2. New POS orders receive the always fee when its percentage is above zero.
3. Editing a POS order recalculates an existing always fee. If the cashier removes it in the POS app, later updates leave it off that order.
4. On `/wcpos-checkout/order-pay/{order-id}/`, selecting a configured gateway applies the card fee; selecting another removes it. The page reloads and restores the payment selection.
5. Configured gateways show a fee status when the card fee is present. The card fee is checked again before payment processing, even if AJAX did not run.

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- WooCommerce POS

## Configuration

In **WooCommerce → Settings → Advanced → POS fees**, set:

| Setting | Default |
| --- | --- |
| Always fee name | Service fee |
| Always fee (%) | Blank (off) |
| Card fee name | Card fee |
| Card fee (%) | 3 |
| Card fee gateways | Stripe Terminal for WooCommerce (`stripe_terminal_for_woocommerce`) |
| Fee tax status (both rules) | Not taxable |

Blank or zero disables a rule. Card gateways are selected from the installed WooCommerce payment gateways; select none to apply no card fee. Taxable fees use the standard tax class.

Changing settings does not bulk-update existing orders. Disabling the always rule leaves existing always fee lines unchanged. Fees from older plugin versions without rule metadata are not automatically modified or removed.

## Security

The plugin uses a custom session-based security token system instead of WordPress nonces, making it compatible with custom checkout pages where the user context may be modified.

## Developer Notes

### File Structure

```
woocommerce-pos-credit-card-fee/
├── assets/
│   └── js/
│       └── fee-manager.js
├── .github/
│   └── workflows/
│       └── release.yml
├── readme.txt
├── README.md
└── woocommerce-pos-credit-card-fee.php
```

### Hooks

The plugin uses several WordPress/WooCommerce hooks:

- `woocommerce_get_sections_advanced`, `woocommerce_get_settings_advanced` — POS fees settings
- `woocommerce_rest_insert_shop_order_object` — create or recalculate the always fee on POS orders
- `woocommerce_before_pay_action` — reconcile the card fee before payment
- `woocommerce_gateway_description` — card fee status
- `woocommerce_hidden_order_itemmeta` — hide internal rule metadata
- `wp_enqueue_scripts` — load the POS payment-page script
- `wp_ajax_wcpos_add_credit_card_fee`, `wp_ajax_wcpos_remove_credit_card_fee` and their `wp_ajax_nopriv_` equivalents — session-token-protected card fee changes

Fee lines are identified by `_wcpos_ccf_rule` metadata, not their names. There is no cart-page fee calculation.

## License

GPL v2 or later

## Support

For support, please visit [https://wcpos.com/](https://wcpos.com/)
