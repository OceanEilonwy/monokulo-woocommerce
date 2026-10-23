<?php
/**
 * What the plugin's tests share: a connected gateway, Monokulo's answers
 * (through `pre_http_request`), WooCommerce's log, orders and webhooks.
 *
 * @package Monokulo
 */

declare(strict_types=1);

/**
 * Base for the plugin's tests, each in a rolled-back transaction.
 */
abstract class MonokuloTestCase extends WP_UnitTestCase {

	const WEBHOOK_SECRET = 'whsec_test';

	/**
	 * Requests the plugin sent, oldest first.
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	protected array $sent = array();

	/**
	 * Log lines, as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	protected array $logged = array();

	public function set_up(): void {
		parent::set_up();
		$this->sent   = array();
		$this->logged = array();
		$logged       = &$this->logged;
		add_filter(
			'woocommerce_logging_class',
			static function () use ( &$logged ) {
				return new class( $logged ) extends WC_Logger {
					private array $entries;
					public function __construct( array &$entries ) {
						$this->entries = &$entries;
					}
					public function log( $level, $message, $context = array() ) {
						$this->entries[] = array( $level, $message );
					}
				};
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'woocommerce_logging_class' );
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Answers every request the plugin sends with `$answer` (a response, or
	 * a WP_Error for an unreachable Monokulo), recording each in `$sent`.
	 *
	 * @param array|WP_Error $answer The answer.
	 */
	protected function monokulo_answers( $answer ): void {
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $answer ) {
				$this->sent[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $answer;
			},
			10,
			3
		);
	}

	/**
	 * An HTTP response with a JSON (or raw) body.
	 *
	 * @param int          $code Status.
	 * @param array|string $body JSON body, or a raw one.
	 */
	protected static function response( int $code, array|string $body = '' ): array {
		return array(
			'headers'  => array(),
			'body'     => is_array( $body ) ? wp_json_encode( $body ) : $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Saves gateway settings, and returns a gateway built from them.
	 *
	 * @param array<string, string> $settings Settings to save.
	 */
	protected static function gateway( array $settings = array() ): WC_Gateway_Monokulo {
		$seed = new WC_Gateway_Monokulo();
		foreach ( $settings as $key => $value ) {
			$seed->update_option( $key, $value );
		}
		return new WC_Gateway_Monokulo();
	}

	/**
	 * A gateway connected as the connect flow leaves it.
	 *
	 * @param array<string, string> $settings Settings to save on top.
	 */
	protected static function connected_gateway( array $settings = array() ): WC_Gateway_Monokulo {
		return self::gateway(
			$settings + array(
				'enabled'                => 'yes',
				'endpoint'               => 'https://pay.example.com',
				'public_key'             => 'pk_test',
				'secret_token'           => 'sk_test',
				'webhook_signing_secret' => self::WEBHOOK_SECRET,
			)
		);
	}

	/**
	 * A saved $42.50 order, with the given item names.
	 *
	 * @param string[] $items Item names.
	 */
	protected static function order( array $items = array() ): WC_Order {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( '42.50' );
		foreach ( $items as $name ) {
			$item = new WC_Order_Item_Product();
			$item->set_name( $name );
			$order->add_item( $item );
		}
		$order->save();
		return $order;
	}

	/**
	 * An order whose customer was sent to pay Monokulo order `$monokulo_order_id`.
	 *
	 * @param string $monokulo_order_id Monokulo's order id.
	 * @param string $status            The order's status.
	 */
	protected static function order_paying( string $monokulo_order_id, string $status = 'pending' ): WC_Order {
		$order = self::order();
		$order->set_status( $status );
		$order->update_meta_data( WC_Gateway_Monokulo::META_ORDER_ID, $monokulo_order_id );
		$order->add_meta_data( WC_Gateway_Monokulo::META_ORDER_IDS, $monokulo_order_id );
		$order->save();
		return $order;
	}

	/**
	 * Posts a webhook delivery to the plugin's REST route, as Monokulo
	 * does, and returns the status it answered.
	 *
	 * @param string      $body      The raw body.
	 * @param string|null $signature The signature header; signed now with the test secret if null, none if ''.
	 * @param array       $headers   More headers.
	 */
	protected function deliver( string $body, ?string $signature = null, array $headers = array() ): int {
		$request = new WP_REST_Request( 'POST', '/monokulo/v1/webhook' );
		$request->set_body( $body );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( '' !== $signature ) {
			$request->set_header( 'X-Monokulo-Signature', $signature ?? monokulo_test_signature( $body, self::WEBHOOK_SECRET ) );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		self::rebuild_gateways();
		return rest_do_request( $request )->get_status();
	}

	/**
	 * Has WooCommerce build its gateways again from the saved settings, as
	 * on a new request.
	 */
	protected static function rebuild_gateways(): void {
		WC()->payment_gateways()->init();
	}

	/**
	 * A webhook body: `$fields` with a fresh event id.
	 *
	 * @param array<string, mixed> $fields The event's fields.
	 */
	protected static function event( array $fields ): string {
		return (string) wp_json_encode(
			$fields + array(
				'api_version' => 2,
				'event_id'    => 'evt_' . wp_generate_password( 12, false ),
				'created_at'  => time(),
			)
		);
	}

	/**
	 * The order's notes, newest first, joined.
	 *
	 * @param WC_Order $order The order.
	 */
	protected static function notes( WC_Order $order ): string {
		return implode( ' | ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	/**
	 * Whether a log line contains `$needle`.
	 *
	 * @param string $needle Text to look for.
	 */
	protected function logged( string $needle ): bool {
		foreach ( $this->logged as $entry ) {
			if ( str_contains( $entry[1], $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
