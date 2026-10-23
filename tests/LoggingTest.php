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
		$this->monokulo_answers( self::response( 204 ) );
		$logs = 'https://pay.example.com/pay/pk_test/logs';

		self::connected_gateway()->process_webhook( '{}', 'bad' );
		WC_Gateway_Monokulo::flush_forwarded( $logs, 'sk_test' );
		$this->assertSame( array(), $this->sent, 'Off by default.' );

		self::connected_gateway( array( 'forward_errors' => 'yes' ) )->process_webhook( '{}', 'bad' );
		WC_Gateway_Monokulo::flush_forwarded( $logs, 'sk_test' );
		$this->assertCount( 1, $this->sent );
		$sent = $this->sent[0];
		$this->assertSame( $logs, $sent['url'] );
		$this->assertSame( 'Bearer sk_test', $sent['args']['headers']['Authorization'] );
		$this->assertSame( WC_Gateway_Monokulo::CLIENT, $sent['args']['headers']['Monokulo-Client'] );
		$this->assertFalse( $sent['args']['blocking'], 'No page waits for it.' );
		$entry = json_decode( $sent['args']['body'], true )['entries'][0];
		$this->assertSame( 'warning', $entry['level'] );
		$this->assertStringContainsString( 'Webhook refused', $entry['message'] );
		$this->assertSame( WC_Gateway_Monokulo::trace_id(), $entry['trace_id'] );

		WC_Gateway_Monokulo::flush_forwarded( $logs, 'sk_test' );
		$this->assertCount( 1, $this->sent, 'Sent once.' );
	}
}
