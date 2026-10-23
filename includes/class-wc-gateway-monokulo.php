<?php
/**
 * The Monokulo payment gateway.
 *
 * @package Monokulo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monero at checkout through Monokulo.
 *
 * - Connect (`handle_connect_return()`) stores the store's keys and the
 *   API entry Monokulo hands back (`/api/v1`, and its onion address when it
 *   has one), and turns the gateway on.
 * - At checkout `process_payment()` makes the order with the entry's
 *   `create-order` form (the entry is kept as its `Cache-Control` says),
 *   and sends the customer to the order's `mk:checkout` link to pay, or to
 *   its `mk:onion-checkout` when they came to the shop over Tor. Every URL
 *   past the entry is a link Monokulo gives.
 * - Monokulo's webhooks (`process_webhook()`) move the WooCommerce order.
 *
 * Monokulo prices the order and watches the chain; the plugin never decides
 * on its own that an order is paid.
 */
class WC_Gateway_Monokulo extends WC_Payment_Gateway {

	/**
	 * The gateway's id: its settings are `woocommerce_monokulo_settings`.
	 */
	const ID = 'monokulo';

	/**
	 * The plugin's version. Kept equal to VERSION and the plugin header by a test.
	 */
	const VERSION = '0.4.0';

	/**
	 * The `Monokulo-Client` header: `woocommerce/<version>`.
	 */
	const CLIENT = 'woocommerce/' . self::VERSION;

	/**
	 * Where the "Monokulo address" setting starts: the public Monokulo.
	 */
	const DEFAULT_MONOKULO_ADDRESS = 'https://www.monokulo.com';

	/**
	 * The connect path's slug: `/plugins/connect/woocommerce`.
	 */
	const CONNECT_SLUG = 'woocommerce';

	/**
	 * What Monokulo calls the plugin on its pages.
	 */
	const CONNECT_NAME = 'WooCommerce';

	/**
	 * The `admin-post.php` action the connect flow comes back to.
	 */
	const CONNECT_RETURN_ACTION = 'monokulo_connect_return';

	/**
	 * The longest order description Monokulo accepts, in characters.
	 */
	const MAX_DESCRIPTION_CHARS = 140;

	/**
	 * The API's media type: HAL-FORMS.
	 */
	const HAL_FORMS = 'application/prs.hal-forms+json';

	/**
	 * The option the API entry is kept in, with its `ETag` and when it
	 * expires, for the key it was read with.
	 */
	const ENTRY_OPTION = 'monokulo_api_entry';

	/**
	 * How far, in seconds, a webhook's signed time may be from now.
	 */
	const SIGNATURE_TOLERANCE_SECONDS = 300;

	/**
	 * Order meta: the Monokulo order the customer was last sent to pay.
	 */
	const META_ORDER_ID = '_monokulo_order_id';

	/**
	 * Order meta, one row each: every Monokulo order made for the order. A
	 * changed total makes a new one.
	 */
	const META_ORDER_IDS = '_monokulo_order_ids';

	/**
	 * Order meta: each Monokulo order's page on the merchant's dashboard
	 * (its `mk:dashboard-order` link), as a JSON object by Monokulo order id.
	 */
	const META_DASHBOARD_LINKS = '_monokulo_dashboard_links';

	/**
	 * Order meta: the webhook events already applied, as a JSON list.
	 */
	const META_APPLIED_EVENT_IDS = '_monokulo_applied_webhook_event_ids';

	/**
	 * Most log entries sent to Monokulo per request: its `send-logs` form
	 * takes no more.
	 */
	const MAX_FORWARDED = 20;

	/**
	 * Log levels sent to Monokulo.
	 */
	const FORWARDED_LEVELS = array( 'warning', 'error', 'critical', 'alert', 'emergency' );

	/**
	 * The WooCommerce status each unpaid Monokulo event moves an order to.
	 */
	const STATUS_MAP = array(
		'order.pending'     => 'pending',
		'order.unconfirmed' => 'on-hold',
		'order.confirming'  => 'on-hold',
		'order.partial'     => 'on-hold',
		'order.expired'     => 'cancelled',
	);

	/**
	 * The API's entry (`https://…/api/v1`), where every other URL is linked
	 * from; empty until connected.
	 *
	 * @var string
	 */
	private string $api;

	/**
	 * The same entry on Monokulo's onion address, as the handshake gave it;
	 * empty when it has none, or for a shop connected before 0.4.0.
	 *
	 * @var string
	 */
	private string $onion_api;

	/**
	 * The store's public key (`pk_…`).
	 *
	 * @var string
	 */
	private string $public_key;

	/**
	 * The store's secret key (`sk_…`), sent only to Monokulo.
	 *
	 * @var string
	 */
	private string $secret_token;

	/**
	 * The key webhook deliveries are signed with (`whsec_…`).
	 *
	 * @var string
	 */
	private string $webhook_signing_secret;

	/**
	 * This PHP request's trace id (W3C Trace Context), sent to Monokulo and
	 * written on every log line. Null until first used.
	 *
	 * @var string|null
	 */
	private static ?string $trace_id = null;

	/**
	 * Warnings and errors to send to Monokulo when this request ends.
	 *
	 * @var array<int, array{level: string, message: string, trace_id: string}>
	 */
	private static array $forward_queue = array();

	/**
	 * Whether the send at the end of the request is registered.
	 *
	 * @var bool
	 */
	private static bool $forward_registered = false;

	/**
	 * Whether the queue is being sent: what that logs isn't queued again.
	 *
	 * @var bool
	 */
	private static bool $forwarding = false;

	/**
	 * Whether the entry was read from Monokulo for the form being sent
	 * (rather than kept), so a refused form isn't worth reading it again.
	 *
	 * @var bool
	 */
	private bool $entry_read_now = false;

	/**
	 * Sets the gateway up from its saved settings.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->icon               = plugins_url( 'assets/monokulo-icon.svg', MONOKULO_PLUGIN_FILE );
		$this->has_fields         = false;
		$this->method_title       = __( 'Monokulo', 'monokulo' );
		$this->method_description = __( 'Take Monero at checkout through Monokulo. Monokulo only ever holds a view key: payments go straight to your wallet.', 'monokulo' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title                  = (string) $this->get_option( 'title' );
		$this->description            = (string) $this->get_option( 'description' );
		$this->api                    = trim( (string) $this->get_option( 'api' ) );
		$this->onion_api              = trim( (string) $this->get_option( 'onion_api' ) );
		$this->public_key             = trim( (string) $this->get_option( 'public_key' ) );
		$this->secret_token           = trim( (string) $this->get_option( 'secret_token' ) );
		$this->webhook_signing_secret = trim( (string) $this->get_option( 'webhook_signing_secret' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * The settings form. Connect fills in the keys; the API entry, its
	 * onion address and the webhook signing secret are saved too but never
	 * shown.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'monokulo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer Monero at checkout', 'monokulo' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'What customers see at checkout.', 'monokulo' ),
				'default'     => __( 'Monero (via Monokulo)', 'monokulo' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'monokulo' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title at checkout.', 'monokulo' ),
				'default'     => __( 'Pay with Monero. You pay on a secure Monokulo payment page next.', 'monokulo' ),
				'desc_tip'    => true,
			),
			'connection'     => array(
				'title'       => __( 'Connection', 'monokulo' ),
				'type'        => 'title',
				'description' => __( 'Connect fills in the store\'s keys and turns Monero on.', 'monokulo' ),
			),
			'address'        => array(
				'title'       => __( 'Monokulo address', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'Where your Monokulo is, as your browser opens it: https://www.monokulo.com, or your own Monokulo\'s address. Save it before you Connect.', 'monokulo' ),
				'default'     => self::DEFAULT_MONOKULO_ADDRESS,
				'placeholder' => self::DEFAULT_MONOKULO_ADDRESS,
				'desc_tip'    => true,
			),
			'connect'        => array(
				'title'       => __( 'Store', 'monokulo' ),
				'type'        => 'monokulo_connect',
				'description' => __( 'You sign in to Monokulo (or sign up), pick or set up the store, and come back here.', 'monokulo' ),
			),
			'public_key'     => array(
				'title'       => __( 'Store public key', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'Connect fills it in.', 'monokulo' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'secret_token'   => array(
				'title'       => __( 'Store secret key', 'monokulo' ),
				'type'        => 'password',
				'description' => __( 'Used to make orders from this shop\'s server, never shown to customers. Connect fills it in.', 'monokulo' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'forward_errors' => array(
				'title'       => __( 'Send errors to Monokulo', 'monokulo' ),
				'type'        => 'checkbox',
				'label'       => __( 'Also send this plugin\'s warnings and errors to your Monokulo', 'monokulo' ),
				'description' => __( 'Kept there when the store accepts client logs. They are always in WooCommerce > Status > Logs too.', 'monokulo' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Offered at checkout only while the store has its whole connection.
	 */
	public function is_available(): bool {
		return parent::is_available() && $this->has_credentials();
	}

	/**
	 * Whether the API entry and the store's secret key are set.
	 */
	private function has_credentials(): bool {
		return '' !== $this->api && '' !== $this->secret_token;
	}

	/**
	 * Creates the Monokulo order and sends the customer to pay it: to its
	 * checkout, or its checkout on Monokulo's onion address when the
	 * customer came to the shop over Tor. The order stays pending until a
	 * webhook says otherwise. A failure throws, and WooCommerce shows the
	 * message to the customer.
	 *
	 * @param int $order_id Order ID.
	 * @return array{result: string, redirect: string}
	 * @throws Exception When the order can't be created.
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			throw new Exception( esc_html__( 'Your order could not be found. Please start the checkout again.', 'monokulo' ) );
		}

		$made     = $this->create_monokulo_order( $order );
		$checkout = $made['checkout'];
		if ( self::customer_on_tor() ) {
			if ( null === $made['onion_checkout'] ) {
				$this->log( sprintf( 'A customer on the shop\'s onion address could not be sent to pay Monokulo order %s: the Monokulo has no onion address (its operator can turn on Tor), and a customer on Tor is never sent to its web address.', $made['order_id'] ), 'error' );
				throw new Exception( esc_html__( 'Monero payments cannot be made over Tor at this store right now. Please contact the store owner.', 'monokulo' ) );
			}
			$checkout = $made['onion_checkout'];
		}

		$order->update_meta_data( self::META_ORDER_ID, $made['order_id'] );
		$known = array_map( static fn( WC_Meta_Data $meta ) => $meta->value, $order->get_meta( self::META_ORDER_IDS, false ) );
		if ( ! in_array( $made['order_id'], $known, true ) ) {
			$order->add_meta_data( self::META_ORDER_IDS, $made['order_id'] );
		}
		/* translators: %s: the Monokulo checkout */
		$note = sprintf( __( 'Monokulo: the customer was sent to pay here: %s', 'monokulo' ), $checkout );
		if ( null !== $made['dashboard'] ) {
			$links                      = self::dashboard_links( $order );
			$links[ $made['order_id'] ] = $made['dashboard'];
			$order->update_meta_data( self::META_DASHBOARD_LINKS, wp_json_encode( $links ) );
			/* translators: %s: the order's page in the merchant's Monokulo dashboard */
			$note .= ' ' . sprintf( __( 'In Monokulo: %s', 'monokulo' ), $made['dashboard'] );
		}
		$order->add_order_note( $note );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $checkout,
		);
	}

	/**
	 * Whether this request came to the shop on a `.onion` host: a customer
	 * on Tor, who must stay on it.
	 */
	private static function customer_on_tor(): bool {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$host = (string) preg_replace( '/:\d+\z/', '', $host );
		return str_ends_with( rtrim( $host, '.' ), '.onion' );
	}

	/**
	 * The order's Monokulo dashboard pages, by Monokulo order id.
	 *
	 * @param WC_Order $order The order.
	 * @return array<string, string>
	 */
	private static function dashboard_links( WC_Order $order ): array {
		$links = json_decode( (string) $order->get_meta( self::META_DASHBOARD_LINKS ), true );
		return is_array( $links ) ? array_filter( $links, 'is_string' ) : array();
	}

	/**
	 * Makes the Monokulo order with the API entry's `create-order` form,
	 * with the store's secret key. Monokulo prices the amount in XMR.
	 * Failures are logged in full for the merchant and thrown with a short
	 * message for the customer.
	 *
	 * @param WC_Order $order The order.
	 * @return array{order_id: string, checkout: string, onion_checkout: ?string, dashboard: ?string}
	 *         Monokulo's order id and its links, resolved.
	 * @throws Exception When Monokulo can't be reached or refuses.
	 */
	private function create_monokulo_order( WC_Order $order ): array {
		if ( ! $this->has_credentials() ) {
			throw new Exception( esc_html__( 'Monero payments are not set up for this store yet. Please contact the store owner.', 'monokulo' ) );
		}

		$currency = $order->get_currency();
		$amount   = self::format_amount( (float) $order->get_total(), $currency );
		$body     = array(
			'amount'                 => $amount,
			'currency'               => $currency,
			// The order's ID is the merchant's reference: Monokulo shows it in
			// the dashboard and webhooks, not to the customer, who knows the
			// order by its number (in the description).
			'merchant_order_id'      => (string) $order->get_id(),
			'show_merchant_order_id' => false,
			'description'            => self::order_description( $order ),
			'idempotency_key'        => sprintf( 'wc:%d:%s:%s', $order->get_id(), $amount, $currency ),
		);

		list( $url, $response ) = $this->submit( 'create-order', $body );
		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'Order creation request to %s failed: %s', $url, $response->get_error_message() ), 'error' );
			throw new Exception( esc_html__( 'The Monero payment service could not be reached. Please try again shortly.', 'monokulo' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status > 299 ) {
			$problem = self::problem( $raw );
			$this->log( sprintf( 'Order creation request to %s returned HTTP %d: %s%s', $url, $status, $problem['text'], self::hint_for_status( $status ) ), 'error' );
			throw new Exception( esc_html( self::customer_message( $status, $problem['type'] ) ) );
		}

		$decoded  = json_decode( $raw, true );
		$checkout = is_array( $decoded ) ? self::link( $decoded, 'mk:checkout', $url ) : null;
		if ( ! is_array( $decoded ) || empty( $decoded['order_id'] ) || ! is_string( $decoded['order_id'] ) || null === $checkout ) {
			$this->log( sprintf( 'Order creation response from %s was not the expected shape: %s', $url, $raw ), 'error' );
			throw new Exception( esc_html( self::customer_message( 0, '' ) ) );
		}

		return array(
			'order_id'       => $decoded['order_id'],
			'checkout'       => $checkout,
			'onion_checkout' => self::link( $decoded, 'mk:onion-checkout', $url ),
			'dashboard'      => self::link( $decoded, 'mk:dashboard-order', $url ),
		);
	}

	/**
	 * Sends the entry's form `$name`. When the form answers `401`, `404` or
	 * `405`, the key changed or the API moved on: the entry is dropped, and
	 * when it was a kept copy it is read again and the form sent once more
	 * (the order's idempotency key makes that safe).
	 *
	 * @param string $name The form's name (`create-order`).
	 * @param array  $body The JSON body.
	 * @return array{0: string, 1: array|WP_Error} Where it went, and the response.
	 * @throws Exception When the entry can't be read or has no such form.
	 */
	private function submit( string $name, array $body ): array {
		$this->entry_read_now = false;
		$form                 = $this->entry_form( $name );
		$response             = $this->call( $form['method'], $form['target'], $body );
		if ( is_wp_error( $response ) || ! in_array( (int) wp_remote_retrieve_response_code( $response ), array( 401, 404, 405 ), true ) ) {
			return array( $form['target'], $response );
		}
		$this->forget_entry();
		if ( $this->entry_read_now ) {
			return array( $form['target'], $response );
		}
		$form = $this->entry_form( $name );
		return array( $form['target'], $this->call( $form['method'], $form['target'], $body ) );
	}

	/**
	 * One call to the API with the store's secret key: a JSON body when
	 * `$body` is given.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $url     Where.
	 * @param array|null $body    The JSON body, if any.
	 * @param array      $headers More headers.
	 * @param int        $timeout Seconds to wait.
	 * @return array|WP_Error The response, or the error when Monokulo couldn't be reached.
	 */
	private function call( string $method, string $url, ?array $body = null, array $headers = array(), int $timeout = 30 ) {
		$args = array(
			'method'  => $method,
			'headers' => $headers + array(
				'Accept'          => self::HAL_FORMS . ', application/problem+json',
				'Authorization'   => 'Bearer ' . $this->secret_token,
				'Monokulo-Client' => self::CLIENT,
				'traceparent'     => self::traceparent(),
			),
			'timeout' => $timeout,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		return wp_remote_request( $url, $args );
	}

	/**
	 * The API entry as the store's key sees it: its links and forms. It is
	 * kept as Monokulo's `Cache-Control` says (`private, max-age=…`), per
	 * entry and key; once that has passed it is asked for again with its
	 * `ETag`, and a `304` keeps it for another `max-age`. Unreachable or
	 * refused, it throws as an order failure would.
	 *
	 * @param int $timeout Seconds to wait for Monokulo, when it is asked.
	 * @return array The entry's `_links` and `_templates`.
	 * @throws Exception When it can't be read.
	 */
	private function entry( int $timeout = 30 ): array {
		$kept = get_option( self::ENTRY_OPTION );
		$kept = is_array( $kept ) && ( $kept['for'] ?? null ) === $this->entry_key() && is_array( $kept['entry'] ?? null ) ? $kept : null;
		if ( null !== $kept && time() < (int) ( $kept['expires'] ?? 0 ) ) {
			return $kept['entry'];
		}

		$etag     = is_string( $kept['etag'] ?? null ) ? $kept['etag'] : '';
		$response = $this->call( 'GET', $this->api, null, '' !== $etag ? array( 'If-None-Match' => $etag ) : array(), $timeout );
		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'Reading the API entry at %s failed: %s', $this->api, $response->get_error_message() ), 'error' );
			throw new Exception( esc_html__( 'The Monero payment service could not be reached. Please try again shortly.', 'monokulo' ) );
		}
		$this->entry_read_now = true;
		$status               = (int) wp_remote_retrieve_response_code( $response );
		$max_age              = self::max_age( (string) wp_remote_retrieve_header( $response, 'cache-control' ) );

		if ( 304 === $status && null !== $kept ) {
			$kept['expires'] = time() + $max_age;
			update_option( self::ENTRY_OPTION, $kept, false );
			return $kept['entry'];
		}

		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		if ( 200 !== $status || ! is_array( $decoded ) || ! is_array( $decoded['_links'] ?? null ) ) {
			$this->log( sprintf( 'Reading the API entry at %s returned HTTP %d: %s%s', $this->api, $status, self::problem( $raw )['text'], self::hint_for_status( $status ) ), 'error' );
			throw new Exception( esc_html( self::customer_message( 200 === $status ? 0 : $status, '' ) ) );
		}

		$entry = array(
			'_links'     => $decoded['_links'],
			'_templates' => is_array( $decoded['_templates'] ?? null ) ? $decoded['_templates'] : array(),
		);
		update_option(
			self::ENTRY_OPTION,
			array(
				'for'     => $this->entry_key(),
				'entry'   => $entry,
				'etag'    => (string) wp_remote_retrieve_header( $response, 'etag' ),
				'expires' => time() + $max_age,
			),
			false
		);
		return $entry;
	}

	/**
	 * `max-age` from a `Cache-Control` header, in seconds; 0 (ask each
	 * time) without one, or with `no-store` or `no-cache`.
	 *
	 * @param string $cache_control The header.
	 */
	private static function max_age( string $cache_control ): int {
		if ( preg_match( '/(?:\A|[\s,])no-(?:store|cache)(?:\z|[\s,=])/i', $cache_control ) ) {
			return 0;
		}
		return preg_match( '/(?:\A|[\s,])max-age=(\d+)/i', $cache_control, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Which entry and key the kept entry is for, so a new connection reads
	 * its own (and the key itself isn't stored twice).
	 */
	private function entry_key(): string {
		return hash( 'sha256', $this->api . "\n" . $this->secret_token );
	}

	/**
	 * Drops the kept entry, for it to be read again.
	 */
	private function forget_entry(): void {
		delete_option( self::ENTRY_OPTION );
	}

	/**
	 * A form of the entry, its target resolved.
	 *
	 * @param string $name    The form's name (`create-order`).
	 * @param int    $timeout Seconds to wait for Monokulo, when the entry is asked.
	 * @return array{method: string, target: string}
	 * @throws Exception When the entry can't be read or has no such form.
	 */
	private function entry_form( string $name, int $timeout = 30 ): array {
		$form = $this->entry( $timeout )['_templates'][ $name ] ?? null;
		if ( ! is_array( $form ) || ! is_string( $form['target'] ?? null ) || '' === $form['target'] ) {
			$this->log( sprintf( 'The API entry at %s offers no "%s" form.', $this->api, $name ), 'error' );
			throw new Exception( esc_html( self::customer_message( 0, '' ) ) );
		}
		return array(
			'method' => strtoupper( is_string( $form['method'] ?? null ) ? $form['method'] : 'POST' ),
			'target' => self::resolve( $form['target'], $this->api ),
		);
	}

	/**
	 * A resource's link `$rel`, resolved against `$base`, or null.
	 *
	 * @param array  $hal  A decoded HAL resource.
	 * @param string $rel  The relation (`mk:checkout`).
	 * @param string $base The URL the resource was read at.
	 */
	private static function link( array $hal, string $rel, string $base ): ?string {
		$href = $hal['_links'][ $rel ]['href'] ?? null;
		return is_string( $href ) && '' !== $href ? self::resolve( $href, $base ) : null;
	}

	/**
	 * `$href` resolved against `$base` (RFC 3986, for the links Monokulo
	 * gives: absolute URLs, and paths on its own host that already carry
	 * any path it is served under).
	 *
	 * @param string $href A link.
	 * @param string $base The URL it was found at.
	 */
	public static function resolve( string $href, string $base ): string {
		if ( preg_match( '#\A[a-z][a-z0-9+.-]*:#i', $href ) ) {
			return $href;
		}
		$parts  = wp_parse_url( $base );
		$scheme = ( $parts['scheme'] ?? 'https' ) . ':';
		if ( str_starts_with( $href, '//' ) ) {
			return $scheme . $href;
		}
		$origin = $scheme . '//' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $href, '/' ) ) {
			return $origin . $href;
		}
		$path = $parts['path'] ?? '/';
		return $origin . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $href;
	}

	/**
	 * What an `application/problem+json` body says, for the log: its
	 * title, detail and type. Any other body is logged as it is.
	 *
	 * @param string $raw The response body.
	 * @return array{text: string, type: string, detail: string}
	 */
	private static function problem( string $raw ): array {
		$problem = json_decode( $raw, true );
		if ( ! is_array( $problem ) || ! ( isset( $problem['title'] ) || isset( $problem['detail'] ) ) ) {
			return array(
				'text'   => $raw,
				'type'   => '',
				'detail' => '',
			);
		}
		$type   = is_string( $problem['type'] ?? null ) ? $problem['type'] : '';
		$detail = is_string( $problem['detail'] ?? null ) ? $problem['detail'] : '';
		$text   = implode( ': ', array_filter( array( (string) ( $problem['title'] ?? '' ), $detail ) ) );
		return array(
			'text'   => '' !== $type ? $text . ' (' . $type . ')' : $text,
			'type'   => $type,
			'detail' => $detail,
		);
	}

	/**
	 * "Order #1042: Blue mug + 2 more", on one line, at most 140 characters.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function order_description( WC_Order $order ): string {
		/* translators: %s: the order number */
		$description = sprintf( __( 'Order #%s', 'monokulo' ), $order->get_order_number() );
		$items       = array_values( $order->get_items() );
		if ( $items ) {
			$description .= ': ' . $items[0]->get_name();
			if ( count( $items ) > 1 ) {
				/* translators: %d: how many other items the order has */
				$description .= sprintf( __( ' + %d more', 'monokulo' ), count( $items ) - 1 );
			}
		}
		$description = trim( (string) preg_replace( '/[\p{Cc}\s]+/u', ' ', $description ) );
		if ( mb_strlen( $description, 'UTF-8' ) > self::MAX_DESCRIPTION_CHARS ) {
			$description = rtrim( mb_substr( $description, 0, self::MAX_DESCRIPTION_CHARS - 1, 'UTF-8' ) ) . '…';
		}
		return $description;
	}

	/**
	 * Currencies whose minor unit isn't a hundredth (ISO 4217): how many
	 * decimals each has. Every other currency has two.
	 */
	const CURRENCY_DECIMALS = array(
		'BIF' => 0,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'ISK' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KRW' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'UGX' => 0,
		'UYI' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'BHD' => 3,
		'IQD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
		'CLF' => 4,
		'UYW' => 4,
		'XMR' => 12,
	);

	/**
	 * The total as Monokulo parses it: `.` for the point, no thousands
	 * separator, in the currency's own decimals (2 for EUR, 0 for JPY, 12
	 * for XMR), with trailing zeros trimmed: `42.5`, `1000`.
	 *
	 * @param float  $total    The order total.
	 * @param string $currency The order currency.
	 */
	public static function format_amount( float $total, string $currency ): string {
		$amount = number_format( $total, self::CURRENCY_DECIMALS[ strtoupper( $currency ) ] ?? 2, '.', '' );
		return str_contains( $amount, '.' ) ? rtrim( rtrim( $amount, '0' ), '.' ) : $amount;
	}

	/**
	 * What the customer is told when Monokulo refuses an order.
	 *
	 * @param int    $status Monokulo's HTTP status (0 for a garbled answer).
	 * @param string $type   The problem's `type`, if any.
	 */
	private static function customer_message( int $status, string $type ): string {
		if ( 429 === $status ) {
			return __( 'Monero payments are busy right now. Please wait a moment and try again.', 'monokulo' );
		}
		if ( 401 === $status || 403 === $status ) {
			return __( 'Monero payments are not available for this store right now. Please contact the store owner.', 'monokulo' );
		}
		if ( str_ends_with( $type, '/invalid-amount' ) ) {
			return __( 'This order cannot be paid in Monero right now: its amount or currency cannot be priced. Please contact the store.', 'monokulo' );
		}
		return __( 'The Monero payment could not be started. Please try again, or contact the store.', 'monokulo' );
	}

	/**
	 * A hint in the merchant's log, for statuses they can fix.
	 *
	 * @param int $status Monokulo's HTTP status.
	 */
	private static function hint_for_status( int $status ): string {
		return match ( $status ) {
			401 => ' (the store secret key was rejected - reconnect the plugin from its settings page)',
			403 => ' (Monokulo refused the order - check the store\'s settings on Monokulo)',
			404 => ' (Monokulo has no API there - check the Monokulo address, then reconnect the plugin)',
			429 => ' (rate limited - Monokulo is receiving too many orders from this store)',
			default => '',
		};
	}

	/**
	 * The "Monokulo address" setting: where Connect goes.
	 */
	private function monokulo_address(): string {
		return self::clean_address( (string) $this->get_option( 'address' ) ) ?? self::DEFAULT_MONOKULO_ADDRESS;
	}

	/**
	 * A typed Monokulo address, trimmed and without a trailing slash, or
	 * null when it isn't a plain http(s) address (no login, query or
	 * fragment).
	 *
	 * @param string $typed What the merchant typed.
	 */
	public static function clean_address( string $typed ): ?string {
		$address = rtrim( trim( $typed ), '/' );
		$parts   = wp_parse_url( $address );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		return $address;
	}

	/**
	 * Saves the "Monokulo address" cleaned, or keeps the old one and says
	 * why when it isn't an http(s) address.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 */
	public function validate_address_field( $key, $value ): string {
		$address = self::clean_address( (string) $value );
		if ( null === $address ) {
			if ( class_exists( 'WC_Admin_Settings' ) ) {
				WC_Admin_Settings::add_error( __( 'Enter an http or https address for your Monokulo, such as https://www.monokulo.com.', 'monokulo' ) );
			}
			return (string) $this->get_option( $key );
		}
		return $address;
	}

	/**
	 * The Connect button. Each render makes a new nonce for the admin
	 * viewing it, good once and for ten minutes, as Monokulo's connect token
	 * is.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 */
	public function generate_monokulo_connect_html( $key, $data ): string {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'desc_tip'    => false,
				'description' => '',
			)
		);

		$nonce = bin2hex( random_bytes( 16 ) );
		set_transient( self::nonce_transient( $nonce ), get_current_user_id(), 10 * MINUTE_IN_SECONDS );
		$connect_url = rtrim( $this->monokulo_address(), '/' ) . '/plugins/connect/' . self::CONNECT_SLUG . '?' . http_build_query(
			array(
				'site_url'   => home_url( '/' ),
				'return_url' => admin_url( 'admin-post.php?action=' . self::CONNECT_RETURN_ACTION ),
				'nonce'      => $nonce,
				'name'       => self::CONNECT_NAME,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<p>
					<?php if ( $this->has_credentials() ) : ?>
						<?php esc_html_e( 'Connected as', 'monokulo' ); ?>
						<code><?php echo esc_html( $this->public_key ); ?></code>
					<?php else : ?>
						<?php esc_html_e( 'Not connected', 'monokulo' ); ?>
					<?php endif; ?>
				</p>
				<a href="<?php echo esc_url( $connect_url ); ?>" id="<?php echo esc_attr( $field_key ); ?>" class="button button-primary">
					<?php echo $this->has_credentials() ? esc_html__( 'Connect again', 'monokulo' ) : esc_html__( 'Connect', 'monokulo' ); ?>
				</a>
				<p>
					<?php esc_html_e( 'Connects to', 'monokulo' ); ?>
					<code><?php echo esc_html( $this->monokulo_address() ); ?></code>
				</p>
				<?php if ( $this->has_credentials() && '' !== $this->onion_api ) : ?>
					<p>
						<?php esc_html_e( 'Customers on Tor pay on its onion address:', 'monokulo' ); ?>
						<code><?php echo esc_html( (string) wp_parse_url( $this->onion_api, PHP_URL_HOST ) ); ?></code>
					</p>
				<?php endif; ?>
				<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes it. ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The transient that remembers a connect nonce, holding the id of the
	 * admin it was made for.
	 *
	 * @param string $nonce The nonce.
	 */
	private static function nonce_transient( string $nonce ): string {
		return 'monokulo_connect_' . $nonce;
	}

	/**
	 * `admin-post.php?action=monokulo_connect_return`: finishes connecting,
	 * then sends the browser to the settings page. Only for shop managers;
	 * the nonce check is in `process_connect_return()`.
	 */
	public function handle_connect_return(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'monokulo' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Monokulo's nonce, checked in process_connect_return().
		$query = array(
			'token' => isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '',
			'nonce' => isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '',
		);
		// phpcs:enable

		wp_safe_redirect( $this->process_connect_return( $query ) );
		exit;
	}

	/**
	 * Checks the nonce (used up whatever happens: it must be one this admin
	 * was given), redeems the token at `/finish`, saves the connection and
	 * turns the gateway on. A failure changes no setting.
	 *
	 * @param array{token?: string, nonce?: string} $query The connect flow's `token` and `nonce`.
	 * @return string The settings page, with `monokulo_connected` or
	 *                `monokulo_connect_error` for the notice.
	 */
	public function process_connect_return( array $query ): string {
		$token = (string) ( $query['token'] ?? '' );
		$nonce = (string) ( $query['nonce'] ?? '' );
		$admin = false;
		if ( preg_match( '/\A[0-9a-f]{32}\z/', $nonce ) ) {
			$admin = get_transient( self::nonce_transient( $nonce ) );
			delete_transient( self::nonce_transient( $nonce ) );
		}

		if ( '' === $token || false === $admin || get_current_user_id() !== (int) $admin ) {
			$this->log( 'Connect return refused: no token, or a nonce this shop did not give this admin, already used or expired.', 'warning' );
			return self::settings_url( array( 'monokulo_connect_error' => 'nonce' ) );
		}

		$finish = $this->call_connect_finish( $token );
		if ( isset( $finish['error'] ) ) {
			// Monokulo's own words, shown once on the settings page.
			set_transient( self::problem_transient(), $finish['detail'] ?? '', 10 * MINUTE_IN_SECONDS );
			return self::settings_url( array( 'monokulo_connect_error' => $finish['error'] ) );
		}

		$this->update_option( 'api', $finish['api'] );
		$this->update_option( 'onion_api', $finish['onion_api'] );
		$this->update_option( 'public_key', $finish['public_key'] );
		$this->update_option( 'secret_token', $finish['secret_token'] );
		$this->update_option( 'webhook_signing_secret', (string) ( $finish['webhook_signing_secret'] ?? '' ) );
		$this->update_option( 'enabled', 'yes' );
		$this->forget_entry();

		return self::settings_url( array( 'monokulo_connected' => '1' ) );
	}

	/**
	 * The transient holding what Monokulo said when connecting failed, for
	 * the admin who tried.
	 */
	private static function problem_transient(): string {
		return 'monokulo_connect_problem_' . get_current_user_id();
	}

	/**
	 * `POST {monokulo address}/plugins/connect/woocommerce/finish`: redeems
	 * the token for the store's keys and registers the webhook. The API
	 * entry it names is a path, resolved here against the address called;
	 * `onion_api` is absolute, and there only while Monokulo has an onion
	 * address.
	 *
	 * A refusal is `application/problem+json`: its name (`invalid-connect-code`,
	 * `no-public-address`, …) becomes the settings page's error, and its
	 * `detail` is shown there. `unreachable` is a Monokulo that didn't
	 * answer; `finish` anything else.
	 *
	 * @param string $token The connect token.
	 * @return array{public_key: string, secret_token: string, api: string, onion_api: string, webhook_signing_secret?: string}|array{error: string, detail?: string}
	 *         The credentials, or why not (logged).
	 */
	private function call_connect_finish( string $token ): array {
		$url      = $this->monokulo_address() . '/plugins/connect/' . self::CONNECT_SLUG . '/finish';
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Accept'          => 'application/json, application/problem+json',
					'Content-Type'    => 'application/json',
					'Monokulo-Client' => self::CLIENT,
					'traceparent'     => self::traceparent(),
				),
				'body'    => wp_json_encode(
					array(
						'token'       => $token,
						'webhook_url' => rest_url( 'monokulo/v1/webhook' ),
						'name'        => self::CONNECT_NAME,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'Connect finish request to %s failed: %s', $url, $response->get_error_message() ), 'error' );
			return array( 'error' => 'unreachable' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$problem = self::problem( $raw );
			$retry   = (string) wp_remote_retrieve_header( $response, 'retry-after' );
			$this->log( sprintf( 'Connect finish request to %s returned HTTP %d: %s%s', $url, $status, $problem['text'], '' !== $retry ? ' (retry after ' . $retry . ' s)' : '' ), 'error' );
			$name = sanitize_key( (string) preg_replace( '#\A.*/#', '', $problem['type'] ) );
			return array(
				'error'  => '' !== $name ? $name : 'finish',
				'detail' => $problem['detail'],
			);
		}

		$decoded = json_decode( $raw, true );
		foreach ( array( 'public_key', 'secret_token', 'api' ) as $field ) {
			if ( ! is_array( $decoded ) || empty( $decoded[ $field ] ) || ! is_string( $decoded[ $field ] ) ) {
				$this->log( sprintf( 'Connect finish response from %s was not the expected shape: %s', $url, $raw ), 'error' );
				return array( 'error' => 'finish' );
			}
		}
		$decoded['api']       = self::resolve( $decoded['api'], $url );
		$decoded['onion_api'] = is_string( $decoded['onion_api'] ?? null ) ? $decoded['onion_api'] : '';

		return $decoded;
	}

	/**
	 * Handles one webhook delivery and says which status to answer:
	 *
	 * - 401: the signature is missing or doesn't check out;
	 * - 400: signed, but not a webhook envelope;
	 * - 404: no order here for that Monokulo order;
	 * - 500: applying it failed, so the retry applies it;
	 * - 200: applied, already applied before, or an event this version
	 *   doesn't know (logged).
	 *
	 * Anything but a 2xx is retried by Monokulo.
	 *
	 * @param string $body      The request body as sent.
	 * @param string $signature The `X-Monokulo-Signature` header, or ''.
	 */
	public function process_webhook( string $body, string $signature ): int {
		if ( ! self::verify_signature( $this->webhook_signing_secret, $body, $signature, time() ) ) {
			$this->log( 'Webhook refused: a missing or wrong X-Monokulo-Signature.', 'warning' );
			return 401;
		}

		$event = json_decode( $body, true );
		foreach ( array( 'event', 'event_id', 'order_id' ) as $field ) {
			if ( ! is_array( $event ) || empty( $event[ $field ] ) || ! is_string( $event[ $field ] ) ) {
				$this->log( 'Webhook refused: signed, but not a webhook envelope: ' . $body, 'error' );
				return 400;
			}
		}

		$order = self::find_order( $event['order_id'] );
		if ( null === $order ) {
			$this->log( sprintf( 'Webhook %s for Monokulo order %s: no such order in this shop.', $event['event_id'], $event['order_id'] ), 'warning' );
			return 404;
		}

		$applied = json_decode( (string) $order->get_meta( self::META_APPLIED_EVENT_IDS ), true );
		$applied = is_array( $applied ) ? $applied : array();
		if ( in_array( $event['event_id'], $applied, true ) ) {
			return 200;
		}

		try {
			$this->apply_event( $order, $event );
			$applied[] = $event['event_id'];
			$order->update_meta_data( self::META_APPLIED_EVENT_IDS, wp_json_encode( $applied ) );
			$order->save();
		} catch ( Throwable $e ) {
			$this->log( sprintf( 'Webhook %s for order %d could not be applied: %s', $event['event_id'], $order->get_id(), $e->getMessage() ), 'error' );
			return 500;
		}
		$this->log( sprintf( 'Webhook %s (%s) applied to order %d.', $event['event_id'], $event['event'], $order->get_id() ) );

		return 200;
	}

	/**
	 * Checks `X-Monokulo-Signature: t=<unix time>,v1=<hex>`, where the hex is
	 * HMAC-SHA256 of `<t>.<raw body>` keyed with the webhook signing secret.
	 * A time more than five minutes from `$now` is refused, so a captured
	 * delivery can't be replayed.
	 *
	 * @param string $secret The webhook signing secret.
	 * @param string $body   The request body as sent.
	 * @param string $header The header.
	 * @param int    $now    The time to check against.
	 */
	public static function verify_signature( string $secret, string $body, string $header, int $now ): bool {
		if ( '' === $secret || ! preg_match( '/\At=(\d{1,18}),v1=([0-9a-fA-F]{64})\z/', trim( $header ), $parts ) ) {
			return false;
		}
		if ( abs( $now - (int) $parts[1] ) > self::SIGNATURE_TOLERANCE_SECONDS ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $parts[1] . '.' . $body, $secret ), strtolower( $parts[2] ) );
	}

	/**
	 * The order a Monokulo order was made for.
	 *
	 * @param string $monokulo_order_id Monokulo's order id.
	 */
	private static function find_order( string $monokulo_order_id ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'meta_key'   => self::META_ORDER_IDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $monokulo_order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'limit'      => 1,
			)
		);
		return ( $orders[0] ?? null ) instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * Applies one event to the order:
	 *
	 * - `order.paid`, `order.overpaid`: `payment_complete()`, which picks
	 *   Processing or Completed, reduces stock and sends WooCommerce's emails;
	 * - `order.unconfirmed`, `order.confirming`, `order.partial`: On hold;
	 * - `order.expired`: Cancelled;
	 * - `order.pending`: Pending (a double-spend voided every payment);
	 * - `order.double_spend_detected`, `order.double_spend_reversed`: a note
	 *   only. When they change the order's status, Monokulo sends that as its
	 *   own `order.<status>` event.
	 *
	 * Each adds a note, with the Monokulo order's dashboard page when the
	 * order kept it (`META_DASHBOARD_LINKS`). A shop order can have several
	 * Monokulo orders (a new one each time its total changed,
	 * `META_ORDER_IDS`), and Monokulo watches them all:
	 *
	 * - a payment to any of them pays the order: the money arrived. One to an
	 *   older request says so in the note, for the merchant to check the
	 *   amount;
	 * - any other event about an older request is a note only;
	 * - a paid order never goes back: a later event, or a second payment
	 *   (an overpayment, or another request paid too), is a note, for the
	 *   merchant to refund.
	 *
	 * Unknown events are logged and ignored, so a newer Monokulo doesn't
	 * break the shop.
	 *
	 * @param WC_Order $order The order.
	 * @param array    $event The decoded webhook body.
	 * @throws RuntimeException When WooCommerce can't complete the payment.
	 */
	private function apply_event( WC_Order $order, array $event ): void {
		$type   = $event['event'];
		$txid   = is_string( $event['txid'] ?? null ) ? $event['txid'] : __( 'unknown', 'monokulo' );
		$paid   = 'order.paid' === $type || 'order.overpaid' === $type;
		$status = self::STATUS_MAP[ $type ] ?? null;
		$note   = match ( $type ) {
			'order.unconfirmed', 'order.confirming' => __( 'Monokulo: a payment was seen and is waiting for confirmations.', 'monokulo' ),
			'order.partial'               => __( 'Monokulo: part of the amount due has arrived. The order is not paid in full yet.', 'monokulo' ),
			'order.paid'                  => __( 'Monokulo: paid in full.', 'monokulo' ),
			'order.overpaid'              => __( 'Monokulo: paid, with more than the amount due. The excess is not refunded automatically: refund it yourself if needed.', 'monokulo' ),
			'order.expired'               => __( 'Monokulo: the payment window closed without full payment. Any funds received stay at the payment address; refund them yourself if needed.', 'monokulo' ),
			'order.pending'               => __( 'Monokulo: no payment counts towards this order any more.', 'monokulo' ),
			/* translators: %s: transaction id */
			'order.double_spend_detected' => sprintf( __( 'Monokulo: FRAUD ALERT. A payment to this order was double-spent and no longer counts (transaction %s). If this changed the order status, that is a separate note. Check this order before sending anything.', 'monokulo' ), $txid ),
			/* translators: %s: transaction id */
			'order.double_spend_reversed' => sprintf( __( 'Monokulo: the earlier double-spend alert for this order (transaction %s) was wrong and is withdrawn. If this changed the order status, that is a separate note.', 'monokulo' ), $txid ),
			default                       => null,
		};
		if ( null === $note ) {
			$this->log( sprintf( 'Webhook %s: unknown event "%s", accepted and ignored.', $event['event_id'], $type ), 'warning' );
			return;
		}

		$page = self::dashboard_links( $order )[ $event['order_id'] ] ?? null;
		if ( null !== $page ) {
			/* translators: %s: the order's page in the merchant's Monokulo dashboard */
			$note .= ' ' . sprintf( __( 'In Monokulo: %s', 'monokulo' ), $page );
		}

		$older = $order->get_meta( self::META_ORDER_ID ) !== $event['order_id'];

		if ( $paid && $order->is_paid() ) {
			$order->add_order_note(
				$note . ' ' . sprintf(
					/* translators: %s: Monokulo's order id */
					__( 'The order was already paid, so nothing changes: this is more money for it (Monokulo order %s). Refund the extra yourself if needed.', 'monokulo' ),
					$event['order_id']
				)
			);
			return;
		}

		if ( $paid ) {
			// payment_complete() catches its own failures and returns false.
			if ( ! $order->payment_complete( $event['order_id'] ) ) {
				throw new RuntimeException( 'WooCommerce could not complete the payment' );
			}
			$order->add_order_note(
				$older
					? __( 'Monokulo: an older payment request for this order was paid, after the order total changed. Check the amount received.', 'monokulo' ) . ' ' . $note
					: $note
			);
			return;
		}

		if ( null !== $status && $older ) {
			$order->add_order_note( $note . ' ' . __( 'This is about an older payment request for this order, so its status is left as it is.', 'monokulo' ) );
			return;
		}

		if ( null !== $status && $order->is_paid() ) {
			$order->add_order_note( $note . ' ' . __( 'The order was already paid, so its status is left as it is: check it before doing anything else.', 'monokulo' ) );
			return;
		}

		if ( null !== $status && ! $order->has_status( $status ) ) {
			$order->update_status( $status, $note );
			return;
		}

		$order->add_order_note( $note );
	}

	/**
	 * The gateway's settings page, with `$args` added.
	 *
	 * @param array<string, string> $args Query arguments.
	 */
	private static function settings_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::ID ) );
	}

	/**
	 * Says how connecting went, on the gateway's settings page only, from
	 * the flag `process_connect_return()` put in the address, with what
	 * Monokulo said when it refused (its problem's `detail`), once.
	 */
	public static function render_connect_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only picks which notice to show.
		if ( 'wc-settings' !== ( $_GET['page'] ?? '' ) || self::ID !== ( $_GET['section'] ?? '' ) ) {
			return;
		}
		$error   = isset( $_GET['monokulo_connect_error'] ) ? sanitize_key( wp_unslash( $_GET['monokulo_connect_error'] ) ) : null;
		$message = match ( true ) {
			isset( $_GET['monokulo_connected'] ) => __( 'Your store is connected. Monero is now offered at checkout.', 'monokulo' ),
			'no-public-address' === $error    => __( 'Your Monokulo cannot connect shops yet: its operator has not set its public address. Try again later.', 'monokulo' ),
			'unreachable' === $error          => __( 'Your Monokulo could not be reached from this shop\'s server. Check the Monokulo address, then connect again.', 'monokulo' ),
			'nonce' === $error                => __( 'That connect link was already used or has expired. Please connect again.', 'monokulo' ),
			'invalid-connect-code' === $error => __( 'Monokulo did not take the connect code: it was already used or has expired. Please connect again.', 'monokulo' ),
			'rate-limited' === $error         => __( 'Monokulo is busy. Wait a minute, then connect again.', 'monokulo' ),
			null !== $error                   => __( 'Monokulo did not hand over the store\'s keys. Please connect again; the log says why.', 'monokulo' ),
			default                           => null,
		};
		// phpcs:enable
		if ( null === $message ) {
			return;
		}
		$detail = null !== $error ? (string) get_transient( self::problem_transient() ) : '';
		delete_transient( self::problem_transient() );
		if ( '' !== $detail ) {
			/* translators: %s: what Monokulo said */
			$message .= ' ' . sprintf( __( 'Monokulo says: %s', 'monokulo' ), $detail );
		}
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( null === $error ? 'notice-success' : 'notice-error' ), esc_html( $message ) );
	}

	/**
	 * This request's trace id, made on first use.
	 *
	 * @return string 32 lowercase hex characters.
	 */
	public static function trace_id(): string {
		return self::$trace_id ??= bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Joins the trace of an incoming `traceparent` (version 00). Anything
	 * else is ignored.
	 *
	 * @param string $traceparent The header, or ''.
	 * @return bool Whether it was adopted.
	 */
	public static function adopt_traceparent( string $traceparent ): bool {
		if ( ! preg_match( '/\A00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}\z/', trim( $traceparent ), $parts ) ) {
			return false;
		}
		if ( str_repeat( '0', 32 ) === $parts[1] || str_repeat( '0', 16 ) === $parts[2] ) {
			return false;
		}
		self::$trace_id = $parts[1];
		return true;
	}

	/**
	 * A `traceparent` for one outgoing call: this request's trace, a new span.
	 */
	public static function traceparent(): string {
		return '00-' . self::trace_id() . '-' . bin2hex( random_bytes( 8 ) ) . '-01';
	}

	/**
	 * Logs to WooCommerce's logs (source `monokulo`) with the trace id, and
	 * queues warnings and errors for Monokulo when "Send errors to
	 * Monokulo" is on.
	 *
	 * @param string $message Log message.
	 * @param string $level   A WooCommerce log level.
	 */
	private function log( string $message, string $level = 'info' ): void {
		$this->maybe_forward( $message, $level );
		// WooCommerce's file log writes only the message, so the trace id goes in it.
		wc_get_logger()->log(
			$level,
			$message . ' [trace ' . self::trace_id() . ']',
			array(
				'source'   => 'monokulo',
				'trace_id' => self::trace_id(),
			)
		);
	}

	/**
	 * Queues a warning or error for Monokulo, and sends the queue when the
	 * request ends, so no page waits on it.
	 *
	 * @param string $message Log message.
	 * @param string $level   A WooCommerce log level.
	 */
	private function maybe_forward( string $message, string $level ): void {
		if ( self::$forwarding || 'yes' !== $this->get_option( 'forward_errors' ) || ! $this->has_credentials()
			|| ! in_array( $level, self::FORWARDED_LEVELS, true ) || count( self::$forward_queue ) >= self::MAX_FORWARDED ) {
			return;
		}
		self::$forward_queue[] = array(
			'level'    => $level,
			'message'  => $message,
			'trace_id' => self::trace_id(),
		);
		if ( ! self::$forward_registered ) {
			self::$forward_registered = true;
			register_shutdown_function( array( $this, 'flush_forwarded' ) );
		}
	}

	/**
	 * Sends the queued entries with the entry's `send-logs` form, in one
	 * request that isn't waited for. The entry is usually kept already;
	 * when it has to be read, it is waited for briefly. Entries that can't
	 * be sent (no form for the key, Monokulo unreachable) stay in
	 * WooCommerce's log only.
	 */
	public function flush_forwarded(): void {
		if ( ! self::$forward_queue ) {
			return;
		}
		$entries             = self::$forward_queue;
		self::$forward_queue = array();
		self::$forwarding    = true;
		try {
			$form = $this->entry_form( 'send-logs', 3 );
		} catch ( Exception $e ) {
			return;
		} finally {
			self::$forwarding = false;
		}
		wp_remote_request(
			$form['target'],
			array(
				'method'   => $form['method'],
				'headers'  => array(
					'Content-Type'    => 'application/json',
					'Authorization'   => 'Bearer ' . $this->secret_token,
					'Monokulo-Client' => self::CLIENT,
					'traceparent'     => self::traceparent(),
				),
				'body'     => wp_json_encode( array( 'entries' => $entries ) ),
				'timeout'  => 3,
				'blocking' => false,
			)
		);
	}
}
