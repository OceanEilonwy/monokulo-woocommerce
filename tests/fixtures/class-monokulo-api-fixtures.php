<?php
/**
 * Monokulo's API resources as the plugin's tests answer with them: the
 * entry the store's secret key reads, a new order, and the connect
 * handshake's answer. Each comes as a Monokulo served at its host's root
 * or under a path (`$prefix`, `/monokulo`), with or without an onion
 * address. The smoke suite checks them against a real Monokulo's OpenAPI
 * document and the exchanges recorded in it, so they stay what Monokulo
 * sends.
 *
 * @package Monokulo
 */

declare(strict_types=1);

/**
 * The fixtures.
 */
final class Monokulo_Api_Fixtures {

	/**
	 * A Monokulo's onion address (the Tor Project's own, as Monokulo's
	 * examples use).
	 */
	const ONION = 'http://2gzyxa5ihm7nsggfxnu52rck2vv4rvmdlkiu3zzui5du4xyclen53wid.onion';

	/**
	 * `GET /api/v1` with the store's secret key.
	 *
	 * @param string $prefix The path Monokulo is served under (`/monokulo`), or ''.
	 * @param bool   $onion  Whether the Monokulo has an onion address.
	 */
	public static function entry( string $prefix = '', bool $onion = false ): array {
		$entry = array(
			'_links'     => array(
				'self'         => array( 'href' => $prefix . '/api/v1' ),
				'mk:store'     => array( 'href' => $prefix . '/api/v1/store' ),
				'mk:orders'    => array( 'href' => $prefix . '/api/v1/orders' ),
				'mk:events'    => array( 'href' => $prefix . '/api/v1/events' ),
				'service-desc' => array( 'href' => $prefix . '/api/v1/openapi.json' ),
				'service-doc'  => array( 'href' => 'https://docs.monokulo.com/api/' ),
				'curies'       => self::curies(),
			),
			'_templates' => array(
				'create-order' => array(
					'method'      => 'POST',
					'target'      => $prefix . '/api/v1/orders',
					'contentType' => 'application/json',
				),
				'send-logs'    => array(
					'method'      => 'POST',
					'target'      => $prefix . '/api/v1/logs',
					'contentType' => 'application/json',
				),
			),
		);
		if ( $onion ) {
			// Onion links have no path: tor reaches Monokulo directly.
			$entry['_links']['mk:onion-api'] = array( 'href' => self::ONION . '/api/v1' );
		}
		return $entry;
	}

	/**
	 * The `201 Created` body of a new order, as the store's secret key gets it.
	 * A field with no value is left out, never `null`: an order not
	 * cancelled has no `cancelled_at`, and `amount`, `currency`,
	 * `merchant_order_id` and `description` are left out when it has none.
	 *
	 * @param string $order_id Its id.
	 * @param string $prefix   The path Monokulo is served under, or ''.
	 * @param bool   $onion    Whether the Monokulo has an onion address.
	 */
	public static function order( string $order_id, string $prefix = '', bool $onion = false ): array {
		$order = array(
			'order_id'                 => $order_id,
			'status'                   => 'pending',
			'is_final'                 => false,
			'address'                  => '84ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijk',
			'xmr_amount_piconero'      => 276000000000,
			'amount_received_piconero' => 0,
			'amount'                   => '42.5',
			'currency'                 => 'USD',
			'merchant_order_id'        => '1',
			'description'              => 'Order #1',
			'show_merchant_order_id'   => false,
			'confirmations'            => 0,
			'confirmations_required'   => 10,
			'created_at'               => 1760020320,
			'expires_at'               => 4102444800,
			'_links'                   => array(
				'self'               => array( 'href' => $prefix . '/api/v1/orders/' . $order_id ),
				'collection'         => array( 'href' => $prefix . '/api/v1/orders' ),
				'mk:order-events'    => array( 'href' => $prefix . '/api/v1/orders/' . $order_id . '/events' ),
				'mk:checkout'        => array( 'href' => $prefix . '/checkout/' . $order_id ),
				'mk:dashboard-order' => array( 'href' => $prefix . '/stores/store-1/orders/' . $order_id ),
				'curies'             => self::curies(),
			),
		);
		if ( $onion ) {
			$order['_links']['mk:onion-checkout'] = array( 'href' => self::ONION . '/checkout/' . $order_id );
		}
		return $order;
	}

	/**
	 * `POST /plugins/connect/woocommerce/finish`'s answer.
	 *
	 * @param string $prefix The path Monokulo is served under, or ''.
	 * @param bool   $onion  Whether the Monokulo has an onion address.
	 */
	public static function finish( string $prefix = '', bool $onion = false ): array {
		$finish = array(
			'public_key'             => 'pk_connected',
			'secret_token'           => 'sk_connected',
			'api'                    => $prefix . '/api/v1',
			'webhook_signing_secret' => 'whsec_connected',
		);
		if ( $onion ) {
			$finish['onion_api'] = self::ONION . '/api/v1';
		}
		return $finish;
	}

	/**
	 * A webhook body's `_links`: the order as the API links it, absolute
	 * on Monokulo's web address (`$monokulo`, with any path it is served
	 * under), and its onion checkout when it has an onion address.
	 *
	 * @param string $order_id The order's id.
	 * @param string $monokulo Monokulo's web address.
	 * @param bool   $onion    Whether the Monokulo has an onion address.
	 */
	public static function webhook_links( string $order_id, string $monokulo = 'https://pay.example.com', bool $onion = false ): array {
		$links = array(
			'curies'             => self::curies(),
			'mk:order'           => array( 'href' => $monokulo . '/api/v1/orders/' . $order_id ),
			'mk:dashboard-order' => array(
				'href' => $monokulo . '/stores/store-1/orders/' . $order_id,
				'type' => 'text/html',
			),
			'mk:checkout'        => array(
				'href' => $monokulo . '/checkout/' . $order_id,
				'type' => 'text/html',
			),
		);
		if ( $onion ) {
			$links['mk:onion-checkout'] = array(
				'href' => self::ONION . '/checkout/' . $order_id,
				'type' => 'text/html',
			);
		}
		return $links;
	}

	/**
	 * The `mk` curie every resource carries.
	 */
	private static function curies(): array {
		return array(
			array(
				'name'      => 'mk',
				'href'      => 'https://docs.monokulo.com/rels/{rel}',
				'templated' => true,
			),
		);
	}

	/**
	 * The problems `POST /api/v1/orders` answers with that the plugin tells
	 * apart, by name, as Monokulo's OpenAPI document records them (the
	 * smoke suite checks each against the recorded example).
	 */
	const ORDER_PROBLEMS = array(
		'invalid-amount' => array(
			'type' => 'https://docs.monokulo.com/problems/invalid-amount',
			'title' => 'The order can\'t be priced',
			'status' => 400,
			'detail' => 'unknown currency: NOPE',
		),
		'unauthorized' => array(
			'type' => 'https://docs.monokulo.com/problems/unauthorized',
			'title' => 'No valid key',
			'status' => 401,
			'detail' => 'This key was not accepted. Check it, or connect the store or app again.',
		),
		'forbidden' => array(
			'type' => 'https://docs.monokulo.com/problems/forbidden',
			'title' => 'Not allowed with this key',
			'status' => 403,
			'detail' => 'This store only accepts orders from its verified websites, or from its own server using the store\'s secret key.',
		),
		'idempotency-conflict' => array(
			'type' => 'https://docs.monokulo.com/problems/idempotency-conflict',
			'title' => 'Key used for another order',
			'status' => 409,
			'detail' => 'this idempotency_key was already used for a different order',
		),
		'rate-limited' => array(
			'type' => 'https://docs.monokulo.com/problems/rate-limited',
			'title' => 'Too many requests',
			'status' => 429,
			'detail' => 'Rate limit exceeded; retry in 93 s.',
			'retry_after' => 93,
		),
		'engine-unavailable' => array(
			'type' => 'https://docs.monokulo.com/problems/engine-unavailable',
			'title' => 'Payment engine unavailable',
			'status' => 503,
			'detail' => 'The payment engine is busy or unreachable. Try again in a moment.',
		),
		'rates-unavailable' => array(
			'type' => 'https://docs.monokulo.com/problems/rates-unavailable',
			'title' => 'No exchange rate right now',
			'status' => 503,
			'detail' => 'There\'s no exchange rate for USD right now: the rate providers are busy or unreachable. Try again in 42 s.',
			'retry_after' => 42,
		),
	);

	/**
	 * A problem `POST /api/v1/orders` answers with, as recorded.
	 *
	 * @param string $name Its name (`rates-unavailable`).
	 */
	public static function order_problem( string $name ): array {
		return self::ORDER_PROBLEMS[ $name ];
	}

	/**
	 * An `application/problem+json` body.
	 *
	 * @param int    $status Its status.
	 * @param string $name   The problem's name (`invalid-amount`).
	 * @param string $title  Its title.
	 * @param string $detail What went wrong this time.
	 */
	public static function problem( int $status, string $name, string $title, string $detail ): array {
		return array(
			'type'   => 'https://docs.monokulo.com/problems/' . $name,
			'title'  => $title,
			'status' => $status,
			'detail' => $detail,
		);
	}
}
