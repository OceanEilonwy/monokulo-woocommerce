<?php
/**
 * Paying with Monero: `process_payment()` creates the order at
 * `POST {endpoint}/pay/{pk}/orders` and sends the customer to Monokulo's
 * checkout for it.
 *
 * @package Monokulo
 */

declare(strict_types=1);

class CheckoutTest extends MonokuloTestCase {

	const ONION = 'http://2gzyxa5ihm7nsggfxnu52rck2vv4rvmdlkiu3zzui5du4xyclen53wid.onion';

	private static function created( string $order_id = 'mko_1' ): array {
		return self::response(
			200,
			array(
				'order_id'   => $order_id,
				'amount'     => '42.50',
				'currency'   => 'USD',
				'expires_at' => 4102444800,
			)
		);
	}

	public function test_placing_an_order_creates_it_on_monokulo_and_sends_the_customer_to_pay() {
		$order = self::order();
		$this->monokulo_answers( self::created( 'mko_deadbeef' ) );

		$result = self::connected_gateway( array( 'endpoint' => 'https://pay.example.com/' ) )->process_payment( $order->get_id() );

		$this->assertCount( 1, $this->sent );
		$sent = $this->sent[0];
		$this->assertSame( 'https://pay.example.com/pay/pk_test/orders', $sent['url'] );
		$this->assertSame( 'POST', $sent['args']['method'] );
		$this->assertSame( 'Bearer sk_test', $sent['args']['headers']['Authorization'] );
		$this->assertSame( 'woocommerce/' . WC_Gateway_Monokulo::VERSION, $sent['args']['headers']['Monokulo-Client'] );
		$this->assertMatchesRegularExpression( '/\A00-' . WC_Gateway_Monokulo::trace_id() . '-[0-9a-f]{16}-01\z/', $sent['args']['headers']['traceparent'] );
		$this->assertSame(
			array(
				'amount'                 => '42.50',
				'currency'               => 'USD',
				'merchant_order_id'      => (string) $order->get_id(),
				'show_merchant_order_id' => false,
				'description'            => 'Order #' . $order->get_order_number(),
				'idempotency_key'        => 'wc:' . $order->get_id() . ':42.50:USD',
			),
			json_decode( $sent['args']['body'], true )
		);

		$this->assertSame(
			array(
				'result'   => 'success',
				'redirect' => 'https://pay.example.com/pay/pk_test/orders/mko_deadbeef',
			),
			$result
		);
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'mko_deadbeef', $order->get_meta( WC_Gateway_Monokulo::META_ORDER_ID ) );
		$this->assertSame( 'pending', $order->get_status(), 'Not paid until Monokulo says so.' );
	}

	public function test_a_customer_on_the_shops_onion_address_pays_on_monokulos() {
		$gateway = self::connected_gateway( array( 'onion_endpoint' => self::ONION ) );
		$host    = $_SERVER['HTTP_HOST'] ?? null;
		try {
			$_SERVER['HTTP_HOST'] = 'shopabcd.onion';
			$this->monokulo_answers( self::created() );
			$result = $gateway->process_payment( self::order()->get_id() );
			$this->assertSame( 'https://pay.example.com/pay/pk_test/orders', $this->sent[0]['url'], 'Created from the shop\'s server.' );
			$this->assertSame( self::ONION . '/pay/pk_test/orders/mko_1', $result['redirect'] );

			$_SERVER['HTTP_HOST'] = 'shop.example.com';
			$result               = $gateway->process_payment( self::order()->get_id() );
			$this->assertSame( 'https://pay.example.com/pay/pk_test/orders/mko_1', $result['redirect'] );
		} finally {
			if ( null === $host ) {
				unset( $_SERVER['HTTP_HOST'] );
			} else {
				$_SERVER['HTTP_HOST'] = $host;
			}
		}
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

	public function test_amounts_are_plain_decimals() {
		$this->assertSame( '42.50', WC_Gateway_Monokulo::format_amount( 42.5, 'USD' ) );
		$this->assertSame( '1234567.00', WC_Gateway_Monokulo::format_amount( 1234567, 'EUR' ) );
		update_option( 'woocommerce_price_num_decimals', 6 );
		$this->assertSame( '0.123456', WC_Gateway_Monokulo::format_amount( 0.123456, 'xmr' ) );
	}

	public function test_a_store_without_a_whole_connection_calls_nothing() {
		$this->monokulo_answers( self::created() );
		$this->expectException( Exception::class );
		try {
			self::connected_gateway( array( 'secret_token' => '' ) )->process_payment( self::order()->get_id() );
		} finally {
			$this->assertSame( array(), $this->sent );
		}
	}

	/**
	 * Each failure tells the customer something short, logs the detail for
	 * the merchant, and leaves the order unlinked.
	 */
	public function test_a_refused_or_failed_order_is_explained_and_logged() {
		$cases = array(
			'unreachable' => array( new WP_Error( 'http_request_failed', 'cURL error 7' ), 'Could not reach Monokulo', 'cURL error 7' ),
			'HTTP 401'    => array( self::response( 401, array( 'error' => 'detail 401' ) ), 'not available for this store', 'reconnect the plugin' ),
			'HTTP 403'    => array( self::response( 403, array( 'error' => 'detail 403' ) ), 'not available for this store', 'verified-domain' ),
			'HTTP 404'    => array( self::response( 404, array( 'error' => 'detail 404' ) ), 'could not start this payment', 'reconnect the plugin' ),
			'HTTP 429'    => array( self::response( 429, array( 'error' => 'detail 429' ) ), 'busy right now', 'rate limited' ),
			'HTTP 400'    => array( self::response( 400, array( 'error' => 'unsupported currency' ) ), 'could not start this payment', 'unsupported currency' ),
			'not json'    => array( self::response( 200, '<html>Checking your browser</html>' ), 'unexpected response', 'Checking your browser' ),
		);
		foreach ( $cases as $case => list( $answer, $customer, $merchant ) ) {
			$this->logged = array();
			$order        = self::order();
			$this->monokulo_answers( $answer );
			try {
				self::connected_gateway()->process_payment( $order->get_id() );
				$this->fail( "$case should have thrown." );
			} catch ( Exception $e ) {
				$this->assertStringContainsString( $customer, $e->getMessage(), $case );
				$this->assertStringNotContainsString( 'detail', $e->getMessage(), $case );
			}
			$this->assertTrue( $this->logged( $merchant ), $case );
			$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( WC_Gateway_Monokulo::META_ORDER_ID ), $case );
		}
	}
}
