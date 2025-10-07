=== WooCommerce POS Credit Card Fee ===
Contributors: kilbot
Tags: woocommerce, payment, fees, pos, credit card
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.2
Stable tag: 0.0.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Adds credit card fee management buttons to the WooCommerce pos_cash payment gateway.

== Description ==

This plugin adds two buttons to the pos_cash payment method in WooCommerce checkout:
- Add credit card fee (3%)
- Remove credit card fee

When customers select the pos_cash payment method, they can choose to add or remove a 3% credit card processing fee to their order.

== Installation ==

1. Upload the `woocommerce-pos-credit-card-fee` zip to your WordPress site
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Make sure WooCommerce is installed and activated
4. Make sure the pos_cash payment gateway is enabled in WooCommerce settings

== Usage ==

1. When customers go to checkout and select the "pos_cash" payment method, they will see two buttons
2. Clicking "Add credit card fee" will add a 3% fee to the order total
3. Clicking "Remove credit card fee" will remove the fee
4. The fee is automatically recalculated based on the cart subtotal
5. The fee persists in the customer's session until removed

== Configuration ==

To change the fee percentage, edit the following line in the main plugin file:
`define( 'WCPOS_CCF_FEE_PERCENTAGE', 3 );`

== Changelog ==

= 0.0.3 = 
* Improve button UI

= 0.0.2 =
* Add GATEWAY_ID variable, default to 'stripe_terminal_for_woocommerce'

= 0.0.1 =
* Initial release
* Add/remove 3% credit card fee functionality
* AJAX-powered for smooth user experience
* Session-based fee persistence

