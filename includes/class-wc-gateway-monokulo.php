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
 *   checkout address Monokulo hands back, and turns the gateway on.
 * - At checkout `process_payment()` creates the order with
 *   `POST {endpoint}/pay/{pk}/orders` and sends the customer to
 *   `{endpoint}/pay/{pk}/orders/{order_id}` to pay.
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
	const VERSION = '0.3.0';

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
	 * Order meta: the webhook events already applied, as a JSON list.
	 */
	const META_APPLIED_EVENT_IDS = '_monokulo_applied_webhook_event_ids';

	/**
	 * Most log entries sent to Monokulo per request; Monokulo refuses more.
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
	 * The checkout address: where orders are made and customers pay.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * Monokulo's onion address, where customers on the shop's own .onion
	 * address pay; empty when Monokulo has none.
	 *
	 * @var string
	 */
	private string $onion_endpoint;

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
		$this->endpoint               = trim( (string) $this->get_option( 'endpoint' ) );
		$this->onion_endpoint         = trim( (string) $this->get_option( 'onion_endpoint' ) );
		$this->public_key             = trim( (string) $this->get_option( 'public_key' ) );
		$this->secret_token           = trim( (string) $this->get_option( 'secret_token' ) );
		$this->webhook_signing_secret = trim( (string) $this->get_option( 'webhook_signing_secret' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * The settings form. Connect fills in the checkout address and keys;
	 * the webhook signing secret is saved too but never shown.
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
				'description' => __( 'Connect fills in the checkout address and the store\'s keys, and turns Monero on.', 'monokulo' ),
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
			'endpoint'       => array(
				'title'       => __( 'Checkout address', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'Where orders are made and customers pay. Connect fills it in.', 'monokulo' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'onion_endpoint' => array(
				'title'       => __( 'Onion checkout address', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'Where customers on this shop\'s .onion address pay, so they stay on Tor. Connect fills it in; it is empty when your Monokulo has no onion address.', 'monokulo' ),
				'default'     => '',
				'desc_tip'    => true,
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
	 * Whether the checkout address and both keys are set.
	 */
	private function has_credentials(): bool {
		return '' !== $this->endpoint && '' !== $this->public_key && '' !== $this->secret_token;
	}

	/**
	 * Creates the Monokulo order and sends the customer to pay it. The order
	 * stays pending until a webhook says otherwise. A failure throws, and
	 * WooCommerce shows the message to the customer.
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

		$monokulo_order_id = $this->create_monokulo_order( $order );

		$checkout_url = $this->checkout_url( $monokulo_order_id );

		$order->update_meta_data( self::META_ORDER_ID, $monokulo_order_id );
		$known = array_map( static fn( WC_Meta_Data $meta ) => $meta->value, $order->get_meta( self::META_ORDER_IDS, false ) );
		if ( ! in_array( $monokulo_order_id, $known, true ) ) {
			$order->add_meta_data( self::META_ORDER_IDS, $monokulo_order_id );
		}
		/* translators: %s: the Monokulo checkout */
		$order->add_order_note( sprintf( __( 'Monokulo: the customer was sent to pay here: %s', 'monokulo' ), $checkout_url ) );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}

	/**
	 * `POST {endpoint}/pay/{pk}/orders` with the store's secret key. Monokulo
	 * prices the amount in XMR. Failures are logged in full for the merchant
	 * and thrown with a short message for the customer.
	 *
	 * @param WC_Order $order The order.
	 * @return string Monokulo's order id.
	 * @throws Exception When Monokulo can't be reached or refuses.
	 */
	private function create_monokulo_order( WC_Order $order ): string {
		if ( ! $this->has_credentials() ) {
			throw new Exception( esc_html__( 'Monero payments are not set up for this store yet. Please contact the store owner.', 'monokulo' ) );
		}

		$url      = $this->pay_url( $this->endpoint, 'orders' );
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

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type'    => 'application/json',
					'Authorization'   => 'Bearer ' . $this->secret_token,
					'Monokulo-Client' => self::CLIENT,
					'traceparent'     => self::traceparent(),
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'Order creation request to %s failed: %s', $url, $response->get_error_message() ), 'error' );
			throw new Exception( esc_html__( 'The Monero payment service could not be reached. Please try again shortly.', 'monokulo' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$error  = json_decode( $raw, true );
			$detail = is_array( $error ) && isset( $error['error'] ) ? (string) $error['error'] : $raw;
			$this->log( sprintf( 'Order creation request to %s returned HTTP %d: %s%s', $url, $status, $detail, self::hint_for_status( $status ) ), 'error' );
			throw new Exception( esc_html( self::customer_message_for_status( $status ) ) );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['order_id'] ) || ! is_string( $decoded['order_id'] ) ) {
			$this->log( sprintf( 'Order creation response from %s was not the expected shape: %s', $url, $raw ), 'error' );
			throw new Exception( esc_html( self::customer_message_for_status( 0 ) ) );
		}

		return $decoded['order_id'];
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
	 * The total as Monokulo parses it: `.` for the point, no thousands
	 * separator. Two decimals for fiat; XMR keeps the shop's price decimals,
	 * up to twelve.
	 *
	 * @param float  $total    The order total.
	 * @param string $currency The order currency.
	 */
	public static function format_amount( float $total, string $currency ): string {
		$decimals = 'XMR' === strtoupper( $currency ) ? min( 12, max( 2, wc_get_price_decimals() ) ) : 2;
		return number_format( $total, $decimals, '.', '' );
	}

	/**
	 * What the customer is told when Monokulo refuses an order.
	 *
	 * @param int $status Monokulo's HTTP status.
	 */
	private static function customer_message_for_status( int $status ): string {
		if ( 429 === $status ) {
			return __( 'Monero payments are busy right now. Please wait a moment and try again.', 'monokulo' );
		}
		if ( 401 === $status || 403 === $status ) {
			return __( 'Monero payments are not available for this store right now. Please contact the store owner.', 'monokulo' );
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
			403 => ' (Monokulo refused the order - check the store\'s verified-domain settings on Monokulo)',
			404 => ' (Monokulo does not know this store\'s public key - reconnect the plugin)',
			429 => ' (rate limited - Monokulo is receiving too many orders from this store)',
			default => '',
		};
	}

	/**
	 * `{base}/pay/{pk}/{path}`.
	 *
	 * @param string $base The checkout or onion address.
	 * @param string $path What follows the store's key.
	 */
	private function pay_url( string $base, string $path ): string {
		return rtrim( $base, '/' ) . '/pay/' . rawurlencode( $this->public_key ) . '/' . $path;
	}

	/**
	 * Where the customer pays: on Monokulo's onion address when they shop
	 * on the shop's own .onion address and Monokulo has one, so they stay
	 * on Tor; at the checkout address otherwise.
	 *
	 * @param string $order_id Monokulo's order id.
	 */
	private function checkout_url( string $order_id ): string {
		$base = '' !== $this->onion_endpoint && self::is_onion_host( self::request_host() ) ? $this->onion_endpoint : $this->endpoint;
		return $this->pay_url( $base, 'orders/' . rawurlencode( $order_id ) );
	}

	/**
	 * The host the customer's browser asked for, without a port.
	 */
	private static function request_host(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		return strtolower( (string) preg_replace( '/:\d+$/', '', $host ) );
	}

	/**
	 * Whether `$host` is an onion address.
	 *
	 * @param string $host A host name.
	 */
	private static function is_onion_host( string $host ): bool {
		return str_ends_with( rtrim( strtolower( $host ), '.' ), '.onion' );
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

		$status = 0;
		$finish = $this->call_connect_finish( $token, $status );
		if ( null === $finish ) {
			$error = match ( $status ) {
				0       => 'unreachable',
				503     => 'unavailable',
				default => 'finish',
			};
			return self::settings_url( array( 'monokulo_connect_error' => $error ) );
		}

		// Only an http:// .onion address is somewhere to send a customer on Tor.
		$onion = is_string( $finish['onion_endpoint'] ?? null ) ? $finish['onion_endpoint'] : '';
		if ( ! str_starts_with( $onion, 'http://' ) || ! self::is_onion_host( (string) wp_parse_url( $onion, PHP_URL_HOST ) ) ) {
			$onion = '';
		}

		$this->update_option( 'endpoint', $finish['endpoint'] );
		$this->update_option( 'onion_endpoint', $onion );
		$this->update_option( 'public_key', $finish['public_key'] );
		$this->update_option( 'secret_token', $finish['secret_token'] );
		$this->update_option( 'webhook_signing_secret', (string) ( $finish['webhook_signing_secret'] ?? '' ) );
		$this->update_option( 'enabled', 'yes' );

		return self::settings_url( array( 'monokulo_connected' => '1' ) );
	}

	/**
	 * `POST {monokulo address}/plugins/connect/woocommerce/finish`: redeems
	 * the token for the store's keys and registers the webhook.
	 *
	 * @param string $token  The connect token.
	 * @param int    $status Set to Monokulo's HTTP status (0 when unreachable).
	 * @return array{public_key: string, secret_token: string, endpoint: string}|null
	 *         The credentials, or null on any failure (logged).
	 */
	private function call_connect_finish( string $token, int &$status ): ?array {
		$url      = rtrim( $this->monokulo_address(), '/' ) . '/plugins/connect/' . self::CONNECT_SLUG . '/finish';
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
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
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$this->log( sprintf( 'Connect finish request to %s returned HTTP %d: %s', $url, $status, $raw ), 'error' );
			return null;
		}

		$decoded = json_decode( $raw, true );
		foreach ( array( 'public_key', 'secret_token', 'endpoint' ) as $field ) {
			if ( ! is_array( $decoded ) || empty( $decoded[ $field ] ) || ! is_string( $decoded[ $field ] ) ) {
				$this->log( sprintf( 'Connect finish response from %s was not the expected shape: %s', $url, $raw ), 'error' );
				return null;
			}
		}

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
	 * Each adds a note. A paid order is never moved back, and an event about
	 * an older payment request (the total changed since) never moves the
	 * order: the note says so, for the merchant to check. Unknown events are
	 * logged and ignored, so a newer Monokulo doesn't break the shop.
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

		$store_id = is_string( $event['store']['id'] ?? null ) ? $event['store']['id'] : '';
		if ( '' !== $store_id ) {
			/* translators: %s: the order's page in the merchant's Monokulo dashboard */
			$note .= ' ' . sprintf( __( 'In Monokulo: %s', 'monokulo' ), rtrim( $this->endpoint, '/' ) . '/dashboard/stores/' . rawurlencode( $store_id ) . '/orders/' . rawurlencode( $event['order_id'] ) );
		}

		if ( ( $paid || null !== $status ) && $order->get_meta( self::META_ORDER_ID ) !== $event['order_id'] ) {
			$order->add_order_note(
				$paid
					? __( 'Monokulo: an older payment request for this order was paid, after the order total changed. Check the amount received before treating this order as paid.', 'monokulo' ) . ' ' . $note
					: $note . ' ' . __( 'This is about an older payment request for this order, so its status is left as it is.', 'monokulo' )
			);
			return;
		}

		if ( $paid ) {
			// payment_complete() catches its own failures and returns false.
			if ( ! $order->payment_complete( $event['order_id'] ) ) {
				throw new RuntimeException( 'WooCommerce could not complete the payment' );
			}
			$order->add_order_note( $note );
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
	 * the flag `process_connect_return()` put in the address.
	 */
	public static function render_connect_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only picks which notice to show.
		if ( 'wc-settings' !== ( $_GET['page'] ?? '' ) || self::ID !== ( $_GET['section'] ?? '' ) ) {
			return;
		}
		$error   = isset( $_GET['monokulo_connect_error'] ) ? sanitize_key( wp_unslash( $_GET['monokulo_connect_error'] ) ) : null;
		$message = match ( true ) {
			isset( $_GET['monokulo_connected'] ) => __( 'Your store is connected. Monero is now offered at checkout.', 'monokulo' ),
			'unavailable' === $error => __( 'Your Monokulo cannot connect shops yet: its operator has not set its public address.', 'monokulo' ),
			'unreachable' === $error => __( 'Your Monokulo could not be reached from this shop\'s server. Check the Monokulo address, then connect again.', 'monokulo' ),
			'nonce' === $error       => __( 'That connect link was already used or has expired. Please connect again.', 'monokulo' ),
			null !== $error          => __( 'Monokulo did not hand over the store\'s keys. Please connect again; the log says why.', 'monokulo' ),
			default                  => null,
		};
		$class = null === $error ? 'notice-success' : 'notice-error';
		if ( null === $message ) {
			return;
		}
		// phpcs:enable
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
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
		if ( 'yes' !== $this->get_option( 'forward_errors' ) || ! $this->has_credentials()
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
			$url                      = $this->pay_url( $this->endpoint, 'logs' );
			$secret                   = $this->secret_token;
			register_shutdown_function( static fn() => self::flush_forwarded( $url, $secret ) );
		}
	}

	/**
	 * Sends the queued entries in one request that isn't waited for.
	 *
	 * @param string $url    `{endpoint}/pay/{pk}/logs`.
	 * @param string $secret The store's secret key.
	 */
	public static function flush_forwarded( string $url, string $secret ): void {
		if ( ! self::$forward_queue ) {
			return;
		}
		$entries             = self::$forward_queue;
		self::$forward_queue = array();
		wp_remote_post(
			$url,
			array(
				'headers'  => array(
					'Content-Type'    => 'application/json',
					'Authorization'   => 'Bearer ' . $secret,
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
