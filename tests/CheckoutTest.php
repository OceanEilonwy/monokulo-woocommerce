<?php
/**
 * Paying with Monero: `process_payment()` reads the API entry with the
 * store's secret key (kept as its `Cache-Control` says), makes the order
 * with its `create-order` form, and sends the customer to the order's
 * `mk:checkout` link, or its `mk:onion-checkout` for a customer on Tor.
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
		$notes = self::notes( $order );
		$this->assertStringContainsString( 'sent to pay here: https://pay.example.com/checkout/order_deadbeef', $notes );
		$this->assertStringNotContainsString( 'In Monokulo', $notes, 'The dashboard page comes with each webhook, not from here.' );
	}

	/**
	 * A Monokulo served under a path (`https://example.com/monokulo`) gives
	 * every link with that path; the plugin builds none, so its customers
	 * and merchant reach it.
	 */
	public function test_a_monokulo_under_a_path_is_reached_through_its_links() {
		$api = 'https://example.com/monokulo/api/v1';
		$this->monokulo_serves(
			array(
				'GET ' . $api                                => self::entry_response( Monokulo_Api_Fixtures::entry( '/monokulo' ) ),
				'POST https://example.com/monokulo/api/v1/orders' => self::response( 201, Monokulo_Api_Fixtures::order( 'order_p', '/monokulo' ) ),
			)
		);
		$order  = self::order();
		$result = self::connected_gateway( array( 'api' => $api ) )->process_payment( $order->get_id() );

		$this->assertSame( array( $api, 'https://example.com/monokulo/api/v1/orders' ), wp_list_pluck( $this->sent, 'url' ) );
		$this->assertSame( 'https://example.com/monokulo/checkout/order_p', $result['redirect'] );
	}

	/**
	 * A customer who came to the shop on its `.onion` address stays on Tor:
	 * they're sent to the order's checkout on Monokulo's onion address,
	 * though the shop's server called Monokulo on its web address.
	 */
	public function test_a_customer_on_tor_is_sent_to_the_onion_checkout() {
		$this->monokulo_serves(
			array(
				'GET ' . self::API     => self::entry_response( Monokulo_Api_Fixtures::entry( '', true ) ),
				'POST ' . self::ORDERS => self::response( 201, Monokulo_Api_Fixtures::order( 'order_tor', '', true ) ),
			)
		);
		$gateway = self::connected_gateway();

		$_SERVER['HTTP_HOST'] = 'shopabcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuv.onion';
		$order                = self::order();
		$result               = $gateway->process_payment( $order->get_id() );
		$this->assertSame( Monokulo_Api_Fixtures::ONION . '/checkout/order_tor', $result['redirect'] );
		$this->assertStringContainsString( 'sent to pay here: ' . Monokulo_Api_Fixtures::ONION . '/checkout/order_tor', self::notes( wc_get_order( $order->get_id() ) ) );

		$_SERVER['HTTP_HOST'] = 'SHOP.onion:8080';
		$this->assertSame( Monokulo_Api_Fixtures::ONION . '/checkout/order_tor', $gateway->process_payment( self::order()->get_id() )['redirect'], 'Any case, any port.' );

		$_SERVER['HTTP_HOST'] = 'shop.example';
		$this->assertSame( 'https://pay.example.com/checkout/order_tor', $gateway->process_payment( self::order()->get_id() )['redirect'], 'A customer on the web stays on the web.' );

		$_SERVER['HTTP_HOST'] = 'onion.shop.example';
		$this->assertSame( 'https://pay.example.com/checkout/order_tor', $gateway->process_payment( self::order()->get_id() )['redirect'] );
	}

	/**
	 * Pays as a customer on Tor; returns the message they were refused with.
	 *
	 * @param WC_Gateway_Monokulo $gateway The gateway.
	 * @param WC_Order            $order   The order.
	 */
	private function refused_on_tor( WC_Gateway_Monokulo $gateway, WC_Order $order ): string {
		try {
			$gateway->process_payment( $order->get_id() );
		} catch ( Exception $e ) {
			$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( WC_Gateway_Monokulo::META_ORDER_ID ) );
			$this->assertTrue( $this->logged( 'the Monokulo has no onion address' ) );
			return $e->getMessage();
		}
		$this->fail( 'A customer on Tor must not be sent to the web checkout.' );
	}

	/**
	 * Without an onion address the entry has no `mk:onion-api`: a customer
	 * on Tor is told, never sent to the web, and no order is made (it would
	 * only expire). The merchant's log says why.
	 */
	public function test_a_customer_on_tor_is_never_sent_to_the_web() {
		$this->monokulo_serves();
		$_SERVER['HTTP_HOST'] = 'shop.onion';
		$this->assertStringContainsString( 'cannot be made over Tor', $this->refused_on_tor( self::connected_gateway(), self::order() ) );
		$this->assertSame( array( self::API ), wp_list_pluck( $this->sent, 'url' ), 'No order is made.' );
	}

	/**
	 * Before refusing, a kept entry without `mk:onion-api` is checked with
	 * Monokulo (`If-None-Match`): still the same (`304`), the customer is
	 * refused with no order made; a Monokulo that has just gained an onion
	 * address sends them to its onion checkout.
	 */
	public function test_a_kept_entry_without_an_onion_address_is_checked_before_refusing() {
		$this->monokulo_serves();
		$gateway = self::connected_gateway();
		$gateway->process_payment( self::order()->get_id() );

		$_SERVER['HTTP_HOST'] = 'shop.onion';
		$this->sent           = array();
		$this->monokulo_serves( array( 'GET ' . self::API => static fn( array $args ) => '"e1"' === ( $args['headers']['If-None-Match'] ?? '' ) ? self::response( 304, '', array( 'cache-control' => 'private, max-age=3600' ) ) : self::response( 500 ) ) );
		$this->refused_on_tor( $gateway, self::order() );
		$this->assertSame( array( self::API ), wp_list_pluck( $this->sent, 'url' ), 'Revalidated, and no order made.' );

		$this->sent = array();
		$this->monokulo_serves(
			array(
				'GET ' . self::API     => self::entry_response( Monokulo_Api_Fixtures::entry( '', true ), 3600, '"e2"' ),
				'POST ' . self::ORDERS => self::response( 201, Monokulo_Api_Fixtures::order( 'order_tor', '', true ) ),
			)
		);
		$result = $gateway->process_payment( self::order()->get_id() );
		$this->assertSame( Monokulo_Api_Fixtures::ONION . '/checkout/order_tor', $result['redirect'] );
		$this->assertSame( array( self::API, self::ORDERS ), wp_list_pluck( $this->sent, 'url' ) );
	}

	public function test_the_entry_is_read_once_and_kept() {
		$this->monokulo_serves();
		$gateway = self::connected_gateway();
		$gateway->process_payment( self::order()->get_id() );
		$gateway->process_payment( self::order()->get_id() );
		( new WC_Gateway_Monokulo() )->process_payment( self::order()->get_id() );

		$this->assertCount( 1, $this->sent_to( self::API ) );
		$this->assertCount( 3, $this->sent_to( self::ORDERS ) );
		$this->assertArrayNotHasKey( 'If-None-Match', $this->sent[0]['args']['headers'] );
	}

	/**
	 * Once `max-age` has passed, the entry is asked for with its `ETag`; a
	 * `304` keeps the copy for another `max-age`, a new entry replaces it.
	 */
	public function test_an_expired_entry_is_revalidated_with_its_etag() {
		$this->monokulo_serves();
		$gateway = self::connected_gateway();
		$gateway->process_payment( self::order()->get_id() );
		$kept = get_option( WC_Gateway_Monokulo::ENTRY_OPTION );
		$this->assertSame( '"e1"', $kept['etag'] );
		$this->assertEqualsWithDelta( time() + 3600, $kept['expires'], 5 );
		$this->assertStringNotContainsString( 'sk_test', wp_json_encode( $kept ), 'The key isn\'t kept twice.' );

		$expire = static function (): void {
			$kept            = get_option( WC_Gateway_Monokulo::ENTRY_OPTION );
			$kept['expires'] = time() - 1;
			update_option( WC_Gateway_Monokulo::ENTRY_OPTION, $kept, false );
		};
		$expire();
		$this->sent = array();
		$this->monokulo_serves( array( 'GET ' . self::API => static fn( array $args ) => '"e1"' === ( $args['headers']['If-None-Match'] ?? '' ) ? self::response( 304, '', array( 'cache-control' => 'private, max-age=600' ) ) : self::response( 500 ) ) );
		$gateway->process_payment( self::order()->get_id() );
		$gateway->process_payment( self::order()->get_id() );
		$this->assertSame( array( self::API, self::ORDERS, self::ORDERS ), wp_list_pluck( $this->sent, 'url' ), 'Not modified: kept for another max-age.' );
		$this->assertEqualsWithDelta( time() + 600, get_option( WC_Gateway_Monokulo::ENTRY_OPTION )['expires'], 5 );

		$expire();
		$this->sent = array();
		$moved      = Monokulo_Api_Fixtures::entry();
		$moved['_templates']['create-order']['target'] = '/api/v1/new-orders';
		$this->monokulo_serves(
			array(
				'GET ' . self::API                              => self::entry_response( $moved, 3600, '"e2"' ),
				'POST https://pay.example.com/api/v1/new-orders' => self::response( 201, Monokulo_Api_Fixtures::order( 'order_2' ) ),
			)
		);
		$gateway->process_payment( self::order()->get_id() );
		$this->assertSame( array( self::API, 'https://pay.example.com/api/v1/new-orders' ), wp_list_pluck( $this->sent, 'url' ) );
		$this->assertSame( '"e2"', get_option( WC_Gateway_Monokulo::ENTRY_OPTION )['etag'] );
	}

	public function test_an_entry_without_a_max_age_is_read_each_time() {
		$this->monokulo_serves( array( 'GET ' . self::API => self::response( 200, Monokulo_Api_Fixtures::entry() ) ) );
		$gateway = self::connected_gateway();
		$gateway->process_payment( self::order()->get_id() );
		$gateway->process_payment( self::order()->get_id() );
		$this->assertCount( 2, $this->sent_to( self::API ) );
	}

	public function test_a_new_connection_reads_its_own_entry() {
		$this->monokulo_serves();
		self::connected_gateway()->process_payment( self::order()->get_id() );
		self::connected_gateway( array( 'secret_token' => 'sk_new' ) )->process_payment( self::order()->get_id() );
		$this->assertCount( 2, $this->sent_to( self::API ) );
		$this->assertSame( 'Bearer sk_new', $this->sent_to( self::API )[1]['args']['headers']['Authorization'] );
	}

	/**
	 * A form from a kept entry that answers `401`, `404` or `405` has the
	 * entry read again at once and the order sent once more, with the same
	 * idempotency key: the customer never sees the API move on.
	 */
	public function test_a_refused_form_from_a_kept_entry_has_the_entry_read_again_at_once() {
		foreach ( array( 401, 404, 405 ) as $status ) {
			$this->monokulo_serves();
			$gateway = self::connected_gateway();
			$gateway->process_payment( self::order()->get_id() );

			$this->sent = array();
			$moved      = Monokulo_Api_Fixtures::entry();
			$moved['_templates']['create-order']['target'] = '/api/v1/orders-moved';
			$this->monokulo_serves(
				array(
					'GET ' . self::API                                => self::entry_response( $moved ),
					'POST ' . self::ORDERS                            => self::response( $status, Monokulo_Api_Fixtures::problem( $status, 'not-found', 'Gone', '' ) ),
					'POST https://pay.example.com/api/v1/orders-moved' => self::response( 201, Monokulo_Api_Fixtures::order( 'order_moved' ) ),
				)
			);
			$result = $gateway->process_payment( self::order()->get_id() );
			$this->assertSame( 'https://pay.example.com/checkout/order_moved', $result['redirect'], (string) $status );
			$this->assertSame( array( self::ORDERS, self::API, 'https://pay.example.com/api/v1/orders-moved' ), wp_list_pluck( $this->sent, 'url' ), (string) $status );
			$bodies = array_map( static fn( array $sent ) => $sent['args']['body'] ?? null, array( $this->sent[0], $this->sent[2] ) );
			$this->assertSame( $bodies[0], $bodies[1], 'The same order, idempotency key and all.' );
			delete_option( WC_Gateway_Monokulo::ENTRY_OPTION );
		}
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
	 * In the currency's own decimals, trailing zeros trimmed: what the
	 * customer is charged, never the shop's display rounding.
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
			'entry 401'         => array( array( $entry => $problem( 401, 'unauthorized', 'No valid key', 'detail 401' ) ), 'not available for this store', 'the store secret key was rejected - reconnect the plugin' ),
			'entry 404'         => array( array( $entry => $problem( 404, 'not-found', 'Not found', 'detail 404' ) ), 'could not be started', 'check the Monokulo address' ),
			'no form'           => array( array( $entry => self::entry_response( $bare ) ), 'could not be started', 'offers no "create-order" form' ),
			'create unreachable' => array( array( $create => new WP_Error( 'http_request_failed', 'cURL error 28' ) ), 'could not be reached', 'cURL error 28' ),
			'HTTP 401'          => array( array( $create => $problem( 401, 'unauthorized', 'No valid key', 'detail 401' ) ), 'not available for this store', 'No valid key: detail 401 (https://docs.monokulo.com/problems/unauthorized)' ),
			'HTTP 403'          => array( array( $create => $problem( 403, 'forbidden', 'Not allowed with this key', 'detail 403' ) ), 'not available for this store', 'Not allowed with this key' ),
			'HTTP 429'          => array( array( $create => $problem( 429, 'rate-limited', 'Too many requests', 'detail 429' ) ), 'busy right now', 'rate limited' ),
			'no rate'           => array( array( $create => $problem( 503, 'rates-unavailable', 'No exchange rate right now', 'detail 503' ) ), 'try again shortly', 'no exchange rate for the order\'s currency right now' ),
			'engine away'       => array( array( $create => $problem( 503, 'engine-unavailable', 'The payment engine is unavailable', 'detail 503' ) ), 'try again shortly', 'payment engine is busy or unreachable' ),
			'invalid amount'    => array( array( $create => $problem( 400, 'invalid-amount', 'The order can\'t be priced', 'unsupported currency: USD' ) ), 'cannot be priced', 'unsupported currency: USD' ),
			'conflict'          => array( array( $create => $problem( 409, 'idempotency-conflict', 'Key used for another order', 'detail 409' ) ), 'could not be started', 'Key used for another order' ),
			'not json'          => array( array( $create => self::response( 201, '<html>Checking your browser</html>' ) ), 'could not be started', 'Checking your browser' ),
			'no checkout link'  => array( array( $create => self::response( 201, $made ) ), 'could not be started', 'not the expected shape' ),
		);
		foreach ( $cases as $case => list( $routes, $customer, $merchant ) ) {
			$this->logged = array();
			$order        = self::order();
			delete_option( WC_Gateway_Monokulo::ENTRY_OPTION );
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

	/**
	 * Monokulo had no exchange rate, or its engine was away: no order was
	 * made. The customer is asked to try again shortly, the merchant's log
	 * says how long Monokulo asked to wait, and placing the order again
	 * sends the same idempotency key, so it can only ever make one order.
	 */
	public function test_an_order_monokulo_cannot_make_yet_is_tried_again_with_the_same_key() {
		foreach ( array( 'rates-unavailable' => array( 'retry-after' => '30' ), 'engine-unavailable' => array() ) as $name => $headers ) {
			$this->logged = array();
			$this->sent   = array();
			$order        = self::order();
			$problem      = Monokulo_Api_Fixtures::problem( 503, $name, 'Not right now', 'Try again in a moment.' ) + ( $headers ? array( 'retry_after' => 30 ) : array() );
			$this->monokulo_serves( array( 'POST ' . self::ORDERS => self::response( 503, $problem, $headers ) ) );
			try {
				self::connected_gateway()->process_payment( $order->get_id() );
				$this->fail( "$name should have thrown." );
			} catch ( Exception $e ) {
				$this->assertSame( 'Monero payments are unavailable for a moment. Please try again shortly.', $e->getMessage(), $name );
			}
			$this->assertSame( $headers ? true : false, $this->logged( '(retry after 30 s)' ), $name . ': ' . wp_json_encode( $this->logged ) );
			$this->assertTrue( $this->logged( 'No order was made' ), $name );
			$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( WC_Gateway_Monokulo::META_ORDER_ID ), $name );

			$this->monokulo_serves();
			$result = self::connected_gateway()->process_payment( $order->get_id() );
			$this->assertSame( 'https://pay.example.com/checkout/order_1', $result['redirect'], $name );
			$keys = array_map( static fn( $sent ) => json_decode( $sent['args']['body'], true )['idempotency_key'], $this->sent_to( self::ORDERS ) );
			$this->assertCount( 2, $keys, $name );
			$this->assertSame( 'wc:' . $order->get_id() . ':42.5:USD', $keys[0], $name );
			$this->assertSame( $keys[0], $keys[1], $name . ': the same key both times' );
		}
	}

	/**
	 * A key refused straight after the entry was read isn't read again at
	 * once, but the next order reads it afresh.
	 */
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
