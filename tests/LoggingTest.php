<?php
/**
 * Logs: one W3C trace per PHP request, sent to Monokulo with each call and
 * written on each log line, and "Send errors to Monokulo".
 *
 * @package Monokulo
 */

declare(strict_types=1);

class LoggingTest extends MonokuloTestCase {

	public function test_a_traceparent_names_this_requests_trace_and_a_new_span_each_time() {
		$first  = WC_Gateway_Monokulo::traceparent();
		$second = WC_Gateway_Monokulo::traceparent();
		$this->assertMatchesRegularExpression( '/\A00-[0-9a-f]{32}-[0-9a-f]{16}-01\z/', $first );
		$this->assertSame( substr( $first, 3, 32 ), substr( $second, 3, 32 ) );
		$this->assertNotSame( substr( $first, 36, 16 ), substr( $second, 36, 16 ) );
		$this->assertSame( WC_Gateway_Monokulo::trace_id(), substr( $first, 3, 32 ) );
	}

	public function test_an_incoming_traceparent_is_adopted_only_when_well_formed() {
		$this->assertTrue( WC_Gateway_Monokulo::adopt_traceparent( '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01' ) );
		$this->assertSame( '4bf92f3577b34da6a3ce929d0e0e4736', WC_Gateway_Monokulo::trace_id() );
		foreach ( array(
			'',
			'garbage',
			'00-00000000000000000000000000000000-00f067aa0ba902b7-01',
			'00-5bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01',
			'00-5BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01',
			'01-5bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
			"00-5bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01\nX-Injected: 1",
		) as $bad ) {
			$this->assertFalse( WC_Gateway_Monokulo::adopt_traceparent( $bad ), $bad );
		}
		$this->assertSame( '4bf92f3577b34da6a3ce929d0e0e4736', WC_Gateway_Monokulo::trace_id() );
	}

	public function test_log_lines_carry_the_trace_id() {
		$this->deliver( '{}', '' );
		$this->assertTrue( $this->logged( '[trace ' . WC_Gateway_Monokulo::trace_id() . ']' ) );
	}

	public function test_warnings_go_to_monokulo_only_when_the_merchant_turned_that_on() {
		$this->monokulo_serves();
		$logs = 'https://pay.example.com/api/v1/logs';

		$gateway = self::connected_gateway();
		$gateway->process_webhook( '{}', 'bad' );
		$gateway->flush_forwarded();
		$this->assertSame( array(), $this->sent, 'Off by default.' );

		$gateway = self::connected_gateway( array( 'forward_errors' => 'yes' ) );
		$gateway->process_webhook( '{}', 'bad' );
		$gateway->flush_forwarded();
		$this->assertSame( array( self::API, $logs ), wp_list_pluck( $this->sent, 'url' ), 'The entry\'s send-logs form.' );
		$sent = $this->sent[1];
		$this->assertSame( 'POST', $sent['args']['method'] );
		$this->assertSame( 'Bearer sk_test', $sent['args']['headers']['Authorization'] );
		$this->assertSame( WC_Gateway_Monokulo::CLIENT, $sent['args']['headers']['Monokulo-Client'] );
		$this->assertFalse( $sent['args']['blocking'], 'No page waits for it.' );
		$entry = json_decode( $sent['args']['body'], true )['entries'][0];
		$this->assertSame( 'warning', $entry['level'] );
		$this->assertStringContainsString( 'Webhook refused', $entry['message'] );
		$this->assertSame( WC_Gateway_Monokulo::trace_id(), $entry['trace_id'] );

		$gateway->flush_forwarded();
		$this->assertCount( 2, $this->sent, 'Sent once.' );

		$gateway->process_webhook( '{}', 'bad' );
		$gateway->flush_forwarded();
		$this->assertSame( array( self::API, $logs, $logs ), wp_list_pluck( $this->sent, 'url' ), 'The kept entry: no call to read it again.' );
	}

	/**
	 * Logs go where the entry's form points, under any path Monokulo is
	 * served at: the plugin builds no address for them.
	 */
	public function test_warnings_go_where_the_send_logs_form_points() {
		$api   = 'https://example.com/monokulo/api/v1';
		$entry = Monokulo_Api_Fixtures::entry( '/monokulo' );
		$entry['_templates']['send-logs']['target'] = '/monokulo/reports';
		$this->monokulo_serves( array( 'GET ' . $api => self::entry_response( $entry ) ) );
		$gateway = self::connected_gateway(
			array(
				'api'            => $api,
				'forward_errors' => 'yes',
			)
		);
		$gateway->process_webhook( '{}', 'bad' );
		$gateway->flush_forwarded();
		$this->assertSame( array( $api, 'https://example.com/monokulo/reports' ), wp_list_pluck( $this->sent, 'url' ) );
	}

	/**
	 * With no `send-logs` form for the key, or no Monokulo to read the
	 * entry from, the entries stay in WooCommerce's log only, and what
	 * that logs isn't sent either.
	 */
	public function test_warnings_that_cannot_be_sent_stay_in_the_shops_log() {
		$bare = Monokulo_Api_Fixtures::entry();
		unset( $bare['_templates']['send-logs'] );
		foreach ( array(
			'no form'     => self::entry_response( $bare ),
			'unreachable' => new WP_Error( 'http_request_failed', 'cURL error 7' ),
		) as $case => $answer ) {
			delete_option( WC_Gateway_Monokulo::ENTRY_OPTION );
			$this->sent = array();
			$this->monokulo_serves( array( 'GET ' . self::API => $answer ) );
			$gateway = self::connected_gateway( array( 'forward_errors' => 'yes' ) );
			$gateway->process_webhook( '{}', 'bad' );
			$gateway->flush_forwarded();
			$gateway->flush_forwarded();
			$this->assertSame( array( self::API ), wp_list_pluck( $this->sent, 'url' ), $case );
			$this->assertSame( 3, $this->sent[0]['args']['timeout'], $case . ': read briefly.' );
		}
	}
}
