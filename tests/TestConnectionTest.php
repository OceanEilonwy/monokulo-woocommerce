<?php
/**
 * The settings page's Test connection button: it follows the entry's
 * `send-test-webhook` form and tells the admin what Monokulo answered,
 * from Monokulo's recorded answers (delivered, and every problem) and the
 * not-delivered answer its schema describes.
 *
 * @package Monokulo
 */

declare(strict_types=1);

class TestConnectionTest extends MonokuloTestCase {

	const TESTS = 'https://pay.example.com/api/v1/webhook-tests';

	public function set_up(): void {
		parent::set_up();
		self::connected_gateway();
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_redirect' );
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * The gateway's answer to Test connection, Monokulo answering the form
	 * with `$answer`.
	 *
	 * @param array|WP_Error $answer The answer.
	 */
	private function test_with( $answer ): array {
		$this->monokulo_serves( array( 'POST ' . self::TESTS => $answer ) );
		return ( new WC_Gateway_Monokulo() )->test_connection();
	}

	public function test_delivered_says_what_the_shop_answered_and_how_fast() {
		$result = $this->test_with( self::response( 200, Monokulo_Api_Fixtures::webhook_test() ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Test webhook delivered: this shop answered HTTP 200 in 12 ms (evt_0000000000000002).', $result['message'] );

		// Followed from the entry's form, with `{}` and the secret key.
		$sent = $this->sent_to( self::TESTS );
		$this->assertCount( 1, $sent );
		$this->assertSame( 'POST', $sent[0]['args']['method'] );
		$this->assertSame( '{}', $sent[0]['args']['body'] );
		$this->assertSame( 'Bearer sk_test', $sent[0]['args']['headers']['Authorization'] );
		$this->assertTrue( $this->logged( 'Test connection via ' . self::TESTS . ': Test webhook delivered' ) );
	}

	public function test_the_form_is_followed_wherever_the_entry_says() {
		$api = 'https://pay.example.com/monokulo/api/v1';
		self::connected_gateway( array( 'api' => $api ) );
		$this->monokulo_serves(
			array(
				'GET ' . $api => self::entry_response( Monokulo_Api_Fixtures::entry( '/monokulo' ) ),
				'POST https://pay.example.com/monokulo/api/v1/webhook-tests' => self::response( 200, Monokulo_Api_Fixtures::webhook_test( '/monokulo' ) ),
			)
		);
		$this->assertTrue( ( new WC_Gateway_Monokulo() )->test_connection()['ok'] );
	}

	public function test_not_delivered_says_why_and_when_monokulo_tries_again() {
		$result = $this->test_with( self::response( 200, Monokulo_Api_Fixtures::webhook_test( '', false, 500 ) ) );
		$this->assertFalse( $result['ok'] );
		$this->assertStringStartsWith( 'The test webhook was not delivered: answered 500 (evt_0000000000000002).', $result['message'] );
		$this->assertStringContainsString( 'Monokulo tries again at ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 1760020452 ) . '.', $result['message'] );
		$this->assertTrue( $this->logged( 'Test connection via ' . self::TESTS . ': The test webhook was not delivered' ) );
	}

	public function test_not_delivered_with_no_answer_says_so() {
		$result = $this->test_with( self::response( 200, Monokulo_Api_Fixtures::webhook_test( '', false, 0 ) ) );
		$this->assertFalse( $result['ok'] );
		$this->assertStringStartsWith( 'The test webhook was not delivered: timed out (evt_0000000000000002).', $result['message'] );
	}

	public function test_a_refused_signature_or_missing_route_gets_a_hint() {
		$refused = $this->test_with( self::response( 200, Monokulo_Api_Fixtures::webhook_test( '', false, 401 ) ) );
		$this->assertStringContainsString( 'This shop refused its signature: connect again', $refused['message'] );
		$missing = $this->test_with( self::response( 200, Monokulo_Api_Fixtures::webhook_test( '', false, 404 ) ) );
		$this->assertStringContainsString( 'webhook address was not found', $missing['message'] );
	}

	public function test_rate_limited_says_how_long_to_wait() {
		$problem = Monokulo_Api_Fixtures::WEBHOOK_TEST_PROBLEMS['rate-limited'];
		$result  = $this->test_with( self::response( 429, $problem, array( 'retry-after' => '120' ) ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Monokulo sends at most 6 test webhooks a minute for a store. Try again in 120 seconds.', $result['message'] );
		// Without the header, from the problem's retry_after.
		$this->assertStringContainsString( 'Try again in 120 seconds.', $this->test_with( self::response( 429, $problem ) )['message'] );
	}

	public function test_not_found_asks_to_connect_again() {
		$result = $this->test_with( self::response( 404, Monokulo_Api_Fixtures::WEBHOOK_TEST_PROBLEMS['not-found'] ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Monokulo has no webhook for this shop to test. Connect again to register it.', $result['message'] );
	}

	public function every_problem(): array {
		$cases = array();
		foreach ( Monokulo_Api_Fixtures::WEBHOOK_TEST_PROBLEMS as $name => $problem ) {
			$cases[ $name ] = array( $problem );
		}
		return $cases;
	}

	/**
	 * Every problem the form answers with is a failed test with a message.
	 *
	 * @dataProvider every_problem
	 */
	public function test_every_problem_is_a_failed_test( array $problem ) {
		$result = $this->test_with( self::response( $problem['status'], $problem ) );
		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['message'] );
		if ( ! in_array( $problem['status'], array( 401, 404, 429 ), true ) ) {
			$this->assertSame( 'Monokulo did not send a test webhook. Monokulo says: ' . $problem['detail'], $result['message'] );
		}
	}

	public function test_a_monokulo_without_the_form_is_asked_to_update() {
		$entry = Monokulo_Api_Fixtures::entry();
		unset( $entry['_templates']['send-test-webhook'] );
		$this->monokulo_serves( array( 'GET ' . self::API => self::entry_response( $entry ) ) );
		$result = ( new WC_Gateway_Monokulo() )->test_connection();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'it is older than this plugin', $result['message'] );
		$this->assertSame( array(), $this->sent_to( self::TESTS ) );
	}

	public function test_an_unreachable_monokulo_says_so() {
		$this->monokulo_answers( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
		$result = ( new WC_Gateway_Monokulo() )->test_connection();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'could not be reached', $result['message'] );
	}

	public function test_a_shop_not_connected_sends_nothing() {
		self::connected_gateway( array( 'secret_token' => '' ) );
		$this->monokulo_serves();
		$result = ( new WC_Gateway_Monokulo() )->test_connection();
		$this->assertSame( 'Connect the store first.', $result['message'] );
		$this->assertSame( array(), $this->sent );
	}

	public function test_the_settings_page_has_the_button_and_the_last_test_received() {
		$gateway = new WC_Gateway_Monokulo();
		$html    = $gateway->generate_monokulo_connect_html( 'connect', array() );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*admin-post\.php\?action=monokulo_test_connection&(amp;|\#038;)_wpnonce=[0-9a-f]+" id="woocommerce_monokulo_connect_test" class="button">\s*Test connection#', $html );
		$this->assertStringContainsString( 'Last test webhook received: none yet.', $html );

		update_option(
			WC_Gateway_Monokulo::LAST_TEST_OPTION,
			array(
				'event_id'    => 'evt_seen',
				'created_at'  => 1760020332,
				'received_at' => 1760020333,
			)
		);
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 1760020333 );
		$this->assertStringContainsString( esc_html( 'Last test webhook received: ' . $when . ' (evt_seen).' ), $gateway->generate_monokulo_connect_html( 'connect', array() ) );

		self::connected_gateway( array( 'secret_token' => '' ) );
		$this->assertStringNotContainsString( 'Test connection', ( new WC_Gateway_Monokulo() )->generate_monokulo_connect_html( 'connect', array() ) );
	}

	/**
	 * Presses the button as the current user: the handler's redirect, or
	 * the WPDieException that stopped it.
	 *
	 * @param string $nonce The button's nonce.
	 */
	private function press( string $nonce ): string {
		$_REQUEST['_wpnonce'] = $nonce;
		self::rebuild_gateways();
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect: ' . $location );
			}
		);
		try {
			do_action( 'admin_post_monokulo_test_connection' );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} catch ( WPDieException $e ) {
			return 'died: ' . $e->getMessage();
		}
		return '';
	}

	/**
	 * The notice on the settings page.
	 *
	 * @param string $redirect Where the button sent the browser.
	 */
	private static function notice( string $redirect ): string {
		parse_str( (string) wp_parse_url( substr( $redirect, strlen( 'redirect: ' ) ), PHP_URL_QUERY ), $_GET );
		ob_start();
		monokulo_connect_notice();
		return (string) ob_get_clean();
	}

	public function test_the_button_shows_the_result_on_the_settings_page_once() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->monokulo_serves( array( 'POST ' . self::TESTS => self::response( 200, Monokulo_Api_Fixtures::webhook_test() ) ) );

		$redirect = $this->press( wp_create_nonce( 'monokulo_test_connection' ) );
		$this->assertStringContainsString( 'section=monokulo', $redirect );
		$this->assertStringContainsString( 'monokulo_tested=1', $redirect );
		$this->assertStringContainsString( '<div class="notice notice-success is-dismissible monokulo-test-result"><p>Test webhook delivered: this shop answered HTTP 200 in 12 ms (evt_0000000000000002).</p></div>', self::notice( $redirect ) );
		$this->assertSame( '', self::notice( $redirect ), 'shown once' );

		$this->monokulo_serves( array( 'POST ' . self::TESTS => self::response( 429, Monokulo_Api_Fixtures::WEBHOOK_TEST_PROBLEMS['rate-limited'] ) ) );
		$this->assertStringContainsString( 'notice-error', self::notice( $this->press( wp_create_nonce( 'monokulo_test_connection' ) ) ) );
	}

	public function test_the_button_needs_its_nonce_and_a_shop_manager() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->monokulo_serves();
		$this->assertStringStartsWith( 'died: ', $this->press( 'forged' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertStringContainsString( 'You do not have permission to do this.', $this->press( wp_create_nonce( 'monokulo_test_connection' ) ) );
		$this->assertSame( array(), $this->sent );
	}
}
