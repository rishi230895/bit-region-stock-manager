# bit-region-stock-manager

Version: 1.0.0

Description: Adds region-specific stock fields (stock_usa / stock_eu) to products and variations, validates stock at checkout, decrements on order processing/completion, restores on refund/failure/cancel, and provides a REST endpoint for quick inspection.


# Features

Admin UI fields for Stock USA and Stock EU on:

Simple products (Inventory tab)

Each product variation

Checkout validation: prevents checkout when required region stock is insufficient (based on shipping country).

Atomic-like updates: uses SQL arithmetic (GREATEST, CAST) to reduce race-condition risk when decrementing/restoring post meta.

Records exact decrements per order in _brsm_stock_changes order meta to avoid double-decrements and to allow precise restores.

Restores region stock automatically when orders are failed, cancelled, or partially/fully refunded (configurable/extendable).


# Installation
Create plugin folder:
wp-content/plugins/woo-region-stock-manager/
Add the plugin PHP file woo-region-stock-manager.php (paste the provided plugin code).
Activate plugin: WP Admin → Plugins → Activate.
