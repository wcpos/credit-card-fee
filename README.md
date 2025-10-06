# WooCommerce POS Credit Card Fee

A WordPress plugin that adds credit card fee management functionality to WooCommerce POS checkout pages.

## Features

- Add/remove a 3% credit card fee on order-pay pages
- Session-based security (doesn't require WordPress user authentication)
- Works directly with WooCommerce orders (not cart-based)
- Restricted to POS checkout pages only (`/wcpos-checkout/order-pay/`)
- Preserves selected payment method across page reloads
- Direct order modification using `WC_Order_Item_Fee`

## Installation

1. Download the plugin
2. Upload to your WordPress `/wp-content/plugins/` directory
3. Activate the plugin through the WordPress admin

## Usage

The plugin automatically adds fee management buttons to the BACS (Bank Transfer) payment method on POS checkout pages.

1. Navigate to a POS order-pay page (`/wcpos-checkout/order-pay/{order-id}/`)
2. Select the Bank Transfer payment method
3. Click "Add credit card fee" to add a 3% fee to the order
4. Click "Remove credit card fee" to remove the fee

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- WooCommerce POS

## Configuration

The fee percentage is currently set to 3% and defined as a constant in the main plugin file:

```php
define('WCPOS\WooCommercePOS\CreditCardFee\FEE_PERCENTAGE', 3);
```

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

- `woocommerce_gateway_description` - Adds buttons to payment method description
- `woocommerce_cart_calculate_fees` - Adds fees to cart (for regular checkout)
- `before_woocommerce_pay` - Handles fee display on order-pay pages
- `wp_ajax_wcpos_add_credit_card_fee` - AJAX handler for adding fees
- `wp_ajax_wcpos_remove_credit_card_fee` - AJAX handler for removing fees

## License

GPL v2 or later

## Support

For support, please visit [https://wcpos.com/](https://wcpos.com/)
