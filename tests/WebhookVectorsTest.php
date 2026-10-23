<?php
/**
 * The webhook receiver against Monokulo's published test vectors
 * (tests/vectors/webhook-vectors.json, pinned to a monokulo commit in
 * tests/vectors/PIN): one signed delivery per event, and deliveries a
 * receiver must refuse. Monokulo's own tests build the file from the code
 * that sends real webhooks, so passing on it means handling what Monokulo
 * sends.
 *
 * Signatures are checked with the clock at the vectors' `sent_at`, through
 * the private `verify_webhook_signature()`, the way WebhookSignatureTest
 * does. Each delivery's body is then sent through the receiver signed now,
 * as Monokulo would send it today.
 *
 * @package Monokulo
 */
class WebhookVectorsTest extends WP_UnitTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function vectors() {
		return json_decode( (string) file_get_contents( __DIR__ . '/vectors/webhook-vectors.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	public function delivery_provider() {
		$cases = array();
		foreach ( self::vectors()['deliveries'] as $delivery ) {
			$cases[ $delivery['name'] ] = array( $delivery );
		}
		return $cases;
	}

	public function refused_provider() {
		$cases = array();
		foreach ( self::vectors()['refuse'] as $refuse ) {
			$cases[ $refuse['why'] ] = array( $refuse );
		}
		return $cases;
	}

	private function gateway() {
		$seed = new WC_Gateway_Monokulo();
		$seed->update_option( 'webhook_signing_secret', self::vectors()['signing_secret'] );
		return new WC_Gateway_Monokulo();
	}

	private function verify_at_sent_at( WC_Gateway_Monokulo $gateway, array $delivery ) {
		$method = new ReflectionMethod( WC_Gateway_Monokulo::class, 'verify_webhook_signature' );
		return $method->invoke( $gateway, $delivery['body'], $delivery['headers']['X-Monokulo-Signature'], self::vectors()['sent_at'] );
	}

	/**
	 * @dataProvider delivery_provider
	 */
	public function test_each_delivery_verifies( array $delivery ) {
		$this->assertTrue( $this->verify_at_sent_at( $this->gateway(), $delivery ) );
	}

	/**
	 * @dataProvider refused_provider
	 */
	public function test_each_refused_delivery_is_refused( array $refuse ) {
		$this->assertFalse( $this->verify_at_sent_at( $this->gateway(), $refuse ), $refuse['why'] );
	}

	/**
	 * Every delivery's body is taken by the receiver for the order it names,
	 * and moves the WooCommerce order as the status map says.
	 *
	 * @dataProvider delivery_provider
	 */
	public function test_each_delivery_moves_the_order( array $delivery ) {
		$event = json_decode( $delivery['body'], true );
		$order = wc_create_order();
		$order->set_status( 'pending' );
		$order->update_meta_data( WC_Gateway_Monokulo::META_ORDER_ID, $event['order_id'] );
		$order->save();

		$gateway   = $this->gateway();
		$signature = monokulo_test_signature( $delivery['body'], self::vectors()['signing_secret'] );
		$this->assertSame( 200, $gateway->process_webhook_request( $delivery['body'], $signature ) );

		$order = wc_get_order( $order->get_id() );
		switch ( $event['event'] ) {
			case 'order.paid':
			case 'order.overpaid':
				$this->assertTrue( $order->is_paid(), $order->get_status() );
				break;
			case 'order.unconfirmed':
			case 'order.confirming':
			case 'order.partial':
				$this->assertSame( 'on-hold', $order->get_status() );
				break;
			case 'order.expired':
				$this->assertSame( 'cancelled', $order->get_status() );
				break;
			default:
				// Double-spend alerts add a note and leave the status alone.
				$this->assertSame( 'pending', $order->get_status() );
		}

		// The same delivery again changes nothing and is still taken.
		$this->assertSame( 200, $gateway->process_webhook_request( $delivery['body'], $signature ) );
	}
}
