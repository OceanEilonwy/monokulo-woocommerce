<?php
/**
 * The gateway in WooCommerce: registered, off until connected, offered in
 * the Cart and Checkout blocks, compatible with order tables, and removed
 * cleanly.
 *
 * @package Monokulo
 */

declare(strict_types=1);

class GatewayTest extends MonokuloTestCase {

	public function test_woocommerce_lists_the_gateway_turned_off() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertInstanceOf( WC_Gateway_Monokulo::class, $gateways['monokulo'] ?? null );

		$gateway = new WC_Gateway_Monokulo();
		$this->assertSame( 'no', $gateway->enabled );
		$this->assertArrayNotHasKey( 'monokulo', WC()->payment_gateways()->get_available_payment_gateways() );
	}

	public function test_a_store_without_a_whole_connection_is_not_offered() {
		$this->assertTrue( self::connected_gateway()->is_available() );
		$this->assertFalse( self::connected_gateway( array( 'secret_token' => '' ) )->is_available() );
		$this->assertFalse( self::connected_gateway( array( 'enabled' => 'no' ) )->is_available() );
	}

	/**
	 * WooCommerce matches the declaration to the installed plugin
	 * (monokulo/monokulo.php, installed from the zip by scripts/test all).
	 */
	public function test_it_declares_order_tables_and_blocks_support() {
		$features = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
		foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
			$this->assertContains( 'monokulo/monokulo.php', $features->get_compatible_plugins_for_feature( $feature )['compatible'], $feature );
		}
	}

	public function test_the_blocks_checkout_offers_it_with_its_title_and_icon() {
		self::connected_gateway( array( 'title' => 'Monero & co' ) );
		self::rebuild_gateways();
		$registry = Automattic\WooCommerce\Blocks\Package::container()->get( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class );
		$method   = $registry->get_registered( 'monokulo' );
		$this->assertInstanceOf( Monokulo_Blocks_Support::class, $method );

		$method->initialize();
		$this->assertTrue( $method->is_active() );
		$this->assertSame( array( 'monokulo-blocks' ), $method->get_payment_method_script_handles() );
		$this->assertStringEndsWith( '/assets/blocks.js', wp_scripts()->registered['monokulo-blocks']->src );
		$data = $method->get_payment_method_data();
		$this->assertSame( 'Monero & co', html_entity_decode( $data['title'] ), 'Decoded by the script.' );
		$this->assertStringEndsWith( '/assets/monokulo-icon.svg', $data['icon'] );
		$this->assertSame( array( 'products' ), $data['supports'] );
	}

	public function test_the_plugins_page_links_to_its_settings() {
		$links = apply_filters( 'plugin_action_links_' . plugin_basename( MONOKULO_PLUGIN_FILE ), array() );
		$this->assertStringContainsString( 'section=monokulo', $links[0] );
	}

	public function test_deleting_the_plugin_removes_its_settings() {
		self::connected_gateway();
		define( 'WP_UNINSTALL_PLUGIN', 'monokulo/monokulo.php' );
		require dirname( __DIR__ ) . '/uninstall.php';
		$this->assertFalse( get_option( 'woocommerce_monokulo_settings' ) );
	}
}
