<?php

/**
 * Plugin Name: WooCommerce POS Credit Card Fee
 * Plugin URI: https://wcpos.com/
 * Description: Adds credit card fee management buttons to a WooCommerce payment gateway
 * Version: 0.0.3
 * Author: kilbot
 * License: GPL v2 or later
 * Text Domain: wcpos-ccf
 */

namespace WCPOS\WooCommercePOS\CreditCardFee;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WCPOS\WooCommercePOS\CreditCardFee\PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCPOS\WooCommercePOS\CreditCardFee\PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WCPOS\WooCommercePOS\CreditCardFee\FEE_PERCENTAGE', 3); // 3% fee
define('WCPOS\WooCommercePOS\CreditCardFee\GATEWAY_ID', 'stripe_terminal_for_woocommerce');
define('WCPOS\WooCommercePOS\CreditCardFee\PLUGIN_VERSION', '0.0.3');

/**
 * Main plugin class
 */
class Manager
{
    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('init', array($this, 'init'));
    }

    /**
     * Initialize the plugin
     */
    public function init()
    {
        // Add buttons to pos_cash payment fields via gateway description
        add_filter('woocommerce_gateway_description', array($this, 'add_fee_buttons_to_description'), 10, 2);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX handlers
        add_action('wp_ajax_wcpos_add_credit_card_fee', array($this, 'ajax_add_fee'));
        add_action('wp_ajax_nopriv_wcpos_add_credit_card_fee', array($this, 'ajax_add_fee'));
        add_action('wp_ajax_wcpos_remove_credit_card_fee', array($this, 'ajax_remove_fee'));
        add_action('wp_ajax_nopriv_wcpos_remove_credit_card_fee', array($this, 'ajax_remove_fee'));

        // Add fee to cart if session flag is set (for checkout page)
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_credit_card_fee'));

        // Handle fee for order-pay page
        add_action('before_woocommerce_pay', array($this, 'add_fee_to_order_pay'));
    }

    /**
     * Add fee management buttons to pos_cash payment fields via gateway description
     */
    public function add_fee_buttons_to_description($description, $gateway_id)
    {
        // Only add buttons on POS checkout pages
        if (!$this->is_pos_order_pay_page()) {
            return $description;
        }

        // Only add buttons to the required gateway
        if ($gateway_id !== GATEWAY_ID) {
            return $description;
        }

        $has_fee = \WC()->session->get('wcpos_add_credit_card_fee');

        // Build the buttons HTML with inline styles
        $buttons_html = '<div class="wcpos-ccf-buttons" style="margin-top:15px;padding:15px;background:#f7f7f7;border-radius:4px;border:1px solid #e0e0e0;">';
        
        // Add fee button
        $add_display = $has_fee ? 'display:none;' : '';
        $add_disabled = $has_fee ? ' disabled' : '';
        $buttons_html .= '<button type="button" class="button wcpos-add-cc-fee-btn"' . $add_disabled . ' style="' . $add_display . 'background:#2271b1;color:#fff;border:none;padding:10px 20px;font-size:14px;font-weight:500;border-radius:4px;cursor:pointer;transition:all 0.2s ease;">';
        $buttons_html .= esc_html__('Add credit card fee', 'wcpos-ccf');
        $buttons_html .= '</button>';
        
        // Remove fee button
        $remove_display = !$has_fee ? 'display:none;' : '';
        $remove_disabled = !$has_fee ? ' disabled' : '';
        $buttons_html .= '<button type="button" class="button wcpos-remove-cc-fee-btn"' . $remove_disabled . ' style="' . $remove_display . 'background:#dc3232;color:#fff;border:none;padding:10px 20px;font-size:14px;font-weight:500;border-radius:4px;cursor:pointer;transition:all 0.2s ease;margin-left:10px;">';
        $buttons_html .= esc_html__('Remove credit card fee', 'wcpos-ccf');
        $buttons_html .= '</button>';

        // Status badge
        if ($has_fee) {
            $buttons_html .= '<span class="wcpos-fee-status" style="display:inline-block;margin-left:10px;padding:8px 12px;background:#d4edda;color:#155724;border-radius:4px;font-weight:600;font-size:13px;border:1px solid #c3e6cb;">';
            $buttons_html .= sprintf(esc_html__('%d%% fee applied', 'wcpos-ccf'), FEE_PERCENTAGE);
            $buttons_html .= '</span>';
        }

        $buttons_html .= '</div>';

        // Append buttons to the description
        return $description . $buttons_html;
    }

    /**
     * Check if we're on the POS checkout order-pay page
     */
    private function is_pos_order_pay_page()
    {
        // Check if we're on order-pay endpoint
        if (!is_wc_endpoint_url('order-pay')) {
            return false;
        }

        // Check if URL contains /wcpos-checkout/
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($request_uri, '/wcpos-checkout/') !== false;
    }

    /**
     * Enqueue necessary scripts
     */
    public function enqueue_scripts()
    {
        if ($this->is_pos_order_pay_page()) {
            wp_enqueue_script(
                'wcpos-credit-card-fee',
                PLUGIN_URL . 'assets/js/fee-manager.js',
                array('jquery', 'wc-checkout'),
                PLUGIN_VERSION,
                true
            );

            // Generate a custom security token based on session
            $security_token = $this->generate_security_token();

            wp_localize_script('wcpos-credit-card-fee', 'wcpos_ccf', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wcpos_ccf_nonce'), // Keep for backward compatibility
                'security_token' => $security_token,
                'fee_percentage' => FEE_PERCENTAGE
            ));
        }
    }

    /**
     * Generate a custom security token
     *
     * This method creates a security token based on the WooCommerce session
     * rather than the WordPress user system. This is necessary for custom
     * checkout pages where the logged-in user context may be modified.
     *
     * The token is valid for 1 hour and uses a combination of:
     * - A unique session token stored in WC session
     * - Current timestamp (hourly blocks)
     * - WordPress security salts
     */
    private function generate_security_token()
    {
        // Use session-based token that doesn't rely on user authentication
        if (!\WC()->session) {
            return '';
        }

        // Get or create a unique session token
        $session_token = \WC()->session->get('wcpos_ccf_token');
        if (!$session_token) {
            $session_token = wp_generate_password(32, false);
            \WC()->session->set('wcpos_ccf_token', $session_token);
        }

        // Create a hash with timestamp for additional security
        $timestamp = floor(time() / 3600); // Valid for 1 hour
        $secret = defined('NONCE_KEY') ? NONCE_KEY : AUTH_KEY; // Use WordPress salt

        return hash_hmac('sha256', $session_token . $timestamp, $secret);
    }

    /**
     * Verify custom security token
     */
    private function verify_security_token($token)
    {
        if (empty($token) || !\WC()->session) {
            return false;
        }

        // Get stored session token
        $session_token = \WC()->session->get('wcpos_ccf_token');
        if (!$session_token) {
            return false;
        }

        // Check current and previous hour tokens (allows for clock differences)
        $timestamp = floor(time() / 3600);
        $prev_timestamp = $timestamp - 1;
        $secret = defined('NONCE_KEY') ? NONCE_KEY : AUTH_KEY;

        $current_token = hash_hmac('sha256', $session_token . $timestamp, $secret);
        $prev_token = hash_hmac('sha256', $session_token . $prev_timestamp, $secret);

        return hash_equals($current_token, $token) || hash_equals($prev_token, $token);
    }

    /**
     * AJAX handler to add fee
     */
    public function ajax_add_fee()
    {
        // Use custom security check instead of nonce
        if (!isset($_POST['security_token'])) {
            wp_send_json_error(array('message' => 'Security token missing. Please refresh the page and try again.'));
            return;
        }

        if (!$this->verify_security_token($_POST['security_token'])) {
            wp_send_json_error(array('message' => 'Security verification failed. Please refresh the page and try again.'));
            return;
        }

        // Get order ID from the pay page
        $order_id = absint(get_query_var('order-pay'));
        if (!$order_id && isset($_POST['order_id'])) {
            $order_id = absint($_POST['order_id']);
        }

        if (!$order_id) {
            wp_send_json_error(array('message' => 'Order ID not found.'));
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found.'));
            return;
        }

        // Calculate fee amount (3% of order total)
        $subtotal = $order->get_subtotal();
        $fee_amount = $subtotal * (FEE_PERCENTAGE / 100);

        if ($fee_amount <= 0) {
            wp_send_json_error(array('message' => 'Invalid fee amount.'));
            return;
        }

        // Remove any existing credit card fees
        foreach ($order->get_fees() as $fee_id => $fee) {
            if (strpos($fee->get_name(), 'Credit Card Fee') !== false) {
                $order->remove_item($fee_id);
            }
        }

        // Add the new fee
        $fee = new \WC_Order_Item_Fee();
        $fee->set_name(sprintf(__('Credit Card Fee (%d%%)', 'wcpos-ccf'), FEE_PERCENTAGE));
        $fee->set_amount($fee_amount);
        $fee->set_tax_class('');
        $fee->set_tax_status('taxable');
        $fee->set_total($fee_amount);

        // Add fee to order
        $order->add_item($fee);
        $order->calculate_totals();
        $order->save();

        // Store in session for reference
        \WC()->session->set('wcpos_add_credit_card_fee', true);
        \WC()->session->set('wcpos_fee_order_' . $order_id, true);

        wp_send_json_success(array(
            'message' => sprintf(__('%d%% credit card fee added', 'wcpos-ccf'), FEE_PERCENTAGE),
            'reload' => true // Tell JS to reload the page
        ));
    }

    /**
     * AJAX handler to remove fee
     */
    public function ajax_remove_fee()
    {
        // Use custom security check instead of nonce
        if (!isset($_POST['security_token'])) {
            wp_send_json_error(array('message' => 'Security token missing. Please refresh the page and try again.'));
            return;
        }

        if (!$this->verify_security_token($_POST['security_token'])) {
            wp_send_json_error(array('message' => 'Security verification failed. Please refresh the page and try again.'));
            return;
        }

        // Get order ID from the pay page
        $order_id = absint(get_query_var('order-pay'));
        if (!$order_id && isset($_POST['order_id'])) {
            $order_id = absint($_POST['order_id']);
        }

        if (!$order_id) {
            wp_send_json_error(array('message' => 'Order ID not found.'));
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found.'));
            return;
        }

        // Remove any existing credit card fees
        $removed = false;
        foreach ($order->get_fees() as $fee_id => $fee) {
            if (strpos($fee->get_name(), 'Credit Card Fee') !== false) {
                $order->remove_item($fee_id);
                $removed = true;
            }
        }

        if ($removed) {
            $order->calculate_totals();
            $order->save();
        }

        // Clear session flags
        \WC()->session->set('wcpos_add_credit_card_fee', false);
        \WC()->session->set('wcpos_fee_order_' . $order_id, false);

        wp_send_json_success(array(
            'message' => __('Credit card fee removed', 'wcpos-ccf'),
            'reload' => true // Tell JS to reload the page
        ));
    }

    /**
     * Handle fee display for order-pay page
     */
    public function add_fee_to_order_pay()
    {
        if (!$this->is_pos_order_pay_page()) {
            return;
        }

        $order_id = absint(get_query_var('order-pay'));
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if order already has the fee
        $has_fee = false;
        foreach ($order->get_fees() as $fee) {
            if (strpos($fee->get_name(), 'Credit Card Fee') !== false) {
                $has_fee = true;
                break;
            }
        }

        // Update button visibility based on existing fee
        if ($has_fee) {
            \WC()->session->set('wcpos_add_credit_card_fee', true);
            \WC()->session->set('wcpos_fee_order_' . $order_id, true);
        }
    }

    /**
     * Add credit card fee to cart
     */
    public function add_credit_card_fee($cart)
    {
        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }

        // Check if fee should be added
        $should_add_fee = \WC()->session->get('wcpos_add_credit_card_fee');

        if (! $should_add_fee) {
            return;
        }

        // Calculate fee amount (3% of subtotal)
        $subtotal = $cart->get_subtotal();
        $fee_amount = $subtotal * (FEE_PERCENTAGE / 100);

        // Don't add fee if amount is zero
        if ($fee_amount <= 0) {
            return;
        }

        // Add the fee with a unique key to ensure it's properly tracked
        $cart->add_fee(
            sprintf(__('Credit Card Fee (%d%%)', 'wcpos-ccf'), FEE_PERCENTAGE),
            $fee_amount,
            true, // Taxable
            'standard' // Tax class
        );
    }

}

// Initialize the plugin
add_action('plugins_loaded', function () {
    new Manager();
});
