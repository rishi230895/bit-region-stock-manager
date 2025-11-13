<?php

/**
 * Plugin Name: Bit Region Stock Manager
 * Description: Adds region-specific stock fields (USA/EU), validates at checkout, updates on order completion, and restores on refund. Works for simple and variable products.
 * Version: 1.0.0
 * Author: Believin-Technology Pvt LTD.
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/** Code is written for add meta fields in admin product inventory...  */


function brsm_add_custom_stock_fields() {
    global $post;
    echo '<div class="options_group">';
    woocommerce_wp_text_input( array(
        'id'                => 'stock_usa',
        'label'             => __( 'Stock USA', 'woo-region-stock-manager' ),
        'description'       => __( 'Enter stock quantity available for USA orders.', 'woo-region-stock-manager' ),
        'type'              => 'number',
        'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
    ) );

    woocommerce_wp_text_input( array(
        'id'                => 'stock_eu',
        'label'             => __( 'Stock EU', 'woo-region-stock-manager' ),
        'description'       => __( 'Enter stock quantity available for non-USA orders (EU/Other).', 'woo-region-stock-manager' ),
        'type'              => 'number',
        'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
    ) );
    echo '</div>';
}
add_action( 'woocommerce_product_options_inventory_product_data', 'brsm_add_custom_stock_fields' );


/** Code is written for update post meta ....*/

function brsm_save_custom_stock_fields( $post_id ) {
    $stock_usa = isset( $_POST['stock_usa'] ) ? intval( $_POST['stock_usa'] ) : 0;
    $stock_eu  = isset( $_POST['stock_eu'] ) ? intval( $_POST['stock_eu'] ) : 0;
    update_post_meta( $post_id, 'stock_usa', $stock_usa );
    update_post_meta( $post_id, 'stock_eu', $stock_eu );
} 
add_action( 'woocommerce_process_product_meta', 'brsm_save_custom_stock_fields' );

/** Code is written for add fields for each variation... */

function brsm_add_variation_stock_fields( $loop, $variation_data, $variation ) {
	woocommerce_wp_text_input( array(
		'id'                => "stock_usa_{$loop}",
		'name'              => "stock_usa[{$variation->ID}]",
		'value'             => get_post_meta( $variation->ID, 'stock_usa', true ),
		'label'             => __( 'Stock USA', 'woo-region-stock-manager' ),
		'type'              => 'number',
		'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
	) );

	woocommerce_wp_text_input( array(
		'id'                => "stock_eu_{$loop}",
		'name'              => "stock_eu[{$variation->ID}]",
		'value'             => get_post_meta( $variation->ID, 'stock_eu', true ),
		'label'             => __( 'Stock EU', 'woo-region-stock-manager' ),
		'type'              => 'number',
		'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
	) );
}
add_action( 'woocommerce_variation_options_pricing', 'brsm_add_variation_stock_fields', 10, 3 );


/** Code is written for save variation fields... */

function brsm_save_variation_stock_fields( $variation_id, $i ) {
	if ( isset( $_POST['stock_usa'][ $variation_id ] ) ) {
		update_post_meta( $variation_id, 'stock_usa', intval( $_POST['stock_usa'][ $variation_id ] ) );
	}
	if ( isset( $_POST['stock_eu'][ $variation_id ] ) ) {
		update_post_meta( $variation_id, 'stock_eu', intval( $_POST['stock_eu'][ $variation_id ] ) );
	}
}
add_action( 'woocommerce_save_product_variation', 'brsm_save_variation_stock_fields', 10, 2 );


/**
 * Strong validation using woocommerce_after_checkout_validation
 * This will add validation errors to the $errors object so order placement is stopped.
 */
function brsm_validate_region_stock_at_checkout( $data, $errors ) {
    // prefer shipping country from posted data, fallback to WC customer, then to billing country
    $shipping_country = '';
    if ( ! empty( $data['shipping_country'] ) ) {
        $shipping_country = sanitize_text_field( $data['shipping_country'] );
    } elseif ( WC()->customer ) {
        $shipping_country = WC()->customer->get_shipping_country();
    }

    // fallback to billing country if no shipping (virtual products / shipping not required)
    if ( empty( $shipping_country ) && ! empty( $data['billing_country'] ) ) {
        $shipping_country = sanitize_text_field( $data['billing_country'] );
    }

    $region_key = ( strtoupper( $shipping_country ) === 'US' ) ? 'stock_usa' : 'stock_eu';

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        $product_id   = $cart_item['product_id'];
        $variation_id = isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0;
        $qty          = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
        $target_id    = $variation_id ?: $product_id;

        $stock_meta = get_post_meta( $target_id, $region_key, true );

        // ensure numeric stock; treat empty/invalid as 0
        $stock = is_numeric( $stock_meta ) ? (int) $stock_meta : 0;

        if ( $stock < $qty ) {
            // Add error to $errors object — this reliably blocks order placement
            $errors->add(
                'brsm_stock_error_' . $target_id,
                sprintf(
                    /* translators: 1: product name, 2: country, 3: available stock */
                    __( 'Sorry, not enough stock for "%1$s" for region %2$s. Available: %3$d. Please adjust quantity or remove the product.', 'woo-region-stock-manager' ),
                    get_the_title( $target_id ),
                    strtoupper( $shipping_country ? $shipping_country : 'N/A' ),
                    $stock
                )
            );
        }
    }
}
add_action( 'woocommerce_after_checkout_validation', 'brsm_validate_region_stock_at_checkout', 10, 2 );





/** Code is written for order completion decrement stock... */

function brsm_handle_order_completed( $order_id ) {

	$order = wc_get_order( $order_id );

	if ( ! $order ) {
        return;
    }

	$country = $order->get_shipping_country();
	$key = ( strtoupper( $country ) === 'US' ) ? 'stock_usa' : 'stock_eu';

	global $wpdb;
    
	foreach ( $order->get_items() as $item ) {
		$pid = $item->get_product_id();
		$vid = $item->get_variation_id();
		$qty = (int) $item->get_quantity();
		$id  = $vid ?: $pid;

		$wpdb->query( $wpdb->prepare("
			UPDATE {$wpdb->postmeta}
			SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) - %d)
			WHERE post_id = %d AND meta_key = %s
		", $qty, $id, $key) );
	}
}
add_action( 'woocommerce_order_status_completed', 'brsm_handle_order_completed' );


/** Code is written for written restore stock... */

function brsm_handle_order_refunded( $order_id, $refund_id = 0 ) {

	$order = wc_get_order( $order_id );

	if ( ! $order ) {
        return;
    }

	$country = $order->get_shipping_country();
	$key = ( strtoupper( $country ) === 'US' ) ? 'stock_usa' : 'stock_eu';
	global $wpdb;

	$items = $refund_id ? wc_get_order( $refund_id )->get_items() : $order->get_items();

	foreach ( $items as $item ) {
		$pid = $item->get_product_id();
		$vid = $item->get_variation_id();
		$qty = (int) $item->get_quantity();
		$id  = $vid ?: $pid;

		$wpdb->query( $wpdb->prepare("
			UPDATE {$wpdb->postmeta}
			SET meta_value = CAST(meta_value AS SIGNED) + %d
			WHERE post_id = %d AND meta_key = %s
		", $qty, $id, $key) );
	}
}
add_action( 'woocommerce_order_refunded', 'brsm_handle_order_refunded', 10, 2 );

