<?php
/**
 * Boots WordPress's PHPUnit test library with WooCommerce and this plugin
 * loaded, in the WordPress container (scripts/test wordpress), where
 * WP_TESTS_DIR is /wordpress-phpunit.
 *
 * @package Monokulo
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir || ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress's test library isn't at WP_TESTS_DIR: run the tests with scripts/test wordpress.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads WooCommerce, then the plugin, as WordPress would load them active.
 */
function _monokulo_manually_load_plugins() {
	require WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
	require dirname( __DIR__ ) . '/monokulo.php';
}
tests_add_filter( 'muplugins_loaded', '_monokulo_manually_load_plugins' );

/**
 * Installs WooCommerce's tables, options and roles in the test database, as
 * activating it would, then reloads the roles.
 */
function _monokulo_install_woocommerce() {
	WC_Install::install();
	$GLOBALS['wp_roles'] = null;
	wp_roles();
}
tests_add_filter( 'setup_theme', '_monokulo_install_woocommerce' );

/**
 * The `X-Monokulo-Signature` Monokulo sends for `$body`, signed at
 * `$timestamp` (now by default).
 *
 * @param string   $body      The raw request body.
 * @param string   $secret    The webhook signing secret.
 * @param int|null $timestamp The signing time.
 * @return string
 */
function monokulo_test_signature( $body, $secret, $timestamp = null ) {
	$timestamp = null === $timestamp ? time() : $timestamp;
	return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
}

require $_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/fixtures/class-monokulo-api-fixtures.php';
require __DIR__ . '/MonokuloTestCase.php';
