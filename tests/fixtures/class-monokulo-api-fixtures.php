<?php
/**
 * Monokulo's API resources as the plugin's tests answer with them: the
 * entry the store's secret key reads, a new order, and the connect
 * handshake's answer. Each comes as a Monokulo served at its host's root
 * or under a path (`$prefix`, `/monokulo`), with or without an onion
 * address. The smoke suite checks them against a real Monokulo's OpenAPI
 * document and its recorded examples, so they stay what Monokulo sends.
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
				'curies'       => array(
					array(
						'name'      => 'mk',
						'href'      => 'https://docs.monokulo.com/rels/{rel}',
						'templated' => true,
					),
				),
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
