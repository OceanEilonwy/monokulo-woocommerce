<?php
/**
 * Removes the plugin's settings when it is deleted. Orders keep their
 * Monokulo order ids and notes, which they refer to. The store, its keys
 * and its webhook stay in Monokulo until disconnected there.
 *
 * @package Monokulo
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_monokulo_settings' );
delete_transient( 'monokulo_connect_nonce_monokulo' );
