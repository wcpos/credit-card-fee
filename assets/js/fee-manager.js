jQuery(document).ready(function($) {
    'use strict';
    
    // Only run on POS checkout order-pay pages
    if (!window.location.pathname.includes('/wcpos-checkout/order-pay/')) {
        return;
    }
    
    // Cache elements
    var $checkoutForm = $('form.checkout');
    var inFlight = false;
    // WordPress localization converts scalar booleans to '1' or ''.
    wcpos_ccf.card_fee_on_order = Boolean(wcpos_ccf.card_fee_on_order);
    
    // Restore selected payment method after reload
    // Use a slight delay to ensure WooCommerce payment scripts have initialized
    var savedPayment = sessionStorage.getItem('wcpos_selected_payment');
    if (savedPayment) {
        setTimeout(function() {
            var $savedRadio = $('input[name="payment_method"][value="' + savedPayment + '"]');
            if ($savedRadio.length) {
                // Check the radio button
                $savedRadio.prop('checked', true);
                
                // Hide all payment boxes first
                $('.payment_box').hide();
                
                // Show the selected payment method's box
                var $paymentBox = $('.payment_box.payment_method_' + savedPayment);
                if ($paymentBox.length) {
                    $paymentBox.show();
                } else {
                    // Fallback: find parent li and show its payment_box
                    $savedRadio.closest('li').find('.payment_box').show();
                }
                
                // Trigger change event for any other listeners
                $savedRadio.trigger('change');
            }
            sessionStorage.removeItem('wcpos_selected_payment');
            syncCardFee();
        }, 150);
    }
    
    function syncCardFee() {
        var payment = $('input[name="payment_method"]:checked').val() || '';
        var wanted = wcpos_ccf.card_gateways.indexOf(payment) !== -1;
        if (inFlight || wanted === wcpos_ccf.card_fee_on_order) {
            return;
        }
        var pathMatch = window.location.pathname.match(/order-pay\/(\d+)/);
        inFlight = true;
        $('#place_order').prop('disabled', true);
        $.ajax({
            url: wcpos_ccf.ajax_url,
            type: 'POST',
            data: {
                action: wanted ? 'wcpos_add_credit_card_fee' : 'wcpos_remove_credit_card_fee',
                security_token: wcpos_ccf.security_token,
                order_id: pathMatch ? pathMatch[1] : '',
                order_key: new URLSearchParams(window.location.search).get('key') || '',
                payment_method: payment
            },
            success: function(response) {
                if (response.success) {
                    var selectedPayment = $('input[name="payment_method"]:checked').val();
                    if (selectedPayment) {
                        sessionStorage.setItem('wcpos_selected_payment', selectedPayment);
                    }
                    window.location.reload();
                } else {
                    inFlight = false;
                    $('#place_order').prop('disabled', false);
                    showNotice(response.data && response.data.message || 'Fee could not be updated.', 'error');
                }
            },
            error: function() {
                inFlight = false;
                $('#place_order').prop('disabled', false);
                showNotice('Error updating fee. Please refresh the page and try again.', 'error');
            }
        });
    }

    // The pay page form is #order_review, so delegate from body rather than form.checkout.
    $(document.body).on('change', 'input[name="payment_method"]', syncCardFee);
    if (!savedPayment) {
        syncCardFee();
    }

    /**
     * Show notice message
     */
    function showNotice(message, type) {
        // Remove existing notices
        $('.wcpos-ccf-notice').remove();
        
        var noticeHtml = '<div class="woocommerce-NoticeGroup wcpos-ccf-notice">' +
                        '<div class="woocommerce-message woocommerce-' + type + '" role="alert">' +
                        message + '</div></div>';
        
        // Find the best place to insert the notice
        var $insertTarget = $('.woocommerce-checkout-payment');
        if (!$insertTarget.length) {
            $insertTarget = $('#order_review');
        }
        if (!$insertTarget.length) {
            $insertTarget = $checkoutForm;
        }
        
        // Add notice before target element
        if ($insertTarget.length) {
            $insertTarget.before(noticeHtml);
            
            // Scroll to notice only if element exists
            var $notice = $('.wcpos-ccf-notice');
            if ($notice.length && $notice.offset()) {
                $('html, body').animate({
                    scrollTop: $notice.offset().top - 100
                }, 500);
            }
        } else {
            // Fallback: show as alert if no suitable container found
            alert(message);
            return;
        }
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $('.wcpos-ccf-notice').fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
});
