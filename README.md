# Duitku Payment Gateway for WooCommerce

A WooCommerce payment gateway plugin for Duitku that displays Virtual Account numbers directly on the checkout page.

## Description

This plugin integrates Duitku payment gateway with WooCommerce, allowing customers to pay using:
- Virtual Account (BNI, BRI, Mandiri, BSI, CIMB Niaga, Permata Bank)
- Retail (Alfamart)
- QRIS (ShopeePay, NobuBank)

Key features:
- Displays payment information directly on the checkout page (no redirect)
- Automatic payment status checking
- Configurable expiry period
- Detailed logging for troubleshooting
- Supports both production and development environments

## Requirements

- WordPress 6.8 or higher
- WooCommerce 9.8 or higher
- PHP 8.0 or higher
- SSL certificate installed

## Installation

1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Click "Install Now" and then "Activate"

## Configuration

1. Go to WooCommerce > Settings > Duitku Settings
2. Enter your Merchant Code and API Key (obtain these from your Duitku dashboard)
3. Select Environment Mode (Development/Production)
4. Set the Global Expiry Period (in minutes)
5. Enable/disable logging as needed
6. Save changes

The Callback URL will be displayed on the settings page. Copy this URL and set it in your Duitku merchant dashboard.

## Payment Methods

The following payment methods are supported:

### Virtual Account
- BNI (Code: I1)
- BRI (Code: BR)
- Mandiri (Code: M2)
- BSI (Code: BV)
- CIMB Niaga (Code: B1)
- Permata Bank (Code: BT)

### Retail
- Alfamart (Code: FT)

### QRIS
- ShopeePay (Code: SP)
- NobuBank (Code: DQ)

## Usage

1. When a customer selects a payment method and places an order:
   - For Virtual Account payments: The VA number is displayed immediately
   - For QRIS payments: A QR code is displayed for scanning
   - For Retail payments: A payment code is displayed

2. The payment status is checked automatically every 3 seconds
   - When payment is received, the customer is redirected to the order completion page
   - If payment expires, the order status changes to "Cancelled"

## Logging

Payment processing logs are stored in WooCommerce > Status > Logs with the prefix 'duitku-pg'.

## Support

For support or bug reports, please contact:
- Email: support@sgnet.co.id
- Website: https://sgnet.co.id

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by Pradja DJ for SGNet.co.id

## Changelog

### 1.0
- Initial release
- Support for Virtual Account payments
- Support for Retail payments
- Support for QRIS payments
- Automatic payment status checking
- Configurable expiry period
- Payment logging system
