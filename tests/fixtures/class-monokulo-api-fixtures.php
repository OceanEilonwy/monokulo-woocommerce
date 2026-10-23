<?php
/**
 * Monokulo's API resources as the plugin's tests answer with them: the
 * entry the store's secret key reads, and a new order. The smoke suite
 * checks them against a real Monokulo's OpenAPI document, so they stay
 * what Monokulo sends.
 *
 * @package Monokulo
 */

declare(strict_types=1);

/**
 * The fixtures.
 */
final class Monokulo_Api_Fixtures {

	/**
	 * `GET /api/v1` with the store's secret key.
	 */
	public static function entry(): array {
		return array(
			'_links'     => array(
				'self'                => array( 'href' => '/api/v1' ),
				'mk:store'            => array( 'href' => '/api/v1/store' ),
				'mk:orders'           => array( 'href' => '/api/v1/orders' ),
				'mk:events'           => array( 'href' => '/api/v1/events' ),
				'service-desc'        => array( 'href' => '/api/v1/openapi.json' ),
				'curies'              => array(
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
					'target'      => '/api/v1/orders',
					'contentType' => 'application/json',
					'properties'  => array(
						array(
							'name'     => 'amount',
							'required' => true,
						),
						array(
							'name'     => 'currency',
							'required' => true,
						),
					),
				),
			),
		);
	}

	/**
	 * The `201 Created` body of a new order.
	 *
	 * @param string $order_id Its id.
	 */
	public static function order( string $order_id ): array {
		return array(
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
				'self'             => array( 'href' => '/api/v1/orders/' . $order_id ),
				'collection'       => array( 'href' => '/api/v1/orders' ),
				'mk:order-events'  => array( 'href' => '/api/v1/orders/' . $order_id . '/events' ),
				'mk:checkout'      => array( 'href' => '/checkout/' . $order_id ),
			),
		);
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
