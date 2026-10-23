<?php
/**
 * Plugin Name:          Monokulo for WooCommerce
 * Plugin URI:           https://github.com/OceanEilonwy/monokulo-woocommerce
 * Description:          Take Monero at checkout through Monokulo, a non-custodial Monero payment processor that only ever holds a view key.
 * Version:              0.4.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.2
 * WC tested up to:      11.2
 * Author:               Monokulo
 * Author URI:           https://github.com/OceanEilonwy/monokulo
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          monokulo
 *
 * @package Monokulo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MONOKULO_PLUGIN_FILE = __FILE__;

/**
 * Declares support for WooCommerce's order tables (HPOS) and the Cart and
 * Checkout blocks.
 */
function monokulo_declare_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MONOKULO_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MONOKULO_PLUGIN_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'monokulo_declare_compatibility' );

/**
 * Loads the gateway once WooCommerce, whose class it extends, has loaded.
 */
function monokulo_load(): void {
	if ( class_exists( 'WC_Payment_Gateway' ) ) {
		require_once __DIR__ . '/includes/class-wc-gateway-monokulo.php';
	}
}
add_action( 'plugins_loaded', 'monokulo_load' );

/**
 * Runs the gateway's upgrade once WooCommerce is up (once per version).
 */
function monokulo_upgrade(): void {
	if ( class_exists( 'WC_Gateway_Monokulo' ) ) {
		WC_Gateway_Monokulo::upgrade();
	}
}
add_action( 'woocommerce_init', 'monokulo_upgrade' );

/**
 * Adds the gateway to WooCommerce's payment methods.
 *
 * @param array $gateways Gateway class names or instances.
 * @return array
 */
function monokulo_add_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_Monokulo';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'monokulo_add_gateway' );

/**
 * The gateway as WooCommerce built it, or null without WooCommerce.
 */
function monokulo_gateway(): ?WC_Gateway_Monokulo {
	if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Gateway_Monokulo' ) ) {
		return null;
	}
	$gateway = WC()->payment_gateways()->payment_gateways()[ WC_Gateway_Monokulo::ID ] ?? null;
	return $gateway instanceof WC_Gateway_Monokulo ? $gateway : null;
}

/**
 * Where the connect flow comes back to:
 * `admin-post.php?action=monokulo_connect_return`.
 */
function monokulo_connect_return(): void {
	monokulo_gateway()?->handle_connect_return();
}
add_action( 'admin_post_monokulo_connect_return', 'monokulo_connect_return' );

/**
 * Monokulo's webhooks: `POST /wp-json/monokulo/v1/webhook`.
 */
function monokulo_register_webhook_route(): void {
	register_rest_route(
		'monokulo/v1',
		'/webhook',
		array(
			'methods'             => 'POST',
			'callback'            => 'monokulo_webhook',
			// A delivery proves itself with its signature, not a WordPress login.
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'monokulo_register_webhook_route' );

/**
 * Answers one webhook delivery. Monokulo reads only the status.
 *
 * @param WP_REST_Request $request The delivery.
 */
function monokulo_webhook( WP_REST_Request $request ): WP_REST_Response {
	$gateway = monokulo_gateway();
	if ( null === $gateway ) {
		return new WP_REST_Response( null, 503 );
	}
	WC_Gateway_Monokulo::adopt_traceparent( (string) $request->get_header( 'traceparent' ) );
	$status = $gateway->process_webhook( $request->get_body(), (string) $request->get_header( 'x_monokulo_signature' ) );
	return new WP_REST_Response( null, $status );
}

/**
 * Offers the payment method in the Cart and Checkout blocks.
 *
 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry The blocks' payment methods.
 */
function monokulo_register_blocks_support( $registry ): void {
	if ( class_exists( 'WC_Gateway_Monokulo' ) ) {
		require_once __DIR__ . '/includes/class-monokulo-blocks-support.php';
		$registry->register( new Monokulo_Blocks_Support() );
	}
}
add_action( 'woocommerce_blocks_payment_method_type_registration', 'monokulo_register_blocks_support' );

/**
 * Says how connecting went, on the gateway's settings page.
 */
function monokulo_connect_notice(): void {
	if ( class_exists( 'WC_Gateway_Monokulo' ) ) {
		WC_Gateway_Monokulo::render_connect_notice();
	}
}
add_action( 'admin_notices', 'monokulo_connect_notice' );

/**
 * A Settings link beside the plugin on the Plugins page.
 *
 * @param array $links The plugin's action links.
 * @return array
 */
function monokulo_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=monokulo' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'monokulo' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( MONOKULO_PLUGIN_FILE ), 'monokulo_action_links' );
