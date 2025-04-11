# OctaHexa Product Reservation Lock

**Version: 1.0.0**

A WooCommerce plugin that locks products during checkout to prevent duplicate sales of unique items.

## Description

OctaHexa Product Reservation Lock provides a robust solution for WooCommerce stores selling unique or one-of-a-kind products. This plugin helps prevent the frustrating scenario where multiple customers attempt to purchase the same unique product simultaneously.

### Key Features

- **Product Reservation**: Locks products when customers proceed to checkout
- **Timed Reservation**: Automatically releases products after a configurable time period
- **User Notifications**: Optional warning messages about product reservation
- **Admin Controls**: Monitor and manually release product locks
- **Compatible**: Works with WooCommerce's built-in "Hold Stock" feature
- **Variable Products**: Supports variation-specific product locking

## Installation

1. Upload the `octahexa-product-reservation-lock` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to WooCommerce → Product Reservation to configure settings

## Configuration

### Plugin Settings

1. Go to WooCommerce → Product Reservation
2. Set the **Lock Duration** (in minutes) - this determines how long products remain locked during checkout
3. Choose whether to **Show Reservation Warning** to customers

### WooCommerce Integration

For maximum protection, we recommend also configuring WooCommerce's built-in stock holding feature:

1. Go to WooCommerce → Settings → Products → Inventory
2. Enable "Hold Stock" and set it to the same duration as configured in the plugin settings (recommended: 10 minutes)

## How It Works

1. When a customer adds a product to their cart, the plugin checks if it's already reserved by another customer
2. When the customer proceeds to checkout, the product is locked for the configured duration
3. If the order is completed within the lock period, the product remains locked until delivery
4. If the checkout is abandoned, the lock is automatically released after the configured time

## Admin Features

- **Product Edit Screen**: See active locks directly on the product edit page
- **Manual Release**: Ability to release locks manually if needed
- **Products List**: Quick view of lock status in the products list

## Developer Notes

This plugin creates a custom database table (`oh_product_reservation_locks`) to track product locks. It's designed to be lightweight and efficient, only locking products at the checkout stage to minimize impact on the shopping experience.

## Compatibility

- WordPress 5.6+
- WooCommerce 3.0+
- Compatible with most WooCommerce themes and plugins

## Support

For support, feature requests, or bug reports, please contact OctaHexa at https://octahexa.com/contact/

## License

GPLv2 or later
https://www.gnu.org/licenses/gpl-2.0.html
