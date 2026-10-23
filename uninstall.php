<?php
/**
 * Removes the plugin's settings, the API entry it kept and the version
 * its upgrade last ran for, when it is
 * deleted. Orders keep their
 * Monokulo order ids and notes. The store, its keys and its webhook stay
 * in Monokulo until the shop is disconnected there. Connect nonces expire
 * on their own within ten minutes.
 *
 * @package Monokulo
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_monokulo_settings' );
delete_option( 'monokulo_api_entry' );
delete_option( 'monokulo_version' );
