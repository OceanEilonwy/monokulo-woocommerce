<?php
/**
 * The payment method in the Cart and Checkout blocks.
 *
 * @package Monokulo
 */

declare(strict_types=1);

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hands the blocks checkout the gateway's title, description and icon, and
 * its script (assets/blocks.js). The order itself goes through the
 * gateway's process_payment(), as on the classic checkout.
 */
final class Monokulo_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * The payment method's id, the gateway's.
	 *
	 * @var string
	 */
	protected $name = WC_Gateway_Monokulo::ID;

	/**
	 * Reads the gateway's saved settings.
	 */
	public function initialize(): void {
		$settings       = get_option( 'woocommerce_monokulo_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether the merchant turned the payment method on. Whether it is
	 * offered for a given cart is the gateway's is_available().
	 */
	public function is_active(): bool {
		return 'yes' === $this->get_setting( 'enabled', 'no' );
	}

	/**
	 * The script that registers the payment method in the blocks.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'monokulo-blocks',
			plugins_url( 'assets/blocks.js', MONOKULO_PLUGIN_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			WC_Gateway_Monokulo::VERSION,
			true
		);
		return array( 'monokulo-blocks' );
	}

	/**
	 * What the script shows: `monokulo_data` in wcSettings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		$gateway = monokulo_gateway();
		return array(
			'title'       => $gateway ? $gateway->get_title() : '',
			'description' => $gateway ? $gateway->get_description() : '',
			'icon'        => $gateway ? (string) $gateway->icon : '',
			'supports'    => $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array(),
		);
	}
}
