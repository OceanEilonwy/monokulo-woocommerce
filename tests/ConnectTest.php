<?php
/**
 * Connecting the shop: the Connect button goes to the Monokulo address
 * with a nonce, and the connect flow's return redeems the token at
 * `/plugins/connect/woocommerce/finish`, saves the store's keys and turns
 * the gateway on.
 *
 * @package Monokulo
 */

declare(strict_types=1);

class ConnectTest extends MonokuloTestCase {

	const ADDRESS = 'https://monokulo.test';

	const FINISH = array(
		'public_key'             => 'pk_connected',
		'secret_token'           => 'sk_connected',
		'endpoint'               => 'https://pay.monokulo.test',
		'onion_endpoint'         => 'http://2gzyxa5ihm7nsggfxnu52rck2vv4rvmdlkiu3zzui5du4xyclen53wid.onion',
		'webhook_signing_secret' => 'whsec_connected',
	);

	public function set_up(): void {
		parent::set_up();
		self::gateway( array( 'address' => self::ADDRESS ) );
	}

	/**
	 * Renders the Connect button; returns its HTML and the nonce in its link.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function render_connect(): array {
		$gateway = new WC_Gateway_Monokulo();
		$html    = $gateway->generate_monokulo_connect_html( 'connect', $gateway->get_form_fields()['connect'] );
		$this->assertMatchesRegularExpression( '/nonce=([0-9a-f]{32})/', $html );
		preg_match( '/nonce=([0-9a-f]{32})/', $html, $m );
		return array( $html, $m[1] );
	}

	private function connect_return( array $query ): string {
		return ( new WC_Gateway_Monokulo() )->process_connect_return( $query );
	}

	public function test_connect_links_to_the_monokulo_address_with_a_nonce_it_keeps() {
		list( $html, $nonce ) = $this->render_connect();
		$link = html_entity_decode( (string) preg_replace( '/.*href="([^"]+)".*/s', '$1', $html ) );

		$this->assertStringStartsWith( self::ADDRESS . '/plugins/connect/woocommerce?', $link );
		parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $query );
		$this->assertSame( home_url( '/' ), $query['site_url'] );
		$this->assertSame( admin_url( 'admin-post.php?action=monokulo_connect_return' ), $query['return_url'] );
		$this->assertSame( 'WooCommerce', $query['name'] );
		$this->assertSame( $nonce, $query['nonce'] );
		$this->assertStringContainsString( 'Connects to <code>' . self::ADDRESS . '</code>', (string) preg_replace( '/\s+/', ' ', $html ) );
	}

	public function test_connecting_saves_the_store_and_turns_monero_on() {
		list( , $nonce ) = $this->render_connect();
		$this->monokulo_answers( self::response( 200, self::FINISH ) );

		$back = $this->connect_return( array( 'token' => 'conn_token', 'nonce' => $nonce ) );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( self::ADDRESS . '/plugins/connect/woocommerce/finish', $this->sent[0]['url'] );
		$this->assertSame( WC_Gateway_Monokulo::CLIENT, $this->sent[0]['args']['headers']['Monokulo-Client'] );
		$this->assertSame(
			array(
				'token'       => 'conn_token',
				'webhook_url' => rest_url( 'monokulo/v1/webhook' ),
				'name'        => 'WooCommerce',
			),
			json_decode( $this->sent[0]['args']['body'], true )
		);
		$this->assertStringContainsString( 'section=monokulo', $back );
		$this->assertStringContainsString( 'monokulo_connected=1', $back );

		$saved = new WC_Gateway_Monokulo();
		foreach ( array( 'endpoint', 'onion_endpoint', 'public_key', 'secret_token', 'webhook_signing_secret' ) as $key ) {
			$this->assertSame( self::FINISH[ $key ], $saved->get_option( $key ), $key );
		}
		$this->assertSame( 'yes', $saved->enabled );
		$this->assertTrue( $saved->is_available() );

		$html = $saved->generate_monokulo_connect_html( 'connect', array() );
		$this->assertStringContainsString( '<code>pk_connected</code>', $html );
	}

	public function test_an_onion_endpoint_that_is_not_an_http_onion_address_is_dropped() {
		foreach ( array( 'https://abc.onion', 'http://pay.example.com', 'javascript:alert(1)' ) as $onion ) {
			list( , $nonce ) = $this->render_connect();
			$this->monokulo_answers( self::response( 200, array( 'onion_endpoint' => $onion ) + self::FINISH ) );
			$this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) );
			$this->assertSame( '', ( new WC_Gateway_Monokulo() )->get_option( 'onion_endpoint' ), $onion );
		}
	}

	public function test_a_failed_finish_keeps_the_store_as_it_was() {
		$answers = array(
			'refused'     => self::response( 401 ),
			'unreachable' => new WP_Error( 'http_request_failed', 'cURL error 28' ),
			'garbled'     => self::response( 200, '<html>502 Bad Gateway</html>' ),
			'incomplete'  => self::response( 200, array( 'public_key' => 'pk_x' ) ),
		);
		self::gateway(
			array(
				'endpoint'   => 'https://old.example',
				'public_key' => 'pk_old',
				'enabled'    => 'no',
			)
		);
		foreach ( $answers as $case => $answer ) {
			list( , $nonce ) = $this->render_connect();
			$this->monokulo_answers( $answer );
			$this->assertStringContainsString( 'monokulo_connect_error=finish', $this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) ), $case );
			$after = new WC_Gateway_Monokulo();
			$this->assertSame( 'pk_old', $after->get_option( 'public_key' ), $case );
			$this->assertSame( 'https://old.example', $after->get_option( 'endpoint' ), $case );
			$this->assertSame( 'no', $after->enabled, $case );
		}
	}

	public function test_a_monokulo_not_ready_for_plugins_says_so() {
		list( , $nonce ) = $this->render_connect();
		$this->monokulo_answers( self::response( 503, array( 'error' => 'no public address yet' ) ) );
		$this->assertStringContainsString( 'monokulo_connect_error=unavailable', $this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) ) );
		$this->assertSame( '', ( new WC_Gateway_Monokulo() )->get_option( 'public_key' ) );
	}

	public function test_a_wrong_missing_or_reused_nonce_is_refused_before_finish() {
		list( , $nonce ) = $this->render_connect();
		$this->monokulo_answers( self::response( 200, self::FINISH ) );

		foreach ( array(
			array( 'token' => 't', 'nonce' => str_repeat( 'a', 32 ) ),
			array( 'nonce' => $nonce ),
			array( 'token' => 't' ),
		) as $query ) {
			list( , $nonce ) = $this->render_connect();
			$this->assertStringContainsString( 'monokulo_connect_error=nonce', $this->connect_return( $query ) );
		}
		$this->assertSame( array(), $this->sent );

		list( , $nonce ) = $this->render_connect();
		$this->assertStringContainsString( 'monokulo_connected=1', $this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) ) );
		$this->assertStringContainsString( 'monokulo_connect_error=nonce', $this->connect_return( array( 'token' => 't2', 'nonce' => $nonce ) ), 'used once' );
		$this->assertCount( 1, $this->sent );
	}

	public function test_only_shop_managers_reach_the_connect_return() {
		self::gateway( array( 'public_key' => 'pk_existing' ) );
		self::rebuild_gateways();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$_GET = array(
			'token' => 'forged',
			'nonce' => 'guessed',
		);
		try {
			do_action( 'admin_post_monokulo_connect_return' );
			$this->fail( 'A customer must not reach the connect return.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'You do not have permission to do this.', $e->getMessage() );
		}
		$this->assertSame( array(), $this->sent );
		$this->assertSame( 'pk_existing', ( new WC_Gateway_Monokulo() )->get_option( 'public_key' ) );
	}

	public function test_connect_goes_to_monokulo_com_until_another_address_is_saved() {
		delete_option( 'woocommerce_monokulo_settings' );
		list( $html ) = $this->render_connect();
		$this->assertStringContainsString( 'https://monokulo.com/plugins/connect/woocommerce?', $html );
	}

	public function test_the_monokulo_address_is_cleaned_or_refused_on_save() {
		$gateway = new WC_Gateway_Monokulo();
		$this->assertSame( 'https://mine.example/monokulo', $gateway->validate_address_field( 'address', "  https://mine.example/monokulo/ \n" ) );
		$this->assertSame( 'http://localhost:8081', $gateway->validate_address_field( 'address', 'http://localhost:8081' ) );
		foreach ( array( '', 'monokulo.com', 'ftp://monokulo.com', 'javascript:alert(1)', 'https://' ) as $typed ) {
			$this->assertSame( self::ADDRESS, $gateway->validate_address_field( 'address', $typed ), $typed );
		}
	}

	public function test_the_settings_page_says_how_connecting_went() {
		$notice = static function ( array $query ): string {
			$_GET = $query + array(
				'page'    => 'wc-settings',
				'section' => 'monokulo',
			);
			ob_start();
			monokulo_connect_notice();
			return (string) ob_get_clean();
		};
		$this->assertStringContainsString( 'Your Monero wallet is connected', $notice( array( 'monokulo_connected' => '1' ) ) );
		$this->assertStringContainsString( 'has not set its public address', $notice( array( 'monokulo_connect_error' => 'unavailable' ) ) );
		$this->assertStringContainsString( 'Could not connect your Monero wallet', $notice( array( 'monokulo_connect_error' => 'finish' ) ) );
		$this->assertStringNotContainsString( 'Monero', $notice( array( 'section' => 'bacs', 'monokulo_connected' => '1' ) ) );
	}
}
