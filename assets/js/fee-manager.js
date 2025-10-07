jQuery(document).ready(function($) {
    'use strict';
    
    // Only run on POS checkout order-pay pages
    if (!window.location.pathname.includes('/wcpos-checkout/order-pay/')) {
        return;
    }
    
    // Cache elements
    var $body = $('body');
    var $checkoutForm = $('form.checkout');
    
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
        }, 150);
    }
    
    /**
     * Force checkout refresh
     */
    function forceCheckoutRefresh() {
        // Try multiple methods to ensure checkout updates
        
        // Method 1: Standard WooCommerce checkout update
        $body.trigger('update_checkout');
        
        // Method 2: If checkout params available, use direct AJAX update
        if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.ajax_url) {
            setTimeout(function() {
                $.ajax({
                    type: 'POST',
                    url: wc_checkout_params.ajax_url,
                    data: {
                        'action': 'woocommerce_update_order_review',
                        'security': wc_checkout_params.update_order_review_nonce || '',
                        'payment_method': $('input[name="payment_method"]:checked').val() || ''
                    },
                    success: function(response) {
                        if (response && response.fragments) {
                            $.each(response.fragments, function(key, value) {
                                $(key).replaceWith(value);
                            });
                            $body.trigger('updated_checkout');
                        }
                    }
                });
            }, 100);
        }
        
        // Method 3: For custom checkout pages, trigger a form change
        setTimeout(function() {
            $checkoutForm.trigger('change');
        }, 200);
    }
    
    /**
     * Handle add fee button click
     */
    $body.on('click', '.wcpos-add-cc-fee-btn', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $removeButton = $('.wcpos-remove-cc-fee-btn');
        var $status = $('.wcpos-fee-status');
        
        // Get order ID from URL or form
        var orderId = '';
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('pay_for_order') && urlParams.has('key')) {
            // Extract order ID from the URL path
            var pathMatch = window.location.pathname.match(/order-pay\/(\d+)/);
            if (pathMatch) {
                orderId = pathMatch[1];
            }
        }
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Adding fee...');
        
        // Make AJAX request
        $.ajax({
            url: wcpos_ccf.ajax_url,
            type: 'POST',
            data: {
                action: 'wcpos_add_credit_card_fee',
                nonce: wcpos_ccf.nonce, // Keep for backward compatibility
                security_token: wcpos_ccf.security_token,
                order_id: orderId
            },
            success: function(response) {
                if (response.success) {
                    // If reload flag is set, reload the page
                    if (response.data && response.data.reload) {
                        // Update button to show reloading state
                        $button.text('Reloading page...').css('opacity', '0.7');
                        
                        // Store selected payment method before reload
                        var selectedPayment = $('input[name="payment_method"]:checked').val();
                        if (selectedPayment) {
                            sessionStorage.setItem('wcpos_selected_payment', selectedPayment);
                        }
                        
                        // Reload immediately
                        setTimeout(function() {
                            window.location.reload();
                        }, 500);
                    } else {
                        // Otherwise update UI without reload
                        $button.hide();
                        $removeButton.show();
                        
                        if ($status.length) {
                            $status.html(wcpos_ccf.fee_percentage + '% fee applied');
                        }
                        
                        if (response.data && response.data.message) {
                            showNotice(response.data.message, 'success');
                        }
                        
                        forceCheckoutRefresh();
                        
                        // Re-enable button
                        $button.prop('disabled', false).text('Add credit card fee');
                    }
                } else {
                    // Handle error response
                    var errorMsg = 'Fee could not be added.';
                    if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    showNotice(errorMsg, 'error');
                    
                    // Re-enable button on error
                    $button.prop('disabled', false).text('Add credit card fee');
                }
            },
            error: function(xhr) {
                var message = 'Error adding fee. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                showNotice(message, 'error');
                
                // Re-enable button on error
                $button.prop('disabled', false).text('Add credit card fee');
            }
        });
    });
    
    /**
     * Handle remove fee button click
     */
    $body.on('click', '.wcpos-remove-cc-fee-btn', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $addButton = $('.wcpos-add-cc-fee-btn');
        var $status = $('.wcpos-fee-status');
        
        // Get order ID from URL or form
        var orderId = '';
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('pay_for_order') && urlParams.has('key')) {
            // Extract order ID from the URL path
            var pathMatch = window.location.pathname.match(/order-pay\/(\d+)/);
            if (pathMatch) {
                orderId = pathMatch[1];
            }
        }
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Removing fee...');
        
        // Make AJAX request
        $.ajax({
            url: wcpos_ccf.ajax_url,
            type: 'POST',
            data: {
                action: 'wcpos_remove_credit_card_fee',
                nonce: wcpos_ccf.nonce, // Keep for backward compatibility
                security_token: wcpos_ccf.security_token,
                order_id: orderId
            },
            success: function(response) {
                if (response.success) {
                    // If reload flag is set, reload the page
                    if (response.data && response.data.reload) {
                        // Update button to show reloading state
                        $button.text('Reloading page...').css('opacity', '0.7');
                        
                        // Store selected payment method before reload
                        var selectedPayment = $('input[name="payment_method"]:checked').val();
                        if (selectedPayment) {
                            sessionStorage.setItem('wcpos_selected_payment', selectedPayment);
                        }
                        
                        // Reload immediately
                        setTimeout(function() {
                            window.location.reload();
                        }, 500);
                    } else {
                        // Otherwise update UI without reload
                        $button.hide();
                        $addButton.show();
                        
                        if ($status.length) {
                            $status.html('');
                        }
                        
                        if (response.data && response.data.message) {
                            showNotice(response.data.message, 'success');
                        }
                        
                        forceCheckoutRefresh();
                        
                        // Re-enable button
                        $button.prop('disabled', false).text('Remove credit card fee');
                    }
                } else {
                    // Handle error response
                    var errorMsg = 'Fee could not be removed.';
                    if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    showNotice(errorMsg, 'error');
                    
                    // Re-enable button on error
                    $button.prop('disabled', false).text('Remove credit card fee');
                }
            },
            error: function(xhr) {
                var message = 'Error removing fee. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                showNotice(message, 'error');
                
                // Re-enable button on error
                $button.prop('disabled', false).text('Remove credit card fee');
            }
        });
    });
    
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
    
    /**
     * Re-initialize buttons after checkout update
     */
    $body.on('updated_checkout', function() {
        // The buttons should persist through checkout updates
        // This is here in case we need to re-initialize anything
    });
    
    /**
     * Handle payment method change
     */
    $body.on('change', 'input[name="payment_method"]', function() {
        // Optional: You could automatically remove the fee when switching away from pos_cash
        // Uncomment the following if desired:
        /*
        if ($(this).val() !== 'pos_cash' && $('.wcpos-remove-cc-fee-btn').is(':visible')) {
            $('.wcpos-remove-cc-fee-btn').trigger('click');
        }
        */
    });
});

