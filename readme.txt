=== WooCommerce POS Credit Card Fee ===
Contributors: kilbot
Tags: woocommerce, payment, fees, pos, credit card
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Configurable always and automatic card fees for WooCommerce POS orders.

== Description ==

Add an optional always fee to new POS orders and automatically apply a card fee for selected gateways on the POS order-pay page.

Both fees use the discounted product line totals, excluding tax, shipping and other fees. Configure their names and percentages separately, with a shared tax status.

== Installation ==

1. Upload the `woocommerce-pos-credit-card-fee` zip to your WordPress site
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Make sure WooCommerce is installed and activated
4. Install and activate WooCommerce POS, and enable the payment gateways you want to use

== Usage ==

1. An administrator opens WooCommerce → Settings → Advanced → POS fees and saves the desired rules.
2. New POS orders receive the always fee when enabled. Order updates recalculate it only while its fee line remains; removing it in the POS app leaves it off that order.
3. On the POS order-pay page, selecting a configured gateway applies the card fee. Selecting another gateway removes it.
4. The page reloads and restores the selected payment method. Configured gateways show a status when the card fee is present.
5. The card fee is reconciled again before payment processing, even if AJAX did not run.

== Configuration ==

WooCommerce → Settings → Advanced → POS fees contains:
- Always fee name: Service fee by default.
- Always fee (%): blank by default (off).
- Card fee name: Card fee by default.
- Card fee (%): 3 by default.
- Card fee gateways: a multiselect of installed gateways, defaulting to Stripe Terminal for WooCommerce (stripe_terminal_for_woocommerce).
- Fee tax status: Not taxable by default, or Taxable, standard class, for both rules.

Blank or zero disables a rule. Selecting no card gateways applies no card fee.
Settings do not bulk-update existing orders. Disabling the always rule leaves existing always fee lines unchanged.
Older fee lines without rule metadata are not automatically modified or removed. Fees are not applied to the cart page.

== Changelog ==

= 0.1.0 =
* Add configurable always and automatic card fee rules under Advanced → POS fees.
* Calculate fees from discounted product line totals excluding tax.
* Identify fee lines by rule metadata and reconcile card fees before payment.

= 0.0.4 =
* Calculate fee including tax

= 0.0.3 = 
* Improve fee display

= 0.0.2 =
* Add gateway configuration

= 0.0.1 =
* Initial release
* Add/remove credit card fee functionality
* AJAX-powered for smooth user experience
* Session-based fee persistence

