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
		$this->assertStringContainsString( 'In Monokulo: https://pay.example.com/dashboard/stores/store-1/orders/mko_linked', self::notes( $order ) );
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
	 * After the total changed, the customer was sent to pay a new Monokulo
	 * order. Events about the old one tell the merchant and leave the order.
	 */
	public function test_an_older_payment_request_never_moves_the_order() {
		$order = self::order_paying( 'mko_old' );
		$order->update_meta_data( WC_Gateway_Monokulo::META_ORDER_ID, 'mko_new' );
		$order->add_meta_data( WC_Gateway_Monokulo::META_ORDER_IDS, 'mko_new' );
		$order->save();

		$this->assertSame( 200, $this->deliver( self::event( array( 'event' => 'order.expired', 'order_id' => 'mko_old' ) ) ) );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( 'older payment request', self::notes( wc_get_order( $order->get_id() ) ) );

		$this->assertSame( 200, $this->deliver( self::event( array( 'event' => 'order.paid', 'order_id' => 'mko_old' ) ) ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
		$this->assertStringContainsString( 'Check the amount received', self::notes( $order ) );

		$this->assertSame( 200, $this->deliver( self::event( array( 'event' => 'order.paid', 'order_id' => 'mko_new' ) ) ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
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
