<?php

/**
 * Plugin Name: WooCommerce POS Credit Card Fee
 * Plugin URI: https://wcpos.com/
 * Description: Configurable always and automatic card fees for WooCommerce POS
 * Version: 0.1.0
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
define('WCPOS\WooCommercePOS\CreditCardFee\PLUGIN_VERSION', '0.1.0');

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
        // Show the card fee status in configured gateway descriptions
        add_filter('woocommerce_gateway_description', array($this, 'card_fee_status_in_description'), 10, 2);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX handlers
        add_action('wp_ajax_wcpos_add_credit_card_fee', array($this, 'ajax_add_fee'));
        add_action('wp_ajax_nopriv_wcpos_add_credit_card_fee', array($this, 'ajax_add_fee'));
        add_action('wp_ajax_wcpos_remove_credit_card_fee', array($this, 'ajax_remove_fee'));
        add_action('wp_ajax_nopriv_wcpos_remove_credit_card_fee', array($this, 'ajax_remove_fee'));

        add_filter('woocommerce_get_sections_advanced', array($this, 'settings_section'));
        add_filter('woocommerce_get_settings_advanced', array($this, 'settings_fields'), 10, 2);
        add_filter('woocommerce_hidden_order_itemmeta', function ($keys) {
            $keys[] = '_wcpos_ccf_rule';
            return $keys;
        });
        add_action('woocommerce_rest_insert_shop_order_object', array($this, 'apply_always_fee'), 10, 3);
        add_action('woocommerce_order_after_calculate_totals', array($this, 'recompute_always_fee'), 10, 2);
        add_action('woocommerce_before_pay_action', array($this, 'reconcile_card_fee'));
    }

    public function settings_section($sections)
    {
        $sections['wcpos_fees'] = __('POS fees', 'wcpos-ccf');
        return $sections;
    }

    public function settings_fields($settings, $section)
    {
        if ($section !== 'wcpos_fees') {
            return $settings;
        }
        $gateways = array();
        foreach (\WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            $gateways[$id] = $gateway->get_title();
        }
        $settings = array(array('title' => __('POS fees', 'wcpos-ccf'), 'type' => 'title', 'id' => 'wcpos_fees'));
        foreach (array('always' => __('Always fee', 'wcpos-ccf'), 'card' => __('Card fee', 'wcpos-ccf')) as $id => $label) {
            $settings[] = array(
                'title' => $label . ' ' . __('name', 'wcpos-ccf'), 'type' => 'text', 'id' => 'wcpos_ccf_' . $id . '_name',
                'default' => $id === 'always' ? __('Service fee', 'wcpos-ccf') : __('Card fee', 'wcpos-ccf'),
            );
            $settings[] = array(
                'title' => $label . ' (%)', 'type' => 'number', 'id' => 'wcpos_ccf_' . $id . '_percent',
                'default' => $id === 'always' ? '' : 3, 'custom_attributes' => array('step' => '0.01', 'min' => '0'),
                'desc' => __('Blank or 0 disables this fee.', 'wcpos-ccf'),
            );
        }
        $settings[] = array(
            'title' => __('Card fee gateways', 'wcpos-ccf'), 'type' => 'multiselect', 'id' => 'wcpos_ccf_card_gateways',
            'options' => $gateways, 'default' => array('stripe_terminal_for_woocommerce'), 'class' => 'wc-enhanced-select',
        );
        $settings[] = array(
            'title' => __('Fee tax status', 'wcpos-ccf'), 'type' => 'select', 'id' => 'wcpos_ccf_tax_status', 'default' => 'none',
            'options' => array('taxable' => __('Taxable, standard class', 'wcpos-ccf'), 'none' => __('Not taxable', 'wcpos-ccf')),
        );
        $settings[] = array('type' => 'sectionend', 'id' => 'wcpos_fees');
        return $settings;
    }

    private function get_always_rule()
    {
        return array(
            'id' => 'always', 'name' => (string) get_option('wcpos_ccf_always_name', __('Service fee', 'wcpos-ccf')),
            'percent' => max(0.0, (float) get_option('wcpos_ccf_always_percent', '')),
            'tax_status' => get_option('wcpos_ccf_tax_status', 'none') === 'taxable' ? 'taxable' : 'none',
            'gateways' => array(),
        );
    }

    private function get_card_rule()
    {
        return array(
            'id' => 'card', 'name' => (string) get_option('wcpos_ccf_card_name', __('Card fee', 'wcpos-ccf')),
            'percent' => max(0.0, (float) get_option('wcpos_ccf_card_percent', 3)),
            'tax_status' => get_option('wcpos_ccf_tax_status', 'none') === 'taxable' ? 'taxable' : 'none',
            'gateways' => array_values((array) get_option('wcpos_ccf_card_gateways', array('stripe_terminal_for_woocommerce'))),
        );
    }

    private function fee_amount_for($order, $rule)
    {
        $basis = 0;
        foreach ($order->get_items('line_item') as $item) {
            $basis += (float) $item->get_total();
        }
        return wc_format_decimal($basis * $rule['percent'] / 100, wc_get_price_decimals());
    }

    private function find_fee($order, $rule_id)
    {
        foreach ($order->get_fees() as $fee) {
            if ($fee->get_meta('_wcpos_ccf_rule') === $rule_id) {
                return $fee;
            }
        }
        // A card fee written by 0.0.4 carries no meta; adopt it rather than add a second one.
        if ($rule_id === 'card') {
            foreach ($order->get_fees() as $fee) {
                if (!$fee->get_meta('_wcpos_ccf_rule') && strpos($fee->get_name(), 'Credit Card Fee') === 0) {
                    return $fee;
                }
            }
        }
        return false;
    }

    private function upsert_fee($order, $rule)
    {
        $fee = $this->find_fee($order, $rule['id']);
        $amount = $this->fee_amount_for($order, $rule);
        if ($fee && $fee->get_name() === $rule['name'] && (float) $fee->get_total() === (float) $amount
            && (float) $fee->get_amount() === (float) $amount && $fee->get_tax_status() === $rule['tax_status']
            && ($rule['tax_status'] !== 'taxable' || $fee->get_tax_class() === '')) {
            return false;
        }
        $new = !$fee;
        if ($new) {
            $fee = new \WC_Order_Item_Fee();
        }
        if (!$fee->get_meta('_wcpos_ccf_rule')) {
            $fee->add_meta_data('_wcpos_ccf_rule', $rule['id'], true);
        }
        $fee->set_name($rule['name']);
        $fee->set_amount($amount);
        $fee->set_total($amount);
        $fee->set_tax_status($rule['tax_status']);
        if ($rule['tax_status'] === 'taxable') {
            $fee->set_tax_class('');
        }
        if ($new) {
            $order->add_item($fee);
        }
        return true;
    }

    private function remove_fee($order, $rule_id)
    {
        $removed = false;
        foreach ($order->get_fees() as $id => $fee) {
            if ($fee->get_meta('_wcpos_ccf_rule') === $rule_id) {
                $order->remove_item($id);
                $removed = true;
            }
        }
        return $removed;
    }

    public function apply_always_fee($order, $request, $creating)
    {
        $rule = $this->get_always_rule();
        if (!$creating || $order->get_created_via() !== 'woocommerce-pos' || $rule['percent'] <= 0) {
            return; // Updates are covered by recompute_always_fee; a removed line stays off.
        }
        $this->upsert_fee($order, $rule);
        $order->calculate_totals();
        $order->save();
    }

    /**
     * Keep an existing always fee in step with the line items on every totals
     * recalculation: REST updates, pay-page coupons, admin edits.
     */
    public function recompute_always_fee($and_taxes, $order)
    {
        static $busy = false;
        if ($busy || !$order instanceof \WC_Order || $order->get_created_via() !== 'woocommerce-pos') {
            return;
        }
        $rule = $this->get_always_rule();
        if ($rule['percent'] <= 0 || !$this->find_fee($order, 'always')) {
            return;
        }
        $busy = true;
        try {
            if ($this->upsert_fee($order, $rule)) {
                $order->calculate_totals($and_taxes);
            }
        } finally {
            $busy = false;
        }
    }

    public function reconcile_card_fee($order)
    {
        // The order is supplied; only the POS checkout path matters here, not the endpoint query var.
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/wcpos-checkout/') === false) {
            return;
        }
        $rule = $this->get_card_rule();
        $payment_method = wc_clean(wp_unslash($_POST['payment_method'] ?? ''));
        $wanted = $rule['percent'] > 0 && in_array($payment_method, $rule['gateways'], true);
        $changed = $wanted ? $this->upsert_fee($order, $rule) : $this->remove_fee($order, 'card');
        if ($changed) {
            $order->calculate_totals();
            $order->save();
        }
    }

    public function card_fee_status_in_description($description, $gateway_id)
    {
        $rule = $this->get_card_rule();
        if (!$this->is_pos_order_pay_page() || !in_array($gateway_id, $rule['gateways'], true)) {
            return $description;
        }
        $order = wc_get_order(absint(get_query_var('order-pay')));
        if (!$order || !$this->find_fee($order, 'card')) {
            return $description;
        }
        return $description . '<span class="wcpos-ccf-status">' .
            esc_html(sprintf(__('%1$s %2$s%% applied', 'wcpos-ccf'), $rule['name'], $rule['percent'])) . '</span>';
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

            $rule = $this->get_card_rule();
            $order = wc_get_order(absint(get_query_var('order-pay')));
            wp_localize_script('wcpos-credit-card-fee', 'wcpos_ccf', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wcpos_ccf_nonce'), // Keep for backward compatibility
                'security_token' => $security_token,
                'card_gateways' => $rule['percent'] > 0 ? $rule['gateways'] : array(),
                'card_fee_on_order' => $order && (bool) $this->find_fee($order, 'card')
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
        if (!$order->needs_payment()) {
            wp_send_json_error(array('message' => __('This order no longer needs payment.', 'wcpos-ccf')));
            return;
        }

        $rule = $this->get_card_rule();
        $payment_method = wc_clean(wp_unslash($_POST['payment_method'] ?? ''));
        if ($rule['percent'] <= 0 || !in_array($payment_method, $rule['gateways'], true)) {
            wp_send_json_error(array('message' => __('Card fee is not enabled for this payment method.', 'wcpos-ccf')));
            return;
        }
        $this->upsert_fee($order, $rule);
        $order->calculate_totals();
        $order->save();

        wp_send_json_success(array(
            'message' => __('Card fee applied', 'wcpos-ccf'),
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
        if (!$order->needs_payment()) {
            wp_send_json_error(array('message' => __('This order no longer needs payment.', 'wcpos-ccf')));
            return;
        }

        $payment_method = wc_clean(wp_unslash($_POST['payment_method'] ?? ''));
        $rule = $this->get_card_rule();
        if ($rule['percent'] > 0 && in_array($payment_method, $rule['gateways'], true)) {
            wp_send_json_error(array('message' => __('Card fee is required for this payment method.', 'wcpos-ccf')));
            return;
        }
        $removed = $this->remove_fee($order, 'card');

        if ($removed) {
            $order->calculate_totals();
            $order->save();
        }

        wp_send_json_success(array(
            'message' => __('Credit card fee removed', 'wcpos-ccf'),
            'reload' => true // Tell JS to reload the page
        ));
    }

}

// Initialize the plugin
add_action('plugins_loaded', function () {
    new Manager();
});
