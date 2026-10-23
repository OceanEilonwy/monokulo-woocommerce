<?php
/**
 * Plugin Name:       Monokulo for WooCommerce
 * Plugin URI:        https://github.com/OceanEilonwy/monokulo-woocommerce
 * Description:       Accept Monero at checkout via Monokulo, the hosted,
 *                     non-custodial payment gateway built on scanner.
 *                     No spend key ever touches this server or ours - only a
 *                     view key, used to watch for incoming payments.
 * Version:            0.3.0
 * Requires at least:  6.4
 * Requires PHP:       8.1
 * Requires Plugins:   woocommerce
 * WC requires at least: 8.0
 * Author:             Monokulo
 * Author URI:         https://github.com/monokulo
 * License:            GPL-2.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         monokulo
 * Domain Path:         /languages
 *
 * @package Monokulo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the gateway once WooCommerce, whose class it extends, has loaded.
 */
function monokulo_init_gateway_class() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once __DIR__ . '/includes/class-wc-gateway-monokulo.php';
}
add_action( 'plugins_loaded', 'monokulo_init_gateway_class' );

/**
 * Adds the gateway to WooCommerce's payment methods.
 *
 * @param string[] $gateways Gateway class names or instances.
 * @return string[]
 */
function monokulo_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Gateway_Monokulo';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'monokulo_add_gateway_class' );

/**
 * Builds WooCommerce's gateways when the connect flow comes back to
 * `admin-post.php?action=monokulo_connect_return`, so the gateway's handler
 * for it is hooked. Nothing else on an admin-post request builds them.
 */
function monokulo_load_gateways_for_connect_return() {
	if ( function_exists( 'WC' ) ) {
		WC()->payment_gateways();
	}
}
add_action( 'admin_post_monokulo_connect_return', 'monokulo_load_gateways_for_connect_return', 0 );
