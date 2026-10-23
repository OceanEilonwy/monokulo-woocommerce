<?php
/**
 * The Monokulo payment gateway.
 *
 * @package Monokulo
 */

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
 * - Monokulo's webhooks (`handle_webhook()`) move the WooCommerce order.
 *
 * Monokulo prices the order and watches the chain; the plugin never decides
 * on its own that an order is paid.
 */
class WC_Gateway_Monokulo extends WC_Payment_Gateway {

	/**
	 * Where the "Monokulo address" setting starts: the public Monokulo.
	 */
	const DEFAULT_MONOKULO_ADDRESS = 'https://monokulo.com';

	/**
	 * The connect path's slug: `/plugins/connect/woocommerce`.
	 */
	const CONNECT_PLATFORM = 'woocommerce';

	/**
	 * What Monokulo calls the plugin on its pages.
	 */
	const CONNECT_NAME = 'WooCommerce';

	/**
	 * The `Monokulo-Client` header. Kept equal to VERSION by a test.
	 */
	const CLIENT = 'woocommerce/0.3.0';

	/**
	 * The longest order description Monokulo accepts, in characters.
	 */
	const MAX_DESCRIPTION_CHARS = 140;

	/**
	 * The `admin-post.php` action the connect flow comes back to.
	 */
	const CONNECT_RETURN_ACTION = 'monokulo_connect_return';

	/**
	 * Order meta: the Monokulo order the customer was sent to pay.
	 */
	const META_ORDER_ID = '_monokulo_order_id';

	/**
	 * Order meta: the webhook events already applied, as a JSON list.
	 */
	const META_APPLIED_EVENT_IDS = '_monokulo_applied_webhook_event_ids';

	/**
	 * `X-Monokulo-Signature`, as PHP names it in `$_SERVER`.
	 */
	const WEBHOOK_SIGNATURE_SERVER_KEY = 'HTTP_X_MONOKULO_SIGNATURE';

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
	 * Where orders are made and customers pay (`endpoint` from connect).
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Monokulo's onion address, where customers on the shop's own .onion
	 * address pay; empty when Monokulo has none.
	 *
	 * @var string
	 */
	private $onion_endpoint;

	/**
	 * The store's public key (`pk_…`).
	 *
	 * @var string
	 */
	private $public_key;

	/**
	 * The store's secret key (`sk_…`), sent only to Monokulo.
	 *
	 * @var string
	 */
	private $secret_token;

	/**
	 * The key webhook deliveries are signed with (`whsec_…`).
	 *
	 * @var string
	 */
	private $webhook_signing_secret;

	/**
	 * This PHP request's trace id (W3C Trace Context), sent to Monokulo and
	 * written on every log line. Null until first used.
	 *
	 * @var string|null
	 */
	private static $trace_id = null;

	/**
	 * Warnings and errors to send to Monokulo when this request ends.
	 *
	 * @var array<int, array{level: string, message: string, trace_id: string}>
	 */
	private static $forward_queue = array();

	/**
	 * Whether the send at the end of the request is registered.
	 *
	 * @var bool
	 */
	private static $forward_registered = false;

	/**
	 * Most entries sent per request; Monokulo refuses more.
	 */
	const MAX_FORWARDED = 20;

	/**
	 * Log levels sent to Monokulo.
	 */
	const FORWARDED_LEVELS = array( 'warning', 'error', 'critical', 'alert', 'emergency' );

	/**
	 * Sets the gateway up from its saved settings.
	 */
	public function __construct() {
		$this->id                 = 'monokulo';
		$this->icon               = plugins_url( 'assets/monokulo-icon.svg', dirname( __DIR__ ) . '/monokulo.php' );
		$this->has_fields         = false;
		$this->method_title       = __( 'Monokulo', 'monokulo' );
		$this->method_description = __(
			'Accept Monero at checkout without ever holding customer funds or your own spend key - Monokulo watches the chain for you and only ever needs a view key. Connect your wallet to enable this gateway for real.',
			'monokulo'
		);
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Monero (via Monokulo)', 'monokulo' ) );
		$this->description = $this->get_option(
			'description',
			__( 'Pay with Monero. You will be redirected to a secure Monokulo payment page to complete your purchase.', 'monokulo' )
		);

		$this->endpoint               = trim( (string) $this->get_option( 'endpoint', '' ) );
		$this->onion_endpoint         = trim( (string) $this->get_option( 'onion_endpoint', '' ) );
		$this->public_key             = trim( (string) $this->get_option( 'public_key', '' ) );
		$this->secret_token           = trim( (string) $this->get_option( 'secret_token', '' ) );
		$this->webhook_signing_secret = trim( (string) $this->get_option( 'webhook_signing_secret', '' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_post_' . self::CONNECT_RETURN_ACTION, array( $this, 'handle_connect_return' ) );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'handle_webhook' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_connect_notice' ) );
	}

	/**
	 * The settings form. Connect fills in the checkout address and keys;
	 * the webhook signing secret is saved too but never shown.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'monokulo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Monero payments via Monokulo', 'monokulo' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'The label the customer sees for this payment method at checkout.', 'monokulo' ),
				'default'     => __( 'Monero (via Monokulo)', 'monokulo' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'monokulo' ),
				'type'        => 'textarea',
				'description' => __( 'The explanatory text the customer sees for this payment method at checkout.', 'monokulo' ),
				'default'     => __( 'Pay with Monero. You will be redirected to a secure Monokulo payment page to complete your purchase.', 'monokulo' ),
				'desc_tip'    => true,
			),

			'connection'  => array(
				'title'       => __( 'Connection', 'monokulo' ),
				'type'        => 'title',
				'description' => __( 'Click below to connect your Monero wallet through Monokulo - this fills in everything below automatically and enables this gateway.', 'monokulo' ),
			),
			'address'     => array(
				'title'       => __( 'Monokulo address', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'The Monokulo your store is on: the public one at monokulo.com, or your own. Save after changing it, then Connect.', 'monokulo' ),
				'default'     => self::DEFAULT_MONOKULO_ADDRESS,
				'placeholder' => self::DEFAULT_MONOKULO_ADDRESS,
				'desc_tip'    => true,
			),
			'connect'     => array(
				'type'        => 'monokulo_connect',
				'description' => __( 'You will be sent to Monokulo to sign in (or sign up) and confirm the connection, then returned here automatically.', 'monokulo' ),
			),

			'endpoint'    => array(
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
			'public_key'  => array(
				'title'       => __( 'Store public key', 'monokulo' ),
				'type'        => 'text',
				'description' => __( 'Your Monokulo store\'s public key (starts with pk_). Filled in automatically by Connect above.', 'monokulo' ),
				'default'     => '',
				'placeholder' => 'pk_...',
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
			'secret_token' => array(
				'title'       => __( 'Store secret key', 'monokulo' ),
				'type'        => 'password',
				'description' => __( 'Your Monokulo store\'s secret key (starts with sk_). Filled in automatically by Connect above. Used to create orders from this site\'s server; never shown to customers.', 'monokulo' ),
				'default'     => '',
				'placeholder' => 'sk_...',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Offered at checkout only while the store has its whole connection.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		return $this->has_credentials();
	}

	/**
	 * Whether the checkout address and both keys are set.
	 *
	 * @return bool
	 */
	private function has_credentials() {
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
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			throw new Exception( __( 'Could not load this order to start the Monero payment.', 'monokulo' ) );
		}

		$monokulo_order = $this->create_monokulo_order( $order );

		$order->update_meta_data( self::META_ORDER_ID, $monokulo_order['order_id'] );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Monokulo order_id */
				__( 'Customer redirected to Monokulo checkout for order_id %s.', 'monokulo' ),
				$monokulo_order['order_id']
			)
		);
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_checkout_url( $monokulo_order['order_id'] ),
		);
	}

	/**
	 * `POST {endpoint}/pay/{pk}/orders` with the store's secret key. Monokulo
	 * prices the amount in XMR. Failures are logged in full for the merchant
	 * and thrown with a short message for the customer.
	 *
	 * @param WC_Order $order The order.
	 * @return array Monokulo's order, with at least `order_id`.
	 * @throws Exception When Monokulo can't be reached or refuses.
	 */
	private function create_monokulo_order( WC_Order $order ) {
		if ( ! $this->has_credentials() ) {
			throw new Exception(
				__( 'Monero payments are not fully configured for this store yet. Please contact the store owner.', 'monokulo' )
			);
		}

		$request_url = $this->get_orders_endpoint_url();
		$currency    = $order->get_currency();
		$amount      = self::format_amount( $order->get_total(), $currency );

		$body = array(
			'amount'                 => $amount,
			'currency'               => $currency,
			// The order's ID is the merchant's reference: Monokulo shows it in
			// the dashboard and webhooks, not to the customer, who knows the
			// order by its number (in the description).
			'merchant_order_id'      => (string) $order->get_id(),
			'show_merchant_order_id' => false,
			'description'            => self::order_description( $order ),
			'idempotency_key'        => self::idempotency_key( $order->get_id(), $amount, $currency ),
		);

		$response = wp_remote_post(
			$request_url,
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
			$this->log(
				sprintf( 'Order creation request to %s failed: %s', $request_url, $response->get_error_message() ),
				'error'
			);
			throw new Exception(
				__( 'Could not reach Monokulo to start this payment. Please try again shortly.', 'monokulo' )
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$decoded_error = json_decode( $raw_body, true );
			$detail        = is_array( $decoded_error ) && isset( $decoded_error['error'] ) ? (string) $decoded_error['error'] : $raw_body;
			$this->log(
				sprintf(
					'Order creation request to %s returned HTTP %d: %s%s',
					$request_url,
					$status_code,
					$detail,
					self::hint_for_status( $status_code )
				),
				'error'
			);
			throw new Exception( self::customer_message_for_status( $status_code ) );
		}

		$decoded = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['order_id'] ) ) {
			$this->log(
				sprintf( 'Order creation response from %s was not the expected shape: %s', $request_url, $raw_body ),
				'error'
			);
			throw new Exception(
				__( 'Monokulo returned an unexpected response. Please contact the store.', 'monokulo' )
			);
		}

		return $decoded;
	}

	/**
	 * "Order #1042: Blue mug + 2 more", on one line, at most 140 characters.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	public static function order_description( WC_Order $order ) {
		$description = sprintf(
			/* translators: %s: the order number */
			__( 'Order #%s', 'monokulo' ),
			$order->get_order_number()
		);
		$items = array_values( $order->get_items() );
		if ( $items ) {
			$description .= ': ' . $items[0]->get_name();
			if ( count( $items ) > 1 ) {
				$description .= sprintf(
					/* translators: %d: how many other items the order has */
					__( ' + %d more', 'monokulo' ),
					count( $items ) - 1
				);
			}
		}
		$description = trim( preg_replace( '/\p{Cc}+/u', ' ', $description ) );
		if ( mb_strlen( $description, 'UTF-8' ) > self::MAX_DESCRIPTION_CHARS ) {
			$description = rtrim( mb_substr( $description, 0, self::MAX_DESCRIPTION_CHARS - 1, 'UTF-8' ) ) . '…';
		}
		return $description;
	}

	/**
	 * One key per order and total: a retry gets the Monokulo order already
	 * made, a changed total a new one.
	 *
	 * @param int|string $order_id The WooCommerce order id.
	 * @param string     $amount   The amount sent.
	 * @param string     $currency The currency code.
	 * @return string
	 */
	public static function idempotency_key( $order_id, $amount, $currency ) {
		return sprintf( 'wc:%s:%s:%s', $order_id, $amount, $currency );
	}

	/**
	 * The total as Monokulo parses it: `.` for the point, no thousands
	 * separator. Two decimals for fiat; XMR keeps the shop's price decimals,
	 * up to twelve.
	 *
	 * @param string|float $total    The order total.
	 * @param string       $currency The order currency.
	 * @return string
	 */
	private static function format_amount( $total, $currency ) {
		$decimals = 2;
		if ( 'XMR' === strtoupper( $currency ) ) {
			$decimals = function_exists( 'wc_get_price_decimals' ) ? min( 12, max( 2, (int) wc_get_price_decimals() ) ) : 12;
		}
		return number_format( (float) $total, $decimals, '.', '' );
	}

	/**
	 * What the customer is told when Monokulo refuses an order.
	 *
	 * @param int $status_code Monokulo's HTTP status.
	 * @return string
	 */
	private static function customer_message_for_status( $status_code ) {
		if ( 429 === $status_code ) {
			return __( 'Monero payments are busy right now. Please wait a moment and try again.', 'monokulo' );
		}
		if ( 401 === $status_code || 403 === $status_code ) {
			return __( 'Monero payments are not available for this store right now. Please contact the store owner.', 'monokulo' );
		}
		return __( 'Monokulo could not start this payment. Please contact the store or try again.', 'monokulo' );
	}

	/**
	 * A hint in the merchant's log, for statuses they can fix.
	 *
	 * @param int $status_code Monokulo's HTTP status.
	 * @return string
	 */
	private static function hint_for_status( $status_code ) {
		switch ( $status_code ) {
			case 401:
				return ' (the store secret key was rejected - reconnect the plugin from its settings page)';
			case 403:
				return ' (Monokulo refused the order - check the store\'s verified-domain settings on Monokulo)';
			case 404:
				return ' (Monokulo does not know this store\'s public key - reconnect the plugin)';
			case 429:
				return ' (rate limited - Monokulo is receiving too many orders from this store)';
			default:
				return '';
		}
	}

	/**
	 * `{endpoint}/pay/{pk}/orders`.
	 *
	 * @return string
	 */
	private function get_orders_endpoint_url() {
		return sprintf(
			'%s/pay/%s/orders',
			rtrim( $this->endpoint, '/' ),
			rawurlencode( $this->public_key )
		);
	}

	/**
	 * Where this customer pays: Monokulo's onion address when they shop on
	 * the shop's own .onion address and Monokulo has one, so they stay on
	 * Tor; the checkout address otherwise.
	 *
	 * @return string
	 */
	private function checkout_address() {
		if ( '' !== $this->onion_endpoint && self::is_onion_host( self::request_host() ) ) {
			return $this->onion_endpoint;
		}
		return $this->endpoint;
	}

	/**
	 * The host the customer's browser asked for, without a port.
	 *
	 * @return string
	 */
	private static function request_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		return strtolower( (string) preg_replace( '/:\d+$/', '', $host ) );
	}

	/**
	 * Whether `$host` is an onion address.
	 *
	 * @param string $host A host name.
	 * @return bool
	 */
	private static function is_onion_host( $host ) {
		return '.onion' === substr( rtrim( strtolower( $host ), '.' ), -6 );
	}

	/**
	 * `{checkout address}/pay/{pk}/orders/{order_id}`: where the customer pays.
	 *
	 * @param string $order_id Monokulo's order id.
	 * @return string
	 */
	private function get_checkout_url( $order_id ) {
		return sprintf(
			'%s/pay/%s/orders/%s',
			rtrim( $this->checkout_address(), '/' ),
			rawurlencode( $this->public_key ),
			rawurlencode( $order_id )
		);
	}

	/**
	 * The "Monokulo address" setting: where Connect goes.
	 *
	 * @return string
	 */
	private function monokulo_address() {
		$address = self::clean_address( (string) $this->get_option( 'address', self::DEFAULT_MONOKULO_ADDRESS ) );
		return null === $address ? self::DEFAULT_MONOKULO_ADDRESS : $address;
	}

	/**
	 * A typed Monokulo address, trimmed and without a trailing slash, or
	 * null when it isn't an http(s) address with a host.
	 *
	 * @param string $typed What the merchant typed.
	 * @return string|null
	 */
	public static function clean_address( $typed ) {
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
	 * @return string
	 */
	public function validate_address_field( $key, $value ) {
		$address = self::clean_address( (string) $value );
		if ( null === $address ) {
			if ( class_exists( 'WC_Admin_Settings' ) ) {
				WC_Admin_Settings::add_error( __( 'The Monokulo address must start with https:// (or http://), like https://monokulo.com.', 'monokulo' ) );
			}
			return (string) $this->get_option( $key, self::DEFAULT_MONOKULO_ADDRESS );
		}
		return $address;
	}

	/**
	 * Where Monokulo sends the admin's browser back after connecting.
	 *
	 * @return string
	 */
	private function get_connect_return_url() {
		return admin_url( 'admin-post.php?action=' . self::CONNECT_RETURN_ACTION );
	}

	/**
	 * The transient holding the connect nonce, good for ten minutes, as
	 * Monokulo's connect token is.
	 *
	 * @return string
	 */
	private function connect_nonce_transient_key() {
		return 'monokulo_connect_nonce_' . $this->id;
	}

	/**
	 * `GET {monokulo address}/plugins/connect/woocommerce?site_url=…&return_url=…&nonce=…&name=…`.
	 *
	 * @param string $nonce The nonce, already stored.
	 * @return string
	 */
	private function build_connect_start_url( $nonce ) {
		return sprintf(
			'%s/plugins/connect/%s?site_url=%s&return_url=%s&nonce=%s&name=%s',
			rtrim( $this->monokulo_address(), '/' ),
			rawurlencode( self::CONNECT_PLATFORM ),
			rawurlencode( home_url( '/' ) ),
			rawurlencode( $this->get_connect_return_url() ),
			rawurlencode( $nonce ),
			rawurlencode( self::CONNECT_NAME )
		);
	}

	/**
	 * The Connect button. Each render makes a new nonce, since the link
	 * carries it.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_monokulo_connect_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'desc_tip'    => false,
				'description' => '',
			)
		);

		$nonce = bin2hex( random_bytes( 16 ) );
		set_transient( $this->connect_nonce_transient_key(), $nonce, 10 * MINUTE_IN_SECONDS );
		$connect_url = $this->build_connect_start_url( $nonce );

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
					<?php
					echo $this->has_credentials()
						? esc_html__( 'Reconnect your Monero wallet', 'monokulo' )
						: esc_html__( 'Connect your Monero wallet', 'monokulo' );
					?>
				</a>
				<p>
					<?php esc_html_e( 'Connects to', 'monokulo' ); ?>
					<code><?php echo esc_html( $this->monokulo_address() ); ?></code>
				</p>
				<?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * `admin-post.php?action=monokulo_connect_return`: finishes connecting,
	 * then sends the browser to the settings page. Only for shop managers;
	 * the nonce check is in `process_connect_return()`.
	 */
	public function handle_connect_return() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'monokulo' ), '', array( 'response' => 403 ) );
		}

		$redirect_url = $this->process_connect_return( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Checks the nonce (used up whatever happens), redeems the token at
	 * `/finish`, saves the connection and turns the gateway on. A failure
	 * changes no setting.
	 *
	 * @param array $query_args The request's query (`token`, `nonce`), unslashed.
	 * @return string The settings page, with `monokulo_connected` or
	 *                `monokulo_connect_error` for the notice.
	 */
	public function process_connect_return( array $query_args ) {
		$token = isset( $query_args['token'] ) ? sanitize_text_field( $query_args['token'] ) : '';
		$nonce = isset( $query_args['nonce'] ) ? sanitize_text_field( $query_args['nonce'] ) : '';

		$stored_nonce = get_transient( $this->connect_nonce_transient_key() );
		delete_transient( $this->connect_nonce_transient_key() );

		if ( '' === $token || '' === $nonce || false === $stored_nonce || ! hash_equals( (string) $stored_nonce, $nonce ) ) {
			$this->log( 'Connect return rejected: missing token, or the nonce did not match what this site generated.', 'warning' );
			return $this->build_settings_url( array( 'monokulo_connect_error' => 'nonce' ) );
		}

		$finish_status = 0;
		$finish        = $this->call_connect_finish( $token, $finish_status );
		if ( null === $finish ) {
			// 503: this Monokulo can't connect plugins yet; trying later can work.
			$reason = 503 === $finish_status ? 'unavailable' : 'finish';
			return $this->build_settings_url( array( 'monokulo_connect_error' => $reason ) );
		}

		$this->update_option( 'endpoint', $finish['endpoint'] );
		// Only an http:// .onion address is somewhere to send a customer on Tor.
		$onion = isset( $finish['onion_endpoint'] ) ? (string) $finish['onion_endpoint'] : '';
		$host  = (string) wp_parse_url( $onion, PHP_URL_HOST );
		$this->update_option( 'onion_endpoint', 0 === strpos( $onion, 'http://' ) && self::is_onion_host( $host ) ? $onion : '' );
		$this->update_option( 'public_key', $finish['public_key'] );
		$this->update_option( 'secret_token', $finish['secret_token'] );
		if ( ! empty( $finish['webhook_signing_secret'] ) ) {
			$this->update_option( 'webhook_signing_secret', $finish['webhook_signing_secret'] );
		}
		$this->update_option( 'enabled', 'yes' );

		return $this->build_settings_url( array( 'monokulo_connected' => '1' ) );
	}

	/**
	 * `POST {monokulo address}/plugins/connect/woocommerce/finish`: redeems
	 * the token for the store's keys and registers the webhook.
	 *
	 * @param string $token       The connect token.
	 * @param int    $status_code Set to Monokulo's HTTP status (0 when unreachable).
	 * @return array|null The credentials, or null on any failure (logged).
	 */
	private function call_connect_finish( $token, &$status_code = 0 ) {
		$url = rtrim( $this->monokulo_address(), '/' ) . '/plugins/connect/' . self::CONNECT_PLATFORM . '/finish';

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
						'webhook_url' => $this->get_webhook_receiver_url(),
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

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$this->log( sprintf( 'Connect finish request to %s returned HTTP %d: %s', $url, $status_code, $raw_body ), 'error' );
			return null;
		}

		$decoded = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['public_key'] ) || empty( $decoded['secret_token'] ) || empty( $decoded['endpoint'] ) ) {
			$this->log( sprintf( 'Connect finish response from %s was not the expected shape: %s', $url, $raw_body ), 'error' );
			return null;
		}

		return $decoded;
	}

	/**
	 * Where Monokulo sends webhooks.
	 *
	 * @return string
	 */
	private function get_webhook_receiver_url() {
		return WC()->api_request_url( $this->id );
	}

	/**
	 * Monokulo's webhook deliveries. The signature is checked against the
	 * raw body, so it is read from `php://input`. Monokulo reads only the
	 * status.
	 */
	public function handle_webhook() {
		$raw_body  = (string) file_get_contents( 'php://input' );
		$signature = isset( $_SERVER[ self::WEBHOOK_SIGNATURE_SERVER_KEY ] )
			? (string) wp_unslash( $_SERVER[ self::WEBHOOK_SIGNATURE_SERVER_KEY ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			: '';
		self::adopt_traceparent( isset( $_SERVER['HTTP_TRACEPARENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_TRACEPARENT'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against a strict pattern.

		status_header( $this->process_webhook_request( $raw_body, $signature ) );
		exit;
	}

	/**
	 * Handles one delivery and says which status to answer:
	 *
	 * - 401: the signature is missing or doesn't check out;
	 * - 400: signed, but not a webhook envelope;
	 * - 404: no order here for that Monokulo order;
	 * - 200: applied, already applied before, or an event this version
	 *   doesn't know (logged).
	 *
	 * Anything but a 2xx is retried by Monokulo.
	 *
	 * @param string $raw_body  The request body as sent.
	 * @param string $signature The `X-Monokulo-Signature` header, or ''.
	 * @return int
	 */
	public function process_webhook_request( $raw_body, $signature ) {
		if ( ! $this->verify_webhook_signature( $raw_body, $signature ) ) {
			$this->log( 'Webhook rejected: missing or invalid X-Monokulo-Signature.', 'warning' );
			return 401;
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event )
			|| empty( $event['event'] ) || ! is_string( $event['event'] )
			|| empty( $event['event_id'] ) || ! is_string( $event['event_id'] )
			|| empty( $event['order_id'] ) || ! is_string( $event['order_id'] )
		) {
			$this->log( sprintf( 'Webhook rejected: correctly signed but not the expected envelope shape: %s', $raw_body ), 'error' );
			return 400;
		}

		$order = $this->find_order_by_order_id( $event['order_id'] );
		if ( ! $order instanceof WC_Order ) {
			$this->log(
				sprintf( 'Webhook for order_id %s (event %s) has no matching order on this site.', $event['order_id'], $event['event_id'] ),
				'warning'
			);
			return 404;
		}

		if ( $this->event_already_applied( $order, $event['event_id'] ) ) {
			return 200;
		}

		$this->apply_webhook_event( $order, $event );
		$this->mark_event_applied( $order, $event['event_id'] );
		$order->save();

		return 200;
	}

	/**
	 * How far, in seconds, a delivery's signed time may be from now.
	 */
	const SIGNATURE_TOLERANCE_SECS = 300;

	/**
	 * Checks `X-Monokulo-Signature: t=<unix time>,v1=<hex>`, where the hex is
	 * HMAC-SHA256 of `<t>.<raw body>` keyed with the webhook signing secret.
	 * A time more than five minutes from now is refused, so a captured
	 * delivery can't be replayed.
	 *
	 * @param string   $raw_body  The request body as sent.
	 * @param string   $signature The header.
	 * @param int|null $now       The time to check against; now if null.
	 * @return bool
	 */
	private function verify_webhook_signature( $raw_body, $signature, $now = null ) {
		if ( '' === $this->webhook_signing_secret || ! is_string( $signature )
			|| ! preg_match( '/\At=(\d{1,18}),v1=([0-9a-fA-F]{64})\z/', $signature, $parts ) ) {
			return false;
		}
		if ( abs( ( null === $now ? time() : (int) $now ) - (int) $parts[1] ) > self::SIGNATURE_TOLERANCE_SECS ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $parts[1] . '.' . $raw_body, $this->webhook_signing_secret );

		return hash_equals( $expected, strtolower( $parts[2] ) );
	}

	/**
	 * The order a Monokulo order belongs to, through WooCommerce's order
	 * query, so it works with either order storage.
	 *
	 * @param string $order_id Monokulo's order id.
	 * @return WC_Order|null
	 */
	private function find_order_by_order_id( $order_id ) {
		$orders = wc_get_orders(
			array(
				'meta_key'   => self::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'limit'      => 1,
				'return'     => 'objects',
			)
		);

		return isset( $orders[0] ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * Whether this event was applied to the order before: Monokulo sends an
	 * event again when it didn't get a 2xx, including when the answer was
	 * lost.
	 *
	 * @param WC_Order $order    The order.
	 * @param string   $event_id The event's id.
	 * @return bool
	 */
	private function event_already_applied( WC_Order $order, $event_id ) {
		$applied = json_decode( (string) $order->get_meta( self::META_APPLIED_EVENT_IDS ), true );
		return is_array( $applied ) && in_array( $event_id, $applied, true );
	}

	/**
	 * Records the event as applied. The caller saves the order.
	 *
	 * @param WC_Order $order    The order.
	 * @param string   $event_id The event's id.
	 */
	private function mark_event_applied( WC_Order $order, $event_id ) {
		$applied   = json_decode( (string) $order->get_meta( self::META_APPLIED_EVENT_IDS ), true );
		$applied   = is_array( $applied ) ? $applied : array();
		$applied[] = $event_id;
		$order->update_meta_data( self::META_APPLIED_EVENT_IDS, wp_json_encode( array_values( array_unique( $applied ) ) ) );
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
	private function apply_webhook_event( WC_Order $order, array $event ) {
		$event_type = $event['event'];

		$status_by_event_type = array(
			'order.pending'     => 'pending',
			'order.unconfirmed' => 'unconfirmed',
			'order.confirming'  => 'confirming',
			'order.paid'        => 'paid',
			'order.partial'     => 'partial',
			'order.overpaid'    => 'overpaid',
			'order.expired'     => 'expired',
		);

		if ( isset( $status_by_event_type[ $event_type ] ) ) {
			$this->apply_order_status_event( $order, $status_by_event_type[ $event_type ] );
			return;
		}

		if ( 'order.double_spend_detected' === $event_type ) {
			$order->add_order_note(
				__(
					'Monokulo: FRAUD ALERT - a payment previously credited to this order was proven to be a double-spend and has been voided by the engine. If this changed the order\'s payment status, that change was announced separately. Please review this order.',
					'monokulo'
				)
			);
			return;
		}

		if ( 'order.double_spend_reversed' === $event_type ) {
			$txid = isset( $event['txid'] ) && is_string( $event['txid'] ) ? $event['txid'] : __( 'unknown', 'monokulo' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: the transaction id the earlier double-spend accusation was about */
					__(
						'Monokulo: an earlier double-spend accusation against this order (transaction %s) was a false positive and has been reversed by the engine. If this changed the order\'s payment status, that change was announced separately.',
						'monokulo'
					),
					$txid
				)
			);
			return;
		}

		$this->log( sprintf( 'Webhook: unrecognized event type "%s" - envelope accepted, no action taken.', $event_type ), 'warning' );
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
	 * @param WC_Order $order         The order.
	 * @param string   $engine_status Monokulo's status.
	 */
	private function apply_order_status_event( WC_Order $order, $engine_status ) {
		if ( 'paid' === $engine_status || 'overpaid' === $engine_status ) {
			if ( 'overpaid' === $engine_status ) {
				$order->add_order_note(
					__(
						'Monokulo: the customer sent more Monero than the amount due. The excess is not automatically refunded - handle any refund manually.',
						'monokulo'
					)
				);
			}
			$order->payment_complete();
			return;
		}

		if ( ! isset( self::STATUS_MAP[ $engine_status ] ) ) {
			$this->log( sprintf( 'Webhook: unrecognized engine status "%s" - no WooCommerce status change applied.', $engine_status ), 'warning' );
			return;
		}

		$note = '';
		if ( 'partial' === $engine_status ) {
			$note = __( 'Monokulo: a partial payment was received - the full amount due has not yet arrived.', 'monokulo' );
		} elseif ( 'expired' === $engine_status ) {
			$note = __(
				'Monokulo: this order\'s payment window has closed without full payment. If any funds were received, they remain at the payment address - there is no automatic refund; handle manually.',
				'monokulo'
			);
		}

		$order->update_status( self::STATUS_MAP[ $engine_status ], $note );
	}

	/**
	 * The gateway's settings page, with `$extra_args` added.
	 *
	 * @param array $extra_args Query arguments.
	 * @return string
	 */
	private function build_settings_url( array $extra_args ) {
		return add_query_arg(
			$extra_args,
			admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $this->id )
		);
	}

	/**
	 * Says how connecting went, on the gateway's settings page only, from
	 * the flag `process_connect_return()` put in the address.
	 */
	public function maybe_render_connect_notice() {
		if ( ! isset( $_GET['page'], $_GET['section'] ) || 'wc-settings' !== $_GET['page'] || $this->id !== $_GET['section'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( isset( $_GET['monokulo_connected'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Your Monero wallet is connected - Monero payments are now enabled.', 'monokulo' ) .
				'</p></div>';
		} elseif ( isset( $_GET['monokulo_connect_error'] ) && 'unavailable' === $_GET['monokulo_connect_error'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' .
				esc_html__( 'Monokulo is not ready to connect plugins yet: its operator has not set its public address. Please try again later.', 'monokulo' ) .
				'</p></div>';
		} elseif ( isset( $_GET['monokulo_connect_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' .
				esc_html__( 'Could not connect your Monero wallet. Please try again.', 'monokulo' ) .
				'</p></div>';
		}
	}

	/**
	 * This request's trace id, made on first use.
	 *
	 * @return string 32 lowercase hex characters.
	 */
	public static function trace_id() {
		if ( null === self::$trace_id ) {
			self::$trace_id = bin2hex( random_bytes( 16 ) );
		}
		return self::$trace_id;
	}

	/**
	 * Joins the trace of an incoming `traceparent` (version 00). Anything
	 * else is ignored.
	 *
	 * @param string $traceparent The header, or ''.
	 * @return bool Whether it was adopted.
	 */
	public static function adopt_traceparent( $traceparent ) {
		if ( ! preg_match( '/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/D', trim( (string) $traceparent ), $parts ) ) {
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
	 *
	 * @return string
	 */
	public static function traceparent() {
		return '00-' . self::trace_id() . '-' . bin2hex( random_bytes( 8 ) ) . '-01';
	}

	/**
	 * Logs to WooCommerce's logs (source `monokulo`) with the trace id.
	 *
	 * @param string $message Log message.
	 * @param string $level   A WooCommerce log level.
	 */
	private function log( $message, $level = 'info' ) {
		$this->maybe_forward( $message, $level );
		if ( function_exists( 'wc_get_logger' ) ) {
			// WooCommerce's file log writes only the message, so the trace id goes in it.
			$trace_id = self::trace_id();
			wc_get_logger()->log(
				$level,
				$message . ' [trace ' . $trace_id . ']',
				array(
					'source'   => 'monokulo',
					'trace_id' => $trace_id,
				)
			);
		}
	}

	/**
	 * Queues a warning or error for Monokulo when "Send errors to Monokulo"
	 * is on, to send when the request ends.
	 *
	 * @param string $message Log message.
	 * @param string $level   A WooCommerce log level.
	 */
	private function maybe_forward( $message, $level ) {
		if ( 'yes' !== $this->get_option( 'forward_errors', 'no' ) || ! $this->has_credentials() ) {
			return;
		}
		if ( ! in_array( $level, self::FORWARDED_LEVELS, true ) || count( self::$forward_queue ) >= self::MAX_FORWARDED ) {
			return;
		}
		self::$forward_queue[] = array(
			'level'    => $level,
			'message'  => (string) $message,
			'trace_id' => self::trace_id(),
		);
		if ( ! self::$forward_registered ) {
			self::$forward_registered = true;
			$url    = rtrim( $this->endpoint, '/' ) . '/pay/' . rawurlencode( $this->public_key ) . '/logs';
			$secret = $this->secret_token;
			register_shutdown_function(
				function () use ( $url, $secret ) {
					WC_Gateway_Monokulo::flush_forwarded( $url, $secret );
				}
			);
		}
	}

	/**
	 * Sends the queued entries in one request that isn't waited for.
	 *
	 * @param string $url    `{endpoint}/pay/{pk}/logs`.
	 * @param string $secret The store's secret key.
	 * @return array|null The request sent, or null when nothing was queued.
	 */
	public static function flush_forwarded( $url, $secret ) {
		if ( empty( self::$forward_queue ) ) {
			return null;
		}
		$args                = array(
			'headers'  => array(
				'Content-Type'    => 'application/json',
				'Authorization'   => 'Bearer ' . $secret,
				'Monokulo-Client' => self::CLIENT,
				'traceparent'     => self::traceparent(),
			),
			'body'     => wp_json_encode( array( 'entries' => self::$forward_queue ) ),
			'timeout'  => 3,
			'blocking' => false,
		);
		self::$forward_queue = array();
		wp_remote_post( $url, $args );
		return $args;
	}
}
