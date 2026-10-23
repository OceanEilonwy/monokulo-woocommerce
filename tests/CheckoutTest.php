<?php
/**
 * Paying with Monero: `process_payment()` reads the API entry with the
 * store's secret key, makes the order with its `create-order` form, and
 * sends the customer to the order's `mk:checkout` link.
 *
 * @package Monokulo
 */

declare(strict_types=1);

class CheckoutTest extends MonokuloTestCase {

	const ORDERS = 'https://pay.example.com/api/v1/orders';

	private static function created( string $order_id = 'order_1' ): array {
		return self::response( 201, Monokulo_Api_Fixtures::order( $order_id ) );
	}

	public function test_placing_an_order_creates_it_on_monokulo_and_sends_the_customer_to_pay() {
		$order = self::order();
		$this->monokulo_serves( array( 'POST ' . self::ORDERS => self::created( 'order_deadbeef' ) ) );

		$result = self::connected_gateway()->process_payment( $order->get_id() );

		$this->assertSame( array( self::API, self::ORDERS ), wp_list_pluck( $this->sent, 'url' ), 'The entry, then its create-order form.' );
		foreach ( $this->sent as $sent ) {
			$this->assertSame( 'Bearer sk_test', $sent['args']['headers']['Authorization'] );
			$this->assertSame( 'woocommerce/' . WC_Gateway_Monokulo::VERSION, $sent['args']['headers']['Monokulo-Client'] );
			$this->assertStringContainsString( 'application/prs.hal-forms+json', $sent['args']['headers']['Accept'] );
			$this->assertMatchesRegularExpression( '/\A00-' . WC_Gateway_Monokulo::trace_id() . '-[0-9a-f]{16}-01\z/', $sent['args']['headers']['traceparent'] );
		}
		$this->assertSame( 'GET', $this->sent[0]['args']['method'] );
		$sent = $this->sent[1];
		$this->assertSame( 'POST', $sent['args']['method'] );
		$this->assertSame( 'application/json', $sent['args']['headers']['Content-Type'] );
		$this->assertSame(
			array(
				'amount'                 => '42.5',
				'currency'               => 'USD',
				'merchant_order_id'      => (string) $order->get_id(),
				'show_merchant_order_id' => false,
				'description'            => 'Order #' . $order->get_order_number(),
				'idempotency_key'        => 'wc:' . $order->get_id() . ':42.5:USD',
			),
			json_decode( $sent['args']['body'], true )
		);

		$this->assertSame(
			array(
				'result'   => 'success',
				'redirect' => 'https://pay.example.com/checkout/order_deadbeef',
			),
			$result
		);
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'order_deadbeef', $order->get_meta( WC_Gateway_Monokulo::META_ORDER_ID ) );
		$this->assertSame( 'pending', $order->get_status(), 'Not paid until Monokulo says so.' );
		$this->assertStringContainsString( 'sent to pay here: https://pay.example.com/checkout/order_deadbeef', self::notes( $order ) );
	}

	public function test_the_entry_is_read_once_and_kept() {
		$this->monokulo_serves();
		$gateway = self::connected_gateway();
		$gateway->process_payment( self::order()->get_id() );
		$gateway->process_payment( self::order()->get_id() );
		( new WC_Gateway_Monokulo() )->process_payment( self::order()->get_id() );

		$this->assertCount( 1, $this->sent_to( self::API ) );
		$this->assertCount( 3, $this->sent_to( self::ORDERS ) );
	}

	/**
	 * Monokulo's links are followed where they point, resolved against the
	 * URL they were read at: nothing past the entry is built by the plugin.
	 */
	public function test_links_and_forms_are_followed_where_they_point() {
		$entry = Monokulo_Api_Fixtures::entry();
		$entry['_templates']['create-order']['target'] = 'https://elsewhere.example:8443/v1/make';
		$made  = Monokulo_Api_Fixtures::order( 'order_far' );
		$made['_links']['mk:checkout']['href'] = '/pay-here/order_far';
		$this->monokulo_serves(
			array(
				'GET ' . self::API                                => self::response( 200, $entry ),
				'POST https://elsewhere.example:8443/v1/make' => self::response( 201, $made ),
			)
		);

		$result = self::connected_gateway()->process_payment( self::order()->get_id() );

		$this->assertSame( 'https://elsewhere.example:8443/pay-here/order_far', $result['redirect'] );
		$this->assertSame( 'https://pay.example.com/checkout/x', WC_Gateway_Monokulo::resolve( '/checkout/x', self::API ) );
		$this->assertSame( 'https://pay.example.com/api/v1/x', WC_Gateway_Monokulo::resolve( 'x', self::API . '/' ) );
		$this->assertSame( 'http://abc.onion/api/v1', WC_Gateway_Monokulo::resolve( '/api/v1', 'http://abc.onion/plugins/connect/woocommerce/finish' ) );
	}

	/**
	 * Paying again with the same total gets the same Monokulo order; a new
	 * total gets a new one, and the order remembers both.
	 */
	public function test_a_changed_total_makes_a_new_monokulo_order_and_keeps_the_old_one() {
		$order   = self::order();
		$gateway = self::connected_gateway();
		$this->monokulo_serves( array( 'POST ' . self::ORDERS => self::created( 'order_first' ) ) );
		$gateway->process_payment( $order->get_id() );
		$gateway->process_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$order->set_total( '50.00' );
		$order->save();
		$this->monokulo_serves( array( 'POST ' . self::ORDERS => self::created( 'order_second' ) ) );
		$gateway->process_payment( $order->get_id() );

		$this->assertStringEndsWith( ':50:USD', json_decode( end( $this->sent )['args']['body'], true )['idempotency_key'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'order_second', $order->get_meta( WC_Gateway_Monokulo::META_ORDER_ID ) );
		$this->assertSame( array( 'order_first', 'order_second' ), array_values( wp_list_pluck( $order->get_meta( WC_Gateway_Monokulo::META_ORDER_IDS, false ), 'value' ) ) );
	}

	public function test_the_description_names_the_order_and_its_first_item() {
		$order = self::order( array( 'Blue mug', 'Red mug', 'Tea towel' ) );
		$this->assertSame( 'Order #' . $order->get_order_number() . ': Blue mug + 2 more', WC_Gateway_Monokulo::order_description( $order ) );

		$long        = self::order( array( str_repeat( 'é', 200 ) ) );
		$description = WC_Gateway_Monokulo::order_description( $long );
		$this->assertSame( 140, mb_strlen( $description, 'UTF-8' ) );
		$this->assertStringStartsWith( 'Order #' . $long->get_order_number() . ': éé', $description );
		$this->assertStringEndsWith( 'é…', $description );
	}

	/**
	 * In the currency's own decimals, trailing zeros trimmed: Monokulo
	 * refuses more than a fiat currency's two.
	 */
	public function test_amounts_are_in_the_currencys_own_decimals_trimmed() {
		$this->assertSame( '42.5', WC_Gateway_Monokulo::format_amount( 42.5, 'USD' ) );
		$this->assertSame( '42.55', WC_Gateway_Monokulo::format_amount( 42.549999, 'EUR' ) );
		$this->assertSame( '1234567', WC_Gateway_Monokulo::format_amount( 1234567, 'EUR' ) );
		$this->assertSame( '0.1', WC_Gateway_Monokulo::format_amount( 0.1, 'GBP' ) );
		$this->assertSame( '0', WC_Gateway_Monokulo::format_amount( 0, 'EUR' ) );
		$this->assertSame( '1500', WC_Gateway_Monokulo::format_amount( 1500, 'JPY' ) );
		$this->assertSame( '1501', WC_Gateway_Monokulo::format_amount( 1500.6, 'jpy' ) );
		$this->assertSame( '12.345', WC_Gateway_Monokulo::format_amount( 12.345, 'KWD' ) );
		$this->assertSame( '12.3', WC_Gateway_Monokulo::format_amount( 12.3, 'KWD' ) );
		$this->assertSame( '0.123456', WC_Gateway_Monokulo::format_amount( 0.123456, 'xmr' ) );
		$this->assertSame( '0.000000000001', WC_Gateway_Monokulo::format_amount( 0.000000000001, 'XMR' ) );
		update_option( 'woocommerce_price_num_decimals', 8 );
		$this->assertSame( '42.5', WC_Gateway_Monokulo::format_amount( 42.5, 'USD' ), 'Never padded to the shop\'s price decimals.' );
	}

	public function test_a_yen_order_is_sent_in_whole_yen() {
		$order = self::order();
		$order->set_currency( 'JPY' );
		$order->set_total( '1500' );
		$order->save();
		$this->monokulo_serves();
		self::connected_gateway()->process_payment( $order->get_id() );
		$sent = json_decode( $this->sent_to( self::ORDERS )[0]['args']['body'], true );
		$this->assertSame( '1500', $sent['amount'] );
		$this->assertSame( 'wc:' . $order->get_id() . ':1500:JPY', $sent['idempotency_key'] );
	}

	public function test_a_store_without_a_whole_connection_calls_nothing() {
		$this->monokulo_serves();
		foreach ( array( 'secret_token', 'api' ) as $missing ) {
			try {
				self::connected_gateway( array( $missing => '' ) )->process_payment( self::order()->get_id() );
				$this->fail( "Without $missing it should have thrown." );
			} catch ( Exception $e ) {
				$this->assertStringContainsString( 'not set up for this store', $e->getMessage() );
			}
			$this->assertSame( array(), $this->sent, $missing );
		}
	}

	/**
	 * Each failure tells the customer something short, logs the detail for
	 * the merchant (a problem's title, detail and type), and leaves the
	 * order unlinked.
	 */
	public function test_a_refused_or_failed_order_is_explained_and_logged() {
		$entry  = 'GET ' . self::API;
		$create = 'POST ' . self::ORDERS;
		$bare   = Monokulo_Api_Fixtures::entry();
		unset( $bare['_templates']['create-order'] );
		$made = Monokulo_Api_Fixtures::order( 'order_1' );
		unset( $made['_links']['mk:checkout'] );
		$problem = static fn( int $status, string $name, string $title, string $detail ) => self::response( $status, Monokulo_Api_Fixtures::problem( $status, $name, $title, $detail ) );

		$cases = array(
			'unreachable'       => array( array( $entry => new WP_Error( 'http_request_failed', 'cURL error 7' ) ), 'could not be reached', 'cURL error 7' ),
			'entry 401'         => array( array( $entry => $problem( 401, 'unauthorized', 'No valid key', 'detail 401' ) ), 'not available for this store', 'reconnect the plugin' ),
			'entry 404'         => array( array( $entry => self::response( 404, 'Not Found' ) ), 'could not be started', 'check the Monokulo address' ),
			'no form'           => array( array( $entry => self::response( 200, $bare ) ), 'not available for this store', 'no "create-order" form' ),
			'create unreachable' => array( array( $create => new WP_Error( 'http_request_failed', 'cURL error 28' ) ), 'could not be reached', 'cURL error 28' ),
			'HTTP 401'          => array( array( $create => $problem( 401, 'unauthorized', 'No valid key', 'detail 401' ) ), 'not available for this store', 'No valid key: detail 401 (https://docs.monokulo.com/problems/unauthorized)' ),
			'HTTP 403'          => array( array( $create => $problem( 403, 'forbidden', 'Not allowed with this key', 'detail 403' ) ), 'not available for this store', 'Not allowed with this key' ),
			'HTTP 429'          => array( array( $create => $problem( 429, 'rate-limited', 'Too many requests', 'detail 429' ) ), 'busy right now', 'rate limited' ),
			'invalid amount'    => array( array( $create => $problem( 400, 'invalid-amount', 'The order can\'t be priced', 'unsupported currency: USD' ) ), 'cannot be priced', 'unsupported currency: USD' ),
			'conflict'          => array( array( $create => $problem( 409, 'idempotency-conflict', 'Key used for another order', 'detail 409' ) ), 'could not be started', 'Key used for another order' ),
			'not json'          => array( array( $create => self::response( 201, '<html>Checking your browser</html>' ) ), 'could not be started', 'Checking your browser' ),
			'no checkout link'  => array( array( $create => self::response( 201, $made ) ), 'could not be started', 'not the expected shape' ),
		);
		foreach ( $cases as $case => list( $routes, $customer, $merchant ) ) {
			$this->logged = array();
			$order        = self::order();
			delete_transient( 'monokulo_entry_' . md5( self::API . "\nsk_test" ) );
			$this->monokulo_serves( $routes );
			try {
				self::connected_gateway()->process_payment( $order->get_id() );
				$this->fail( "$case should have thrown." );
			} catch ( Exception $e ) {
				$this->assertStringContainsString( $customer, $e->getMessage(), $case );
				$this->assertStringNotContainsString( 'detail', $e->getMessage(), $case );
			}
			$this->assertTrue( $this->logged( $merchant ), $case . ': ' . wp_json_encode( $this->logged ) );
			$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( WC_Gateway_Monokulo::META_ORDER_ID ), $case );
		}
	}

	public function test_a_refused_key_has_the_entry_read_again() {
		$this->monokulo_serves( array( 'POST ' . self::ORDERS => self::response( 401, Monokulo_Api_Fixtures::problem( 401, 'unauthorized', 'No valid key', '' ) ) ) );
		$gateway = self::connected_gateway();
		foreach ( array( 1, 2 ) as $attempt ) {
			try {
				$gateway->process_payment( self::order()->get_id() );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}
		$this->assertCount( 2, $this->sent_to( self::API ) );
	}
}
