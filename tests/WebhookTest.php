<?php
/**
 * Monokulo's webhooks, posted to `/wp-json/monokulo/v1/webhook` as
 * Monokulo posts them: refused unless signed, applied once, and each event
 * moving the WooCommerce order.
 *
 * @package Monokulo
 */

declare(strict_types=1);

class WebhookTest extends MonokuloTestCase {

	public function set_up(): void {
		parent::set_up();
		self::connected_gateway();
	}

	public function test_a_signed_paid_event_marks_the_order_paid() {
		$order = self::order_paying( 'mko_paid' );
		$this->assertSame( 200, $this->deliver( self::event( array( 'event' => 'order.paid', 'order_id' => 'mko_paid' ) ) ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_an_unsigned_or_wrongly_signed_delivery_changes_nothing() {
		$order = self::order_paying( 'mko_forged' );
		$body  = self::event( array( 'event' => 'order.paid', 'order_id' => 'mko_forged' ) );
		$good  = monokulo_test_signature( $body, self::WEBHOOK_SECRET );
		foreach ( array(
			'none'         => '',
			'one off'      => substr( $good, 0, -1 ) . ( str_ends_with( $good, '0' ) ? '1' : '0' ),
			'other secret' => monokulo_test_signature( $body, 'whsec_other' ),
			'replayed'     => monokulo_test_signature( $body, self::WEBHOOK_SECRET, time() - 301 ),
			'from ahead'   => monokulo_test_signature( $body, self::WEBHOOK_SECRET, time() + 301 ),
			'malformed'    => 'v1=' . str_repeat( 'a', 64 ),
		) as $case => $signature ) {
			$this->assertSame( 401, $this->deliver( $body, $signature ), $case );
		}
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_without_a_signing_secret_every_delivery_is_refused() {
		self::connected_gateway( array( 'webhook_signing_secret' => '' ) );
		$body = self::event( array( 'event' => 'order.paid', 'order_id' => 'mko_x' ) );
		$this->assertSame( 401, $this->deliver( $body, monokulo_test_signature( $body, '' ) ) );
	}

	public function test_a_signed_body_that_is_not_an_event_is_a_bad_request() {
		$this->assertSame( 400, $this->deliver( 'not json' ) );
		$this->assertSame( 400, $this->deliver( (string) wp_json_encode( array( 'event' => 'order.paid' ) ) ) );
		$this->assertSame( 400, $this->deliver( (string) wp_json_encode( array( 'event' => 'order.paid', 'event_id' => 'evt_1', 'order_id' => 7 ) ) ) );
	}

	public function test_an_event_for_an_order_this_shop_does_not_have_is_not_found() {
		$this->assertSame( 404, $this->deliver( self::event( array( 'event' => 'order.paid', 'order_id' => 'mko_elsewhere' ) ) ) );
	}

	public function test_a_repeated_delivery_is_taken_and_changes_nothing() {
		$order = self::order_paying( 'mko_again' );
		$body  = self::event( array( 'event' => 'order.expired', 'order_id' => 'mko_again' ) );
		$this->assertSame( 200, $this->deliver( $body ) );
		$notes = self::notes( wc_get_order( $order->get_id() ) );

		$this->assertSame( 200, $this->deliver( $body ) );
		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( $notes, self::notes( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public function status_events(): array {
		return array(
			'order.pending'     => array( 'order.pending', array( 'pending' ) ),
			'order.unconfirmed' => array( 'order.unconfirmed', array( 'on-hold' ) ),
			'order.confirming'  => array( 'order.confirming', array( 'on-hold' ) ),
			'order.partial'     => array( 'order.partial', array( 'on-hold' ) ),
			'order.paid'        => array( 'order.paid', array( 'processing', 'completed' ) ),
			'order.overpaid'    => array( 'order.overpaid', array( 'processing', 'completed' ) ),
			'order.expired'     => array( 'order.expired', array( 'cancelled' ) ),
		);
	}

	/**
	 * @dataProvider status_events
	 */
	public function test_each_status_event_moves_an_unpaid_order( string $event, array $statuses ) {
		$order = self::order_paying( 'mko_status', 'on-hold' === $statuses[0] ? 'pending' : 'on-hold' );
		$this->assertSame( 200, $this->deliver( self::event( array( 'event' => $event, 'order_id' => 'mko_status' ) ) ) );
		$this->assertContains( wc_get_order( $order->get_id() )->get_status(), $statuses );
	}

	public function test_notes_say_what_the_merchant_must_handle() {
		$cases = array(
			'order.confirming' => 'waiting for confirmations',
			'order.partial'    => 'part of the amount due has arrived',
			'order.paid'       => 'paid in full',
			'order.overpaid'   => 'The excess is not refunded automatically',
			'order.expired'    => 'Any funds received stay at the payment address',
		);
		foreach ( $cases as $event => $note ) {
			$order = self::order_paying( 'mko_' . $event );
			$this->deliver( self::event( array( 'event' => $event, 'order_id' => 'mko_' . $event ) ) );
			$this->assertStringContainsString( $note, self::notes( wc_get_order( $order->get_id() ) ), $event );
		}
	}

	public function test_a_paid_order_records_the_monokulo_order_and_links_to_it() {
		$order = self::order_paying( 'mko_linked' );
		$this->deliver(
			self::event(
				array(
					'event'    => 'order.paid',
					'order_id' => 'mko_linked',
					'store'    => array( 'id' => 'store-1', 'name' => 'Bakery' ),
				)
			)
		);
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'mko_linked', $order->get_transaction_id() );
		$this->assertStringContainsString( 'In Monokulo: https://pay.example.com/stores/store-1/orders/mko_linked', self::notes( $order ) );
	}

	/**
	 * Monokulo's advice: never undo a payment on your own. A later event
	 * leaves a paid order paid and says so.
	 */
	public function test_a_paid_order_stays_paid() {
		foreach ( array( 'order.expired', 'order.partial', 'order.pending', 'order.confirming' ) as $event ) {
			$order = self::order_paying( 'mko_kept_' . $event, 'processing' );
			$this->assertSame( 200, $this->deliver( self::event( array( 'event' => $event, 'order_id' => 'mko_kept_' . $event ) ) ), $event );
			$order = wc_get_order( $order->get_id() );
			$this->assertSame( 'processing', $order->get_status(), $event );
			$this->assertStringContainsString( 'already paid, so its status is left as it is', self::notes( $order ), $event );
		}
	}

	/**
	 * After the total changed (or the first request expired), the customer
	 * was sent to pay a new Monokulo order: the shop order has two.
	 */
	private static function order_with_an_older_request( string $old, string $new ): WC_Order {
		$order = self::order_paying( $old );
		$order->update_meta_data( WC_Gateway_Monokulo::META_ORDER_ID, $new );
		$order->add_meta_data( WC_Gateway_Monokulo::META_ORDER_IDS, $new );
		$order->save();
		return $order;
	}

	/**
	 * Events other than a payment about an older request are a note, and
	 * never move the order.
	 */
	public function test_an_older_payment_requests_other_events_only_add_a_note() {
		foreach ( array( 'order.expired', 'order.partial', 'order.pending', 'order.unconfirmed', 'order.confirming' ) as $event ) {
			$order = self::order_with_an_older_request( 'mko_old_' . $event, 'mko_new_' . $event );
			$this->assertSame( 200, $this->deliver( self::event( array( 'event' => $event, 'order_id' => 'mko_old_' . $event ) ) ), $event );
			$order = wc_get_order( $order->get_id() );
			$this->assertSame( 'pending', $order->get_status(), $event );
			$this->assertStringContainsString( 'older payment request for this order, so its status is left as it is', self::notes( $order ), $event );
		}
	}

	/**
	 * Money that arrived for any of the order's requests pays it, and the
	 * note says which, for the merchant to check the amount.
	 */
	public function test_a_payment_to_an_older_request_pays_the_order() {
		foreach ( array( 'order.paid', 'order.overpaid' ) as $event ) {
			$order = self::order_with_an_older_request( 'mko_old_' . $event, 'mko_new_' . $event );
			$this->assertSame( 200, $this->deliver( self::event( array( 'event' => $event, 'order_id' => 'mko_old_' . $event ) ) ), $event );
			$order = wc_get_order( $order->get_id() );
			$this->assertTrue( $order->is_paid(), $event );
			$this->assertSame( 'mko_old_' . $event, $order->get_transaction_id(), $event );
			$this->assertStringContainsString( 'an older payment request for this order was paid', self::notes( $order ), $event );
		}
	}

	/**
	 * Once paid, a payment to the order's other request, or more money for
	 * the same one, is a note for the merchant to refund; the order stays
	 * as it is and keeps its first payment's transaction.
	 */
	public function test_a_second_payment_is_a_note() {
		$order = self::order_with_an_older_request( 'mko_first', 'mko_second' );
		$this->deliver( self::event( array( 'event' => 'order.paid', 'order_id' => 'mko_second' ) ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$status = wc_get_order( $order->get_id() )->get_status();

		foreach ( array( array( 'order.paid', 'mko_first' ), array( 'order.overpaid', 'mko_second' ) ) as list( $event, $monokulo_order ) ) {
			$this->assertSame( 200, $this->deliver( self::event( array( 'event' => $event, 'order_id' => $monokulo_order ) ) ), $event );
			$order = wc_get_order( $order->get_id() );
			$this->assertSame( $status, $order->get_status(), $event );
			$this->assertSame( 'mko_second', $order->get_transaction_id(), $event );
			$this->assertStringContainsString( 'already paid, so nothing changes: this is more money for it (Monokulo order ' . $monokulo_order . ')', self::notes( $order ), $event );
		}
	}

	/**
	 * A paid order never goes back, whichever of its requests the event is
	 * about.
	 */
	public function test_a_paid_order_stays_paid_whatever_request_the_event_is_about() {
		$order = self::order_with_an_older_request( 'mko_was', 'mko_is' );
		$this->deliver( self::event( array( 'event' => 'order.paid', 'order_id' => 'mko_was' ) ) );
		foreach ( array( 'mko_was', 'mko_is' ) as $monokulo_order ) {
			foreach ( array( 'order.expired', 'order.pending', 'order.partial' ) as $event ) {
				$this->deliver( self::event( array( 'event' => $event, 'order_id' => $monokulo_order ) ) );
				$this->assertTrue( wc_get_order( $order->get_id() )->is_paid(), "$event for $monokulo_order" );
			}
		}
	}

	/**
	 * Every Monokulo order the shop order has had is known: an event for
	 * any of them finds it, and one for none of them is refused.
	 */
	public function test_every_request_the_order_had_is_recognised() {
		$order = self::order();
		$this->monokulo_serves(
			array(
				'POST https://pay.example.com/api/v1/orders' => self::response( 201, Monokulo_Api_Fixtures::order( 'order_a' ) ),
			)
		);
		$gateway = self::connected_gateway();
		$gateway->process_payment( $order->get_id() );
		$this->monokulo_serves(
			array(
				'POST https://pay.example.com/api/v1/orders' => self::response( 201, Monokulo_Api_Fixtures::order( 'order_b' ) ),
			)
		);
		$gateway->process_payment( $order->get_id() );

		$this->assertSame( 200, $this->deliver( self::event( array( 'event' => 'order.expired', 'order_id' => 'order_a' ) ) ) );
		$this->assertSame( 200, $this->deliver( self::event( array( 'event' => 'order.confirming', 'order_id' => 'order_b' ) ) ) );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 404, $this->deliver( self::event( array( 'event' => 'order.paid', 'order_id' => 'order_c' ) ) ) );
	}

	/**
	 * When applying an event fails, Monokulo is told to send it again, and
	 * the retry applies it.
	 */
	public function test_an_event_that_fails_to_apply_is_retried() {
		$order = self::order_paying( 'mko_retry' );
		$body  = self::event( array( 'event' => 'order.paid', 'order_id' => 'mko_retry' ) );
		$fail  = static function () {
			throw new RuntimeException( 'database went away' );
		};
		add_action( 'woocommerce_pre_payment_complete', $fail );
		$this->assertSame( 500, $this->deliver( $body ) );
		$this->assertTrue( $this->logged( 'could not be applied' ) );
		remove_action( 'woocommerce_pre_payment_complete', $fail );

		$this->assertSame( 200, $this->deliver( $body ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_double_spend_events_add_a_note_and_leave_the_status() {
		$order = self::order_paying( 'mko_ds', 'processing' );
		$this->assertSame( 200, $this->deliver( self::event( array( 'event' => 'order.double_spend_detected', 'order_id' => 'mko_ds', 'txid' => 'aa11' ) ) ) );
		$this->assertSame( 200, $this->deliver( self::event( array( 'event' => 'order.double_spend_reversed', 'order_id' => 'mko_ds', 'txid' => 'bb22' ) ) ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertStringContainsString( 'FRAUD ALERT', self::notes( $order ) );
		$this->assertStringContainsString( 'bb22', self::notes( $order ) );
	}

	public function test_events_from_a_newer_monokulo_are_taken_and_change_nothing() {
		$order = self::order_paying( 'mko_future' );
		foreach ( array( 'order.refunded', 'payment.relabelled' ) as $event ) {
			$this->assertSame( 200, $this->deliver( self::event( array( 'event' => $event, 'order_id' => 'mko_future' ) ) ), $event );
			$this->assertTrue( $this->logged( 'unknown event "' . $event . '"' ), $event );
		}
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_the_delivery_joins_monokulos_trace() {
		self::order_paying( 'mko_traced' );
		$this->deliver(
			self::event( array( 'event' => 'order.confirming', 'order_id' => 'mko_traced' ) ),
			null,
			array( 'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01' )
		);
		$this->assertSame( '0af7651916cd43dd8448eb211c80319c', WC_Gateway_Monokulo::trace_id() );
	}
}
