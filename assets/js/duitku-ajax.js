jQuery(document).ready(function($) {
    // Only run on the order-received page with pending Duitku payments
    if ($('.duitku-payment-details').length === 0) {
        return;
    }

    var checkPayment = function() {
        var orderId = $('input[name="order_id"]').val();
        
        if (!orderId) {
            console.error('Order ID not found');
            return;
        }

        $.ajax({
            url: duitkuAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'duitku_check_payment',
                order_id: orderId,
                nonce: duitkuAjax.nonce
            },
            success: function(response) {
                if (!response.success) {
                    console.error('Payment check failed:', response.data.message);
                    return;
                }

                var data = response.data;

                // Update status message
                $('.duitku-status p').text(data.message);

                // Handle different statuses
                switch (data.status) {
                    case 'completed':
                        // Stop checking and redirect to thank you page
                        clearInterval(paymentChecker);
                        if (data.redirect_url) {
                            window.location.href = data.redirect_url;
                        }
                        break;

                    case 'cancelled':
                    case 'expired':
                        // Stop checking and redirect to checkout
                        clearInterval(paymentChecker);
                        if (data.redirect_url) {
                            window.location.href = data.redirect_url;
                        }
                        break;

                    case 'pending':
                        // Continue checking
                        break;

                    default:
                        console.error('Unknown payment status:', data.status);
                        break;
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
            }
        });
    };

    // Start checking payment status every 3 seconds
    var paymentChecker = setInterval(checkPayment, duitkuAjax.checkInterval);

    // Initial check
    checkPayment();
});
