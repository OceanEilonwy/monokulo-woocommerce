<?php
/**
 * The receiver against Monokulo's published webhook vectors
 * (tests/vectors/webhook-vectors.json, pinned in tests/vectors/PIN): one
 * signed delivery per event, and deliveries a receiver must refuse.
 * Signatures are checked at the vectors' `sent_at`; each delivery's body
 * then goes through the REST route signed now, as Monokulo would send it
 * today.
 *
 * @package Monokulo
 */

declare(strict_types=1);

class WebhookVectorsTest extends MonokuloTestCase {

	private static function vectors(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/vectors/webhook-vectors.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	public function deliveries(): array {
		$cases = array();
		foreach ( self::vectors()['deliveries'] as $delivery ) {
			$cases[ $delivery['name'] ] = array( $delivery );
		}
		return $cases;
	}

	/**
	 * The deliveries about an order: all but `webhook.test`.
	 */
	public function order_deliveries(): array {
		return array_filter( $this->deliveries(), static fn( array $case ) => 'webhook.test' !== $case[0]['headers']['X-Monokulo-Event'] );
	}

	public function refusals(): array {
		$cases = array();
		foreach ( self::vectors()['refuse'] as $refuse ) {
			$cases[ $refuse['why'] ] = array( $refuse );
		}
		return $cases;
	}

	private static function verifies( array $delivery ): bool {
		$vectors = self::vectors();
		return WC_Gateway_Monokulo::verify_signature( $vectors['signing_secret'], $delivery['body'], $delivery['headers']['X-Monokulo-Signature'], $vectors['sent_at'] );
	}

	/**
	 * @dataProvider deliveries
	 */
	public function test_each_delivery_verifies( array $delivery ) {
		$this->assertTrue( self::verifies( $delivery ) );
	}

	/**
	 * @dataProvider refusals
	 */
	public function test_each_refused_delivery_is_refused( array $refuse ) {
		$this->assertFalse( self::verifies( $refuse ), $refuse['why'] );
	}

	public function test_every_event_in_the_vectors_is_one_the_receiver_knows() {
		$events = array_unique( array_map( static fn( array $case ) => $case[0]['headers']['X-Monokulo-Event'], array_values( $this->deliveries() ) ) );
		$this->assertContains( 'webhook.test', $events );
		$this->assertSame( array(), array_values( array_diff( $events, WC_Gateway_Monokulo::ORDER_EVENTS, array( 'webhook.test' ) ) ) );
	}

	/**
	 * The test delivery is taken, kept as the last test received, and
	 * changes no order.
	 */
	public function test_the_test_delivery_changes_no_order() {
		$delivery = array_values( array_diff_key( $this->deliveries(), $this->order_deliveries() ) )[0][0];
		$order    = self::order_paying( 'order_1' );
		self::connected_gateway();

		$this->assertSame( 200, $this->deliver( $delivery['body'] ) );
		$this->assertSame( $delivery['headers']['X-Monokulo-Event-Id'], get_option( WC_Gateway_Monokulo::LAST_TEST_OPTION )['event_id'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', self::notes( $order ) );
		$this->assertSame( 200, $this->deliver( $delivery['body'] ), 'Again: taken.' );
	}

	/**
	 * @dataProvider order_deliveries
	 */
	public function test_each_delivery_moves_the_order( array $delivery ) {
		$event = json_decode( $delivery['body'], true );
		$order = self::order_paying( $event['order_id'] );
		self::connected_gateway();

		$this->assertSame( 200, $this->deliver( $delivery['body'] ) );

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
			case 'order.cancelled':
				$this->assertSame( 'cancelled', $order->get_status() );
				break;
			default:
				$this->assertSame( 'pending', $order->get_status(), 'A double-spend note leaves the status.' );
		}
		$this->assertStringContainsString( 'In Monokulo: ' . $event['_links']['mk:dashboard-order']['href'], self::notes( $order ), 'Every delivery links the order\'s dashboard page.' );
		$this->assertSame( 200, $this->deliver( $delivery['body'] ), 'Again: taken, changes nothing.' );
	}
}
