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
		$this->monokulo_answers( self::response( 200, Monokulo_Api_Fixtures::finish() ) );

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
		foreach ( array( 'public_key', 'secret_token', 'webhook_signing_secret' ) as $key ) {
			$this->assertSame( Monokulo_Api_Fixtures::finish()[ $key ], $saved->get_option( $key ), $key );
		}
		$this->assertSame( self::ADDRESS . '/api/v1', $saved->get_option( 'api' ), 'The entry, resolved against the address /finish was called at.' );
		$this->assertSame( 'yes', $saved->enabled );
		$this->assertTrue( $saved->is_available() );

		$this->assertSame( '', $saved->get_option( 'onion_api' ), 'No onion address.' );

		$html = $saved->generate_monokulo_connect_html( 'connect', array() );
		$this->assertStringContainsString( '<code>pk_connected</code>', $html );
		$this->assertStringNotContainsString( 'onion', $html );
	}

	/**
	 * A Monokulo with an onion address gives it as `onion_api`: kept, and
	 * shown on the settings page. Connecting again to a Monokulo without
	 * one clears it.
	 */
	public function test_connecting_keeps_the_onion_api() {
		list( , $nonce ) = $this->render_connect();
		$this->monokulo_answers( self::response( 200, Monokulo_Api_Fixtures::finish( '', true ) ) );
		$this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) );

		$saved = new WC_Gateway_Monokulo();
		$this->assertSame( Monokulo_Api_Fixtures::ONION . '/api/v1', $saved->get_option( 'onion_api' ) );
		$this->assertSame( self::ADDRESS . '/api/v1', $saved->get_option( 'api' ), 'Orders are still made on the address the shop called.' );
		$this->assertStringContainsString( '<code>' . wp_parse_url( Monokulo_Api_Fixtures::ONION, PHP_URL_HOST ) . '</code>', $saved->generate_monokulo_connect_html( 'connect', array() ) );

		list( , $nonce ) = $this->render_connect();
		$this->monokulo_answers( self::response( 200, Monokulo_Api_Fixtures::finish() ) );
		$this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) );
		$this->assertSame( '', ( new WC_Gateway_Monokulo() )->get_option( 'onion_api' ) );
	}

	/**
	 * A Monokulo served under a path: Connect and `/finish` go under the
	 * address as saved, path and all, and the entry `/finish` gives (with
	 * the path) is resolved against it.
	 */
	public function test_a_monokulo_under_a_path_is_connected_at_its_path() {
		self::gateway( array( 'address' => 'https://example.com/monokulo/' ) );
		list( $html, $nonce ) = $this->render_connect();
		$this->assertStringContainsString( 'href="https://example.com/monokulo/plugins/connect/woocommerce?', $html );

		$this->monokulo_answers( self::response( 200, Monokulo_Api_Fixtures::finish( '/monokulo' ) ) );
		$this->assertStringContainsString( 'monokulo_connected=1', $this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) ) );
		$this->assertSame( 'https://example.com/monokulo/plugins/connect/woocommerce/finish', $this->sent[0]['url'] );
		$this->assertSame( 'https://example.com/monokulo/api/v1', ( new WC_Gateway_Monokulo() )->get_option( 'api' ) );
	}

	/**
	 * Connecting again drops the entry kept for the old connection.
	 */
	public function test_connecting_again_reads_the_entry_afresh() {
		update_option( WC_Gateway_Monokulo::ENTRY_OPTION, array( 'for' => 'old' ), false );
		list( , $nonce ) = $this->render_connect();
		$this->monokulo_answers( self::response( 200, Monokulo_Api_Fixtures::finish() ) );
		$this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) );
		$this->assertFalse( get_option( WC_Gateway_Monokulo::ENTRY_OPTION ) );
	}

	public function test_an_absolute_api_entry_is_kept_as_it_is() {
		list( , $nonce ) = $this->render_connect();
		$this->monokulo_answers( self::response( 200, array( 'api' => 'https://api.monokulo.test/api/v1' ) + Monokulo_Api_Fixtures::finish() ) );
		$this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) );
		$this->assertSame( 'https://api.monokulo.test/api/v1', ( new WC_Gateway_Monokulo() )->get_option( 'api' ) );
	}

	/**
	 * A shop connected by 0.3.0 has its keys and entry but no `onion_api`
	 * (until it connects again) and kept its entry in a transient: it
	 * keeps taking orders, its customers on Tor included, as each order
	 * carries its own onion checkout.
	 */
	public function test_a_shop_connected_by_0_3_0_keeps_working() {
		update_option(
			'woocommerce_monokulo_settings',
			array(
				'enabled'                => 'yes',
				'address'                => 'https://pay.example.com',
				'api'                    => self::API,
				'public_key'             => 'pk_old',
				'secret_token'           => 'sk_old',
				'webhook_signing_secret' => 'whsec_old',
			)
		);
		set_transient( 'monokulo_entry_' . md5( self::API . "\nsk_old" ), Monokulo_Api_Fixtures::entry(), HOUR_IN_SECONDS );

		$gateway = new WC_Gateway_Monokulo();
		$this->assertTrue( $gateway->is_available() );
		$this->assertSame( '', $gateway->get_option( 'onion_api' ) );

		$this->monokulo_serves(
			array(
				'GET ' . self::API                          => self::entry_response( Monokulo_Api_Fixtures::entry( '', true ) ),
				'POST https://pay.example.com/api/v1/orders' => self::response( 201, Monokulo_Api_Fixtures::order( 'order_1', '', true ) ),
			)
		);
		$this->assertSame( 'https://pay.example.com/checkout/order_1', $gateway->process_payment( self::order()->get_id() )['redirect'] );
		$_SERVER['HTTP_HOST'] = 'shop.onion';
		$this->assertSame( Monokulo_Api_Fixtures::ONION . '/checkout/order_1', $gateway->process_payment( self::order()->get_id() )['redirect'] );
		$this->assertSame( 'Bearer sk_old', $this->sent[0]['args']['headers']['Authorization'] );
	}

	/**
	 * Every refusal of `/finish` is a problem: its name is the settings
	 * page's error and its detail is shown there; nothing changes.
	 */
	public function test_a_failed_finish_keeps_the_store_as_it_was() {
		$answers = array();
		foreach ( Monokulo_Api_Fixtures::FINISH_PROBLEMS as $name => $recorded ) {
			$answers[ $name ] = self::response( $recorded['status'], $recorded, 'rate-limited' === $name ? array( 'retry-after' => '120' ) : array() );
		}
		$answers += array(
			'unreachable'            => new WP_Error( 'http_request_failed', 'cURL error 28' ),
			'garbled'                => self::response( 200, '<html>502 Bad Gateway</html>' ),
			'incomplete'             => self::response( 200, array( 'public_key' => 'pk_x' ) ),
			'not a problem'          => self::response( 502, '<html>502 Bad Gateway</html>' ),
		);
		self::gateway(
			array(
				'api'        => 'https://old.example/api/v1',
				'public_key' => 'pk_old',
				'enabled'    => 'no',
			)
		);
		foreach ( $answers as $case => $answer ) {
			list( , $nonce ) = $this->render_connect();
			$this->monokulo_answers( $answer );
			$error = in_array( $case, array( 'garbled', 'incomplete', 'not a problem' ), true ) ? 'finish' : $case;
			$this->assertStringContainsString( 'monokulo_connect_error=' . $error . '&', $this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) ) . '&', $case );
			$after = new WC_Gateway_Monokulo();
			$this->assertSame( 'pk_old', $after->get_option( 'public_key' ), $case );
			$this->assertSame( 'https://old.example/api/v1', $after->get_option( 'api' ), $case );
			$this->assertSame( 'no', $after->enabled, $case );
		}
		$this->assertTrue( $this->logged( 'returned HTTP 429: Too many requests: Rate limit exceeded; retry in 120 s. (https://docs.monokulo.com/problems/rate-limited) (retry after 120 s)' ) );
	}

	public function test_a_wrong_missing_or_reused_nonce_is_refused_before_finish() {
		list( , $nonce ) = $this->render_connect();
		$this->monokulo_answers( self::response( 200, Monokulo_Api_Fixtures::finish() ) );

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

	public function test_connect_links_open_in_two_tabs_both_work_for_the_admin_who_opened_them() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		list( , $first )  = $this->render_connect();
		list( , $second ) = $this->render_connect();
		$this->monokulo_answers( self::response( 200, Monokulo_Api_Fixtures::finish() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertStringContainsString( 'monokulo_connect_error=nonce', $this->connect_return( array( 'token' => 't', 'nonce' => $first ) ), 'another admin' );

		wp_set_current_user( $admin );
		$this->assertStringContainsString( 'monokulo_connected=1', $this->connect_return( array( 'token' => 't', 'nonce' => $second ) ) );
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

	public function test_connect_goes_to_www_monokulo_com_until_another_address_is_saved() {
		delete_option( 'woocommerce_monokulo_settings' );
		list( $html ) = $this->render_connect();
		$this->assertStringContainsString( 'https://www.monokulo.com/plugins/connect/woocommerce?', $html );
	}

	public function test_the_monokulo_address_is_cleaned_or_refused_on_save() {
		$gateway = new WC_Gateway_Monokulo();
		$this->assertSame( 'https://mine.example/monokulo', $gateway->validate_address_field( 'address', "  https://mine.example/monokulo/ \n" ) );
		$this->assertSame( 'http://localhost:8081', $gateway->validate_address_field( 'address', 'http://localhost:8081' ) );
		foreach ( array( '', 'monokulo.com', 'ftp://monokulo.com', 'javascript:alert(1)', 'https://', 'https://me:pw@monokulo.com', 'https://monokulo.com/?x=1', 'https://monokulo.com/#top' ) as $typed ) {
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
		$this->assertStringContainsString( 'Your store is connected.', $notice( array( 'monokulo_connected' => '1' ) ) );
		$this->assertStringContainsString( 'has not set its public address', $notice( array( 'monokulo_connect_error' => 'no-public-address' ) ) );
		$this->assertStringContainsString( 'could not be reached', $notice( array( 'monokulo_connect_error' => 'unreachable' ) ) );
		$this->assertStringContainsString( 'already used or has expired', $notice( array( 'monokulo_connect_error' => 'nonce' ) ) );
		$this->assertStringContainsString( 'did not take the connect code', $notice( array( 'monokulo_connect_error' => 'invalid-connect-code' ) ) );
		$this->assertStringContainsString( 'Monokulo is busy', $notice( array( 'monokulo_connect_error' => 'rate-limited' ) ) );
		$this->assertStringContainsString( 'did not hand over the store', $notice( array( 'monokulo_connect_error' => 'finish' ) ) );
		$this->assertStringContainsString( 'Something went wrong on your Monokulo', $notice( array( 'monokulo_connect_error' => 'internal' ) ) );
		$this->assertStringContainsString( 'did not hand over the store', $notice( array( 'monokulo_connect_error' => 'not-found' ) ) );
		$this->assertStringNotContainsString( 'Monero', $notice( array( 'section' => 'bacs', 'monokulo_connected' => '1' ) ) );
	}

	/**
	 * What Monokulo said is shown to the admin who tried, once.
	 */
	public function test_the_settings_page_shows_monokulos_detail_once() {
		list( , $nonce ) = $this->render_connect();
		$this->monokulo_answers( self::response( 400, Monokulo_Api_Fixtures::problem( 400, 'invalid-request', 'Bad request', 'webhook_url is not an http(s) URL' ) ) );
		$back = $this->connect_return( array( 'token' => 't', 'nonce' => $nonce ) );
		parse_str( (string) wp_parse_url( $back, PHP_URL_QUERY ), $_GET );

		ob_start();
		monokulo_connect_notice();
		$this->assertStringContainsString( 'Monokulo says: webhook_url is not an http(s) URL', (string) ob_get_clean() );
		ob_start();
		monokulo_connect_notice();
		$shown = (string) ob_get_clean();
		$this->assertStringContainsString( 'did not hand over', $shown );
		$this->assertStringNotContainsString( 'Monokulo says', $shown );
	}
}
