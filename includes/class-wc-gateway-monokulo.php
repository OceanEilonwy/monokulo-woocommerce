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
	const DEFAULT_MONOKULO_ADDRESS = 'https://monokulo.com';

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
	 * Order meta: the Monokulo order the customer was sent to pay.
	 */
	const META_ORDER_ID = '_monokulo_order_id';

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
	 * The WooCommerce status for each Monokulo status that isn't paid.
	 */
	const STATUS_MAP = array(
		'pending'     => 'pending',
		'unconfirmed' => 'on-hold',
		'confirming'  => 'on-hold',
		'partial'     => 'on-hold',
		'expired'     => 'cancelled',
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
		$this->method_description = __(
			'Accept Monero at checkout without ever holding customer funds or your own spend key - Monokulo watches the chain for you and only ever needs a view key. Connect your wallet to enable this gateway for real.',
			'monokulo'
		);
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
				'label'   => __( 'Enable Monero payments via Monokulo', 'monokulo' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'The label the customer sees for this payment method at checkout.', 'monokulo' ),
				'default'     => __( 'Monero (via Monokulo)', 'monokulo' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'monokulo' ),
				'type'        => 'textarea',
				'description' => __( 'The explanatory text the customer sees for this payment method at checkout.', 'monokulo' ),
				'default'     => __( 'Pay with Monero. You will be redirected to a secure Monokulo payment page to complete your purchase.', 'monokulo' ),
				'desc_tip'    => true,
			),
			'connection'     => array(
				'title'       => __( 'Connection', 'monokulo' ),
				'type'        => 'title',
				'description' => __( 'Click below to connect your Monero wallet through Monokulo - this fills in everything below automatically and enables this gateway.', 'monokulo' ),
			),
			'address'        => array(
				'title'       => __( 'Monokulo address', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'The Monokulo your store is on: the public one at monokulo.com, or your own. Save after changing it, then Connect.', 'monokulo' ),
				'default'     => self::DEFAULT_MONOKULO_ADDRESS,
				'placeholder' => self::DEFAULT_MONOKULO_ADDRESS,
				'desc_tip'    => true,
			),
			'connect'        => array(
				'type'        => 'monokulo_connect',
				'description' => __( 'You will be sent to Monokulo to sign in (or sign up) and confirm the connection, then returned here automatically.', 'monokulo' ),
			),
			'endpoint'       => array(
				'title'       => __( 'Checkout address', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'Where orders are made and customers pay, as Monokulo said when this store connected. Filled in automatically by Connect above.', 'monokulo' ),
				'default'     => '',
				'placeholder' => 'https://pay.example.com',
				'desc_tip'    => true,
			),
			'onion_endpoint' => array(
				'title'       => __( 'Monokulo onion address', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'Where customers shopping on this shop\'s own .onion address are sent to pay, so they stay on Tor. Filled in automatically by Connect above; empty when Monokulo has no onion address.', 'monokulo' ),
				'default'     => '',
				'placeholder' => 'http://abc...xyz.onion',
				'desc_tip'    => true,
			),
			'public_key'     => array(
				'title'       => __( 'Store public key', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'Your Monokulo store\'s public key (starts with pk_). Filled in automatically by Connect above.', 'monokulo' ),
				'default'     => '',
				'placeholder' => 'pk_...',
				'desc_tip'    => true,
			),
			'secret_token'   => array(
				'title'       => __( 'Store secret key', 'monokulo' ),
				'type'        => 'password',
				'description' => __( 'Your Monokulo store\'s secret key (starts with sk_). Filled in automatically by Connect above. Used to create orders from this site\'s server; never shown to customers.', 'monokulo' ),
				'default'     => '',
				'placeholder' => 'sk_...',
				'desc_tip'    => true,
			),
			'forward_errors' => array(
				'title'       => __( 'Send errors to Monokulo', 'monokulo' ),
				'type'        => 'checkbox',
				'label'       => __( 'Also send this plugin\'s warnings and errors to Monokulo\'s Logs page', 'monokulo' ),
				'description' => __( 'They stay in WooCommerce\'s own logs as well. Sent with your store\'s secret key, next to Monokulo\'s own lines for the same request. Monokulo keeps them only while Diagnostics is turned on in this store\'s settings there.', 'monokulo' ),
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
			throw new Exception( esc_html__( 'Could not load this order to start the Monero payment.', 'monokulo' ) );
		}

		$monokulo_order_id = $this->create_monokulo_order( $order );

		$order->update_meta_data( self::META_ORDER_ID, $monokulo_order_id );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Monokulo order_id */
				__( 'Customer redirected to Monokulo checkout for order_id %s.', 'monokulo' ),
				$monokulo_order_id
			)
		);
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $this->checkout_url( $monokulo_order_id ),
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
			throw new Exception( esc_html__( 'Monero payments are not fully configured for this store yet. Please contact the store owner.', 'monokulo' ) );
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
			throw new Exception( esc_html__( 'Could not reach Monokulo to start this payment. Please try again shortly.', 'monokulo' ) );
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
			throw new Exception( esc_html__( 'Monokulo returned an unexpected response. Please contact the store.', 'monokulo' ) );
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
		$description = trim( (string) preg_replace( '/\p{Cc}+/u', ' ', $description ) );
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
		return __( 'Monokulo could not start this payment. Please contact the store or try again.', 'monokulo' );
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
	 * null when it isn't an http(s) address with a host.
	 *
	 * @param string $typed What the merchant typed.
	 */
	public static function clean_address( string $typed ): ?string {
		$address = rtrim( trim( $typed ), '/' );
		$scheme  = wp_parse_url( $address, PHP_URL_SCHEME );
		$host    = wp_parse_url( $address, PHP_URL_HOST );
		if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) || empty( $host ) ) {
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
				WC_Admin_Settings::add_error( __( 'The Monokulo address must start with https:// (or http://), like https://monokulo.com.', 'monokulo' ) );
			}
			return (string) $this->get_option( $key );
		}
		return $address;
	}

	/**
	 * The Connect button. Each render makes a new nonce, good for ten
	 * minutes as Monokulo's connect token is, since the link carries it.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 */
	public function generate_monokulo_connect_html( $key, $data ): string {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'desc_tip'    => false,
				'description' => '',
			)
		);

		$nonce = bin2hex( random_bytes( 16 ) );
		set_transient( self::nonce_transient(), $nonce, 10 * MINUTE_IN_SECONDS );
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
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Connect your Monero wallet', 'monokulo' ); ?></label>
			</th>
			<td class="forminp">
				<?php if ( $this->has_credentials() ) : ?>
					<p>
						<?php esc_html_e( 'Connected as', 'monokulo' ); ?>
						<code><?php echo esc_html( $this->public_key ); ?></code>
					</p>
				<?php endif; ?>
				<a href="<?php echo esc_url( $connect_url ); ?>" id="<?php echo esc_attr( $field_key ); ?>" class="button button-primary">
					<?php echo $this->has_credentials() ? esc_html__( 'Reconnect your Monero wallet', 'monokulo' ) : esc_html__( 'Connect your Monero wallet', 'monokulo' ); ?>
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
	 * The transient holding the connect nonce.
	 */
	private static function nonce_transient(): string {
		return 'monokulo_connect_nonce_' . self::ID;
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
	 * Checks the nonce (used up whatever happens), redeems the token at
	 * `/finish`, saves the connection and turns the gateway on. A failure
	 * changes no setting.
	 *
	 * @param array{token?: string, nonce?: string} $query The connect flow's `token` and `nonce`.
	 * @return string The settings page, with `monokulo_connected` or
	 *                `monokulo_connect_error` for the notice.
	 */
	public function process_connect_return( array $query ): string {
		$token  = (string) ( $query['token'] ?? '' );
		$nonce  = (string) ( $query['nonce'] ?? '' );
		$stored = get_transient( self::nonce_transient() );
		delete_transient( self::nonce_transient() );

		if ( '' === $token || '' === $nonce || ! is_string( $stored ) || ! hash_equals( $stored, $nonce ) ) {
			$this->log( 'Connect return rejected: missing token, or the nonce did not match what this site generated.', 'warning' );
			return self::settings_url( array( 'monokulo_connect_error' => 'nonce' ) );
		}

		$status = 0;
		$finish = $this->call_connect_finish( $token, $status );
		if ( null === $finish ) {
			// 503: this Monokulo can't connect plugins yet; trying later can work.
			return self::settings_url( array( 'monokulo_connect_error' => 503 === $status ? 'unavailable' : 'finish' ) );
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
	 * - 200: applied, already applied before, or an event this version
	 *   doesn't know (logged).
	 *
	 * Anything but a 2xx is retried by Monokulo.
	 *
	 * @param string $body      The request body as sent.
	 * @param string $signature The `X-Monokulo-Signature` header, or ''.
	 * @param int    $now       The time to check the signature against.
	 */
	public function process_webhook( string $body, string $signature, ?int $now = null ): int {
		if ( ! self::verify_signature( $this->webhook_signing_secret, $body, $signature, $now ?? time() ) ) {
			$this->log( 'Webhook rejected: missing or invalid X-Monokulo-Signature.', 'warning' );
			return 401;
		}

		$event = json_decode( $body, true );
		foreach ( array( 'event', 'event_id', 'order_id' ) as $field ) {
			if ( ! is_array( $event ) || empty( $event[ $field ] ) || ! is_string( $event[ $field ] ) ) {
				$this->log( sprintf( 'Webhook rejected: correctly signed but not the expected envelope shape: %s', $body ), 'error' );
				return 400;
			}
		}

		$order = self::find_order( $event['order_id'] );
		if ( null === $order ) {
			$this->log( sprintf( 'Webhook for order_id %s (event %s) has no matching order on this site.', $event['order_id'], $event['event_id'] ), 'warning' );
			return 404;
		}

		$applied = json_decode( (string) $order->get_meta( self::META_APPLIED_EVENT_IDS ), true );
		$applied = is_array( $applied ) ? $applied : array();
		if ( in_array( $event['event_id'], $applied, true ) ) {
			return 200;
		}

		$this->apply_event( $order, $event );
		$applied[] = $event['event_id'];
		$order->update_meta_data( self::META_APPLIED_EVENT_IDS, wp_json_encode( $applied ) );
		$order->save();

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
	 * The order a Monokulo order belongs to.
	 *
	 * @param string $monokulo_order_id Monokulo's order id.
	 */
	private static function find_order( string $monokulo_order_id ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'meta_key'   => self::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $monokulo_order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'limit'      => 1,
			)
		);
		return ( $orders[0] ?? null ) instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * Applies one event. The double-spend events only add a note: when they
	 * change the order's status, Monokulo sends that as its own
	 * `order.<status>` event. Unknown events are logged and ignored, so a
	 * newer Monokulo doesn't break the shop.
	 *
	 * @param WC_Order $order The order.
	 * @param array    $event The decoded webhook body.
	 */
	private function apply_event( WC_Order $order, array $event ): void {
		$type   = $event['event'];
		$status = str_starts_with( $type, 'order.' ) ? substr( $type, strlen( 'order.' ) ) : '';

		if ( 'paid' === $status || 'overpaid' === $status || isset( self::STATUS_MAP[ $status ] ) ) {
			$this->apply_status( $order, $status );
			return;
		}

		if ( 'order.double_spend_detected' === $type ) {
			$order->add_order_note( __( 'Monokulo: FRAUD ALERT - a payment previously credited to this order was proven to be a double-spend and has been voided by the engine. If this changed the order\'s payment status, that change was announced separately. Please review this order.', 'monokulo' ) );
			return;
		}

		if ( 'order.double_spend_reversed' === $type ) {
			$txid = is_string( $event['txid'] ?? null ) ? $event['txid'] : __( 'unknown', 'monokulo' );
			/* translators: %s: the transaction id the earlier double-spend accusation was about */
			$order->add_order_note( sprintf( __( 'Monokulo: an earlier double-spend accusation against this order (transaction %s) was a false positive and has been reversed by the engine. If this changed the order\'s payment status, that change was announced separately.', 'monokulo' ), $txid ) );
			return;
		}

		$this->log( sprintf( 'Webhook: unrecognized event type "%s" - envelope accepted, no action taken.', $type ), 'warning' );
	}

	/**
	 * Moves the order for an `order.<status>` event:
	 *
	 * - paid, overpaid: `payment_complete()`, which picks Processing or
	 *   Completed, reduces stock and sends WooCommerce's emails; an
	 *   overpayment also gets a note that the excess isn't refunded;
	 * - unconfirmed, confirming, partial: On hold (seen, not yet trusted);
	 * - expired: Cancelled, with a note that funds received stay at the address;
	 * - pending: Pending (a double-spend voided the only payment).
	 *
	 * @param WC_Order $order  The order.
	 * @param string   $status Monokulo's status.
	 */
	private function apply_status( WC_Order $order, string $status ): void {
		if ( 'paid' === $status || 'overpaid' === $status ) {
			if ( 'overpaid' === $status ) {
				$order->add_order_note( __( 'Monokulo: the customer sent more Monero than the amount due. The excess is not automatically refunded - handle any refund manually.', 'monokulo' ) );
			}
			$order->payment_complete();
			return;
		}

		$note = match ( $status ) {
			'partial' => __( 'Monokulo: a partial payment was received - the full amount due has not yet arrived.', 'monokulo' ),
			'expired' => __( 'Monokulo: this order\'s payment window has closed without full payment. If any funds were received, they remain at the payment address - there is no automatic refund; handle manually.', 'monokulo' ),
			default   => '',
		};
		$order->update_status( self::STATUS_MAP[ $status ], $note );
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
		$error = isset( $_GET['monokulo_connect_error'] ) ? sanitize_key( wp_unslash( $_GET['monokulo_connect_error'] ) ) : null;
		if ( isset( $_GET['monokulo_connected'] ) ) {
			$class   = 'notice-success';
			$message = __( 'Your Monero wallet is connected - Monero payments are now enabled.', 'monokulo' );
		} elseif ( 'unavailable' === $error ) {
			$class   = 'notice-error';
			$message = __( 'Monokulo is not ready to connect plugins yet: its operator has not set its public address. Please try again later.', 'monokulo' );
		} elseif ( null !== $error ) {
			$class   = 'notice-error';
			$message = __( 'Could not connect your Monero wallet. Please try again.', 'monokulo' );
		} else {
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
