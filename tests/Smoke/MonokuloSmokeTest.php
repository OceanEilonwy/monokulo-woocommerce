<?php
namespace Monokulo\WooCommerce\Tests\Smoke;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/fixtures/class-monokulo-api-fixtures.php';

/**
 * The installed plugin against a real Monokulo (MONOKULO_URL, a fresh
 * instance scripts/smoke starts: at a host's root, or under a path behind
 * a proxy; MONOKULO_ONION is its onion address, or empty when it has
 * none), as a merchant and a customer use it, over HTTP:
 *
 * 1. the merchant signs up to Monokulo and adds a watch-only wallet;
 * 2. in WordPress, WooCommerce > Settings > Payments > Monokulo, types
 *    this Monokulo's address in place of www.monokulo.com, saves, and presses
 *    Connect;
 * 3. on Monokulo, sets up the store for the shop, picks the wallet, and
 *    goes back to WooCommerce, which redeems the token: connected;
 * 4. a guest finds Monero in the block checkout, and buys the shop's mug
 *    with it through WooCommerce's Store API (what the block checkout
 *    posts): the plugin makes the Monokulo order through the API and
 *    sends them to its checkout, which loads; the order, read with the
 *    store's public key, is pending; the shop's order links its page on
 *    the merchant's Monokulo dashboard, which loads for the merchant;
 * 5. a guest on the shop's onion address is sent to the onion checkout,
 *    or, with no onion address, told Monero can't be paid over Tor;
 * 6. the shop's webhook address answers, and refuses an unsigned delivery.
 *
 * And the unit tests' API fixtures are what this Monokulo's OpenAPI
 * document describes.
 *
 * Nothing is paid. Monokulo's pages are filled in by their fields' names.
 */
final class MonokuloSmokeTest extends TestCase {
	private const SHOP = 'http://localhost:8092/';
	/** A mainnet watch-only wallet's keys, from Monokulo's own tests. */
	private const VIEW_KEY = '0707070707070707070707070707070707070707070707070707070707070707';
	private const SPEND_PUBLIC_KEY = '8621f587cfc4d6f869720476565ecd0972451ff7b8dada3498c9d3c2ca54fc90';
	private const PASSWORD = 'correct horse battery staple';
	/** docker/wp-setup's admin. */
	private const ADMIN_USER = 'admin';
	private const ADMIN_PASSWORD = 'admin-password-1';

	private string $monokulo;
	private string $onion;
	private Browser $browser;

	protected function setUp(): void {
		$url = getenv('MONOKULO_URL');
		if (!$url) {
			$this->markTestSkipped('Set MONOKULO_URL to a fresh Monokulo (scripts/smoke starts one).');
		}
		$this->monokulo = rtrim($url, '/');
		$this->onion = (string)getenv('MONOKULO_ONION');
		$this->browser = new Browser();
	}

	public function test_connect_then_a_customer_is_sent_to_pay(): void {
		$this->signUpToMonokulo();
		$publicKey = $this->connectTheShop();
		$placed = $this->buyWithMonero(new Browser());
		$this->assertSame(200, $placed->status, $placed->body);
		$checkout = (string)$placed->json()['payment_result']['redirect_url'];
		$this->assertMatchesRegularExpression('#\A' . preg_quote($this->monokulo, '#') . '/checkout/([^/?]+)\z#', $checkout);
		$orderId = basename($checkout);

		// The Monokulo checkout loads, and the new order is waiting for payment.
		$page = $this->browser->get($checkout);
		$this->assertSame(200, $page->status, $page->text());
		$order = $this->browser->get($this->monokulo . '/api/v1/orders/' . rawurlencode($orderId), ['Authorization' => 'Bearer ' . $publicKey]);
		$this->assertSame(200, $order->status, $order->body);
		$this->assertSame('pending', $order->json()['status']);
		$this->assertSame(0, $order->json()['confirmations']);
		$this->assertSame(parse_url($this->monokulo, PHP_URL_PATH) . '/checkout/' . $orderId, $order->json()['_links']['mk:checkout']['href']);

		// The shop's order links its page on the merchant's dashboard, which
		// loads for the merchant, signed in to Monokulo.
		$shopOrder = $this->browser->get(self::SHOP . 'wp-admin/admin.php?page=wc-orders&action=edit&id=' . $placed->json()['order_id']);
		$this->assertSame(200, $shopOrder->status);
		$this->assertMatchesRegularExpression('#In Monokulo: (' . preg_quote($this->monokulo, '#') . '/stores/[^/]+/orders/' . preg_quote($orderId, '#') . ')#', $shopOrder->text());
		preg_match('#In Monokulo: (' . preg_quote($this->monokulo, '#') . '/stores/[^/]+/orders/' . preg_quote($orderId, '#') . ')#', $shopOrder->text(), $m);
		$dashboard = $this->browser->get($m[1]);
		$this->assertSame(200, $dashboard->status, $dashboard->text());
		$this->assertStringContainsString($orderId, $dashboard->body);

		// A customer on the shop's onion address stays on Tor.
		$onTor = $this->buyWithMonero(new Browser(['Host' => 'shop2gzyxa5ihm7nsggfxnu52rck2vv4rvmdlkiu3zzui5du4xyclen53wid.onion']), false);
		if ('' !== $this->onion) {
			$this->assertSame(200, $onTor->status, $onTor->body);
			$this->assertMatchesRegularExpression('#\A' . preg_quote($this->onion, '#') . '/checkout/[^/?]+\z#', (string)$onTor->json()['payment_result']['redirect_url']);
		} else {
			$this->assertSame(400, $onTor->status, $onTor->body);
			$this->assertStringContainsString('cannot be made over Tor', $onTor->body);
		}

		// Plain permalinks: the REST API is ?rest_route=.
		$webhook = $this->browser->post(self::SHOP . '?rest_route=/monokulo/v1/webhook', '{"event":"order.paid"}', ['Content-Type' => 'application/json']);
		$this->assertSame(401, $webhook->status, $webhook->body);
	}

	/**
	 * The unit tests answer with Monokulo_Api_Fixtures: each of their
	 * fields, links and forms is one this Monokulo's OpenAPI document
	 * (linked from the entry as `service-desc`) has, and the pages and
	 * problems the plugin relies on are there.
	 */
	public function test_the_api_fixtures_are_what_monokulo_describes(): void {
		$entry = $this->browser->get($this->monokulo . '/api/v1');
		$this->assertSame(200, $entry->status, $entry->body);
		$href = $entry->json()['_links']['service-desc']['href'] ?? null;
		$this->assertIsString($href, 'the entry links its OpenAPI document');
		// Under a path, the link has it: it is resolved against the host.
		$openapi = $this->browser->get(preg_replace('#\A(https?://[^/]+).*\z#', '$1', $this->monokulo) . $href);
		$this->assertSame(200, $openapi->status);
		$doc = $openapi->json();

		$entryPath = $doc['paths']['/api/v1']['get'] ?? null;
		$this->assertIsArray($entryPath, 'GET /api/v1');
		$this->assertMatchesFixture($doc, self::okSchema($doc, $entryPath, '200'), \Monokulo_Api_Fixtures::entry('', true), 'entry');
		foreach (['Cache-Control', 'ETag', 'Vary'] as $header) {
			$this->assertArrayHasKey($header, $entryPath['responses']['200']['headers'] ?? [], "the entry is kept by its $header");
		}

		$create = $doc['paths']['/api/v1/orders']['post'] ?? null;
		$this->assertIsArray($create, 'POST /api/v1/orders');
		$this->assertMatchesFixture($doc, self::okSchema($doc, $create, '201'), \Monokulo_Api_Fixtures::order('order_1', '', true), 'order');
		// What the plugin sends to create-order is what it takes.
		$takes = self::deref($doc, reset($create['requestBody']['content'])['schema'])['properties'] ?? [];
		foreach (['amount', 'currency', 'merchant_order_id', 'show_merchant_order_id', 'description', 'idempotency_key'] as $field) {
			$this->assertArrayHasKey($field, $takes, "create-order takes $field");
		}
		$logs = self::deref($doc, reset($doc['paths']['/api/v1/logs']['post']['requestBody']['content'])['schema']);
		$this->assertArrayHasKey('entries', $logs['properties'] ?? [], 'send-logs takes entries');

		// The pages the plugin sends people to.
		foreach (['/checkout/{order_id}' => 'mk:checkout', '/stores/{id}/orders/{order_id}' => 'mk:dashboard-order'] as $path => $rel) {
			$this->assertArrayHasKey('text/html', $doc['paths'][$path]['get']['responses']['200']['content'] ?? [], "$rel is a page");
		}

		// The handshake: what the plugin keeps, and the problems it tells apart.
		$finish = $doc['paths']['/plugins/connect/{slug}/finish']['post'] ?? null;
		$this->assertIsArray($finish, 'POST /plugins/connect/{slug}/finish');
		$this->assertMatchesFixture($doc, self::okSchema($doc, $finish, '200'), \Monokulo_Api_Fixtures::finish('', true), 'finish');
		foreach (['400', '401', '404', '413', '415', '429', '500', '503'] as $status) {
			$this->assertArrayHasKey('application/problem+json', $finish['responses'][$status]['content'] ?? [], "/finish answers $status as a problem");
		}
	}

	/**
	 * The fixtures are the exchanges recorded in the OpenAPI document
	 * written at the pinned commit (MONOKULO_OPENAPI, from `cargo xtask api
	 * openapi`; the one Monokulo serves has no examples): the entry and a
	 * new order as the store's secret key sees them on a Monokulo with an
	 * onion address, with the same links and forms, and the handshake's
	 * problems.
	 */
	public function test_the_api_fixtures_are_what_monokulo_answers(): void {
		$path = (string)getenv('MONOKULO_OPENAPI');
		$this->assertNotSame('', $path, 'Set MONOKULO_OPENAPI to the OpenAPI document written at the pinned commit (scripts/smoke says how).');
		$doc = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($doc, $path);

		$entry = self::example($doc, '/api/v1', 'get', '200', 'sk');
		$fixture = \Monokulo_Api_Fixtures::entry('', true);
		$this->assertEqualsCanonicalizing(array_keys($entry['_links']), array_keys($fixture['_links']), 'the entry\'s links');
		$this->assertSame(self::forms($entry), self::forms($fixture), 'the entry\'s forms');
		$this->assertSame($entry['_links']['self']['href'], $fixture['_links']['self']['href']);
		$this->assertSame($fixture['_links']['mk:onion-api']['href'], $entry['_links']['mk:onion-api']['href'], 'the onion entry: absolute, with no path');

		$order = self::example($doc, '/api/v1/orders', 'post', '201', 'sk');
		$fixture = \Monokulo_Api_Fixtures::order($order['order_id'], '', true);
		$this->assertEqualsCanonicalizing(array_keys($order), array_keys($fixture), 'the order\'s fields');
		$this->assertEqualsCanonicalizing(array_keys($order['_links']), array_keys($fixture['_links']), 'the order\'s links');
		foreach (['self', 'mk:checkout', 'mk:onion-checkout', 'mk:order-events', 'collection'] as $rel) {
			$this->assertSame($order['_links'][$rel]['href'], $fixture['_links'][$rel]['href'], $rel);
		}
		$this->assertMatchesRegularExpression('#\A/stores/[^/]+/orders/' . preg_quote($order['order_id'], '#') . '\z#', $order['_links']['mk:dashboard-order']['href']);

		// Only the secret key is given the dashboard page.
		$this->assertArrayNotHasKey('mk:dashboard-order', self::example($doc, '/api/v1/orders', 'post', '201', 'pk')['_links']);

		$finish = $doc['paths']['/plugins/connect/{slug}/finish']['post']['responses'];
		foreach (['400' => 'invalid-request', '401' => 'invalid-connect-code', '404' => 'not-found', '415' => 'unsupported-media-type', '503' => 'no-public-address'] as $status => $name) {
			$problem = $finish[$status]['content']['application/problem+json']['examples'][$name]['value'] ?? null;
			$this->assertIsArray($problem, "/finish's $status example");
			$this->assertStringEndsWith('/' . $name, $problem['type'], "/finish's $status example");
			$this->assertIsString($problem['detail'] ?? null, 'a detail to show');
		}
	}

	/** A recorded example's body. */
	private static function example(array $doc, string $path, string $method, string $status, string $name): array {
		$content = $doc['paths'][$path][$method]['responses'][$status]['content'] ?? [];
		$example = reset($content)['examples'][$name]['value'] ?? null;
		self::assertIsArray($example, "$method $path $status, example $name");
		return $example;
	}

	/** @return array<string, string> each form's method and target, by name */
	private static function forms(array $resource): array {
		$forms = [];
		foreach ($resource['_templates'] ?? [] as $name => $form) {
			$forms[$name] = $form['method'] . ' ' . $form['target'];
		}
		ksort($forms);
		return $forms;
	}

	/** The schema of an operation's `$status` answer, references followed. */
	private static function okSchema(array $doc, array $operation, string $status): array {
		$content = $operation['responses'][$status]['content'] ?? [];
		$schema = reset($content)['schema'] ?? null;
		self::assertIsArray($schema, "a $status answer: " . json_encode(array_keys($operation['responses'] ?? [])));
		return self::deref($doc, $schema);
	}

	private static function deref(array $doc, array $schema): array {
		while (isset($schema['$ref'])) {
			$path = explode('/', substr($schema['$ref'], 2));
			$schema = $doc;
			foreach ($path as $part) {
				$schema = $schema[str_replace(['~1', '~0'], ['/', '~'], $part)];
			}
		}
		foreach (['allOf', 'anyOf', 'oneOf'] as $combined) {
			foreach ($schema[$combined] ?? [] as $part) {
				$part = self::deref($doc, $part);
				$schema['properties'] = ($schema['properties'] ?? []) + ($part['properties'] ?? []);
				$schema['required'] = array_merge($schema['required'] ?? [], $part['required'] ?? []);
			}
		}
		return $schema;
	}

	/**
	 * Every field, link and form of `$fixture` is in `$schema`, and every
	 * field `$schema` requires is in `$fixture`.
	 */
	private function assertMatchesFixture(array $doc, array $schema, array $fixture, string $what): void {
		$properties = $schema['properties'] ?? [];
		foreach (array_keys($fixture) as $field) {
			$this->assertArrayHasKey($field, $properties, "$what: $field is described");
		}
		foreach ($schema['required'] ?? [] as $field) {
			$this->assertArrayHasKey($field, $fixture, "$what: the fixture has $field");
		}
		$links = isset($properties['_links']) ? self::deref($doc, $properties['_links'])['properties'] ?? [] : [];
		foreach (array_keys($fixture['_links'] ?? []) as $rel) {
			$this->assertArrayHasKey($rel, $links, "$what: link $rel is described");
		}
		// A form is the operation named for it (`x-hal-forms-template`).
		foreach ($fixture['_templates'] ?? [] as $name => $form) {
			$operation = $doc['paths'][$form['target']][strtolower($form['method'])] ?? [];
			$this->assertSame($name, $operation['x-hal-forms-template'] ?? null, "$what: form $name is {$form['method']} {$form['target']}");
		}
	}

	/** A fresh Monokulo asks for its operator first; that account is the merchant's too. */
	private function signUpToMonokulo(): void {
		$home = $this->browser->get($this->monokulo . '/');
		$email = 'merchant-' . bin2hex(random_bytes(4)) . '@example.test';
		if (str_ends_with((string)$home->location(), '/admin/setup')) {
			$page = $this->browser->get($this->monokulo . '/admin/setup');
			$done = $this->browser->submit($page, '/admin/setup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		} else {
			$page = $this->browser->get($this->monokulo . '/signup');
			$done = $this->browser->submit($page, '/signup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		}
		$this->assertContains($done->status, [302, 303], 'signed up: ' . $done->text());

		$form = $this->browser->get($this->monokulo . '/account/wallets/import?network=mainnet');
		$added = $this->browser->submit($form, '/account/wallets/import', [
			'name'             => 'Shop takings',
			'view_key_hex'     => self::VIEW_KEY,
			'spend_pubkey_hex' => self::SPEND_PUBLIC_KEY,
			'network'          => 'mainnet',
		]);
		$this->assertSame(303, $added->status, 'wallet added: ' . $added->text());
	}

	/** Connects the shop from its Monokulo settings; returns the store's public key. */
	private function connectTheShop(): string {
		$this->signInToWordPress();
		$settingsUrl = self::SHOP . 'wp-admin/admin.php?page=wc-settings&tab=checkout&section=monokulo';

		// The Monokulo address starts at the public Monokulo; this shop's is the one on this machine.
		$settings = $this->browser->get($settingsUrl);
		$this->assertSame(200, $settings->status, $settings->text());
		$this->assertSame('https://www.monokulo.com', $settings->form('')['fields']['woocommerce_monokulo_address'] ?? null);
		// The settings form posts back to its own page (action=""), query string and all.
		$this->browser->post($settingsUrl, ['woocommerce_monokulo_address' => $this->monokulo . '/', 'save' => 'Save changes'] + $settings->form('')['fields']);

		// Connect: off to Monokulo, signed in already, with no store for the shop yet: store setup.
		$settings = $this->browser->get($settingsUrl);
		$this->assertSame(200, $settings->status, $settings->text());
		$this->assertStringContainsString('Connects to <code>' . $this->monokulo . '</code>', preg_replace('/\s+/', ' ', $settings->body));
		$this->assertMatchesRegularExpression('/<a href="([^"]+)" id="woocommerce_monokulo_connect"/', $settings->body);
		preg_match('/<a href="([^"]+)" id="woocommerce_monokulo_connect"/', $settings->body, $m);
		$connect = html_entity_decode($m[1]);
		$this->assertStringStartsWith($this->monokulo . '/plugins/connect/woocommerce?', $connect);

		$setup = $this->browser->follow($this->browser->get($connect));
		$this->assertStringContainsString('WooCommerce', $setup->text(), 'Monokulo names the plugin');
		$wallet = $this->browser->follow($this->browser->submit($setup, '/setup', ['store_name' => 'Smoke test shop']));

		$done = $this->browser->follow($this->browser->submit($wallet, '/setup/wallet/existing'));
		$this->assertStringContainsString('Back to WooCommerce', $done->text());

		// Back to WooCommerce: Monokulo returns to the shop with the token, the
		// plugin redeems it and sends the browser to its settings.
		$back = $this->browser->submit($done, '/plugins/connect/woocommerce');
		$this->assertStringStartsWith(self::SHOP . 'wp-admin/admin-post.php?action=monokulo_connect_return', (string)$back->location(), $back->text());
		$return = $this->browser->get((string)$back->location());
		$this->assertStringContainsString('monokulo_connected=1', (string)$return->location(), $return->status . preg_replace("#<style.*</style>#s", "", $return->body));

		$settings = $this->browser->get((string)$return->location());
		$this->assertMatchesRegularExpression('/Connected as\s*<code>(pk_[^<]+)<\/code>/', $settings->body);
		if ('' !== $this->onion) {
			$this->assertStringContainsString('<code>' . parse_url($this->onion, PHP_URL_HOST) . '</code>', $settings->body, 'the onion address the handshake gave');
		} else {
			$this->assertStringNotContainsString('.onion', $settings->body);
		}
		preg_match('/Connected as\s*<code>(pk_[^<]+)<\/code>/', $settings->body, $m);

		return $m[1];
	}

	private function signInToWordPress(): void {
		// WordPress refuses a sign-in from a browser that hasn't its test cookie yet.
		$this->browser->get(self::SHOP . 'wp-login.php');
		$signedIn = $this->browser->post(self::SHOP . 'wp-login.php', [
			'log'         => self::ADMIN_USER,
			'pwd'         => self::ADMIN_PASSWORD,
			'wp-submit'   => 'Log In',
			'testcookie'  => '1',
			'redirect_to' => self::SHOP . 'wp-admin/',
		]);
		$this->assertSame(302, $signedIn->status, 'signed in to WordPress: ' . $signedIn->text());
		$this->assertStringContainsString('wp-admin', (string)$signedIn->location());
	}

	/**
	 * A guest checkout with Monero through the Store API, in `$customer`;
	 * returns the Store API's answer to placing the order. The block
	 * checkout page is checked on the shop's own address only (WordPress
	 * sends pages on another host to its own; the Store API answers there).
	 */
	private function buyWithMonero(Browser $customer, bool $checkoutPage = true): Page {
		// Plain permalinks: the REST API is ?rest_route=.
		$api = self::SHOP . '?rest_route=/wc/store/v1/';

		$cart = $customer->get($api . 'cart');
		$this->assertSame(200, $cart->status, $cart->body);
		$nonce = (string)$cart->header('Nonce');
		$this->assertNotSame('', $nonce, 'the Store API hands out a nonce');
		$json = ['Content-Type' => 'application/json', 'Nonce' => $nonce];

		$products = $customer->get($api . 'products&search=mug')->json();
		$this->assertNotEmpty($products, 'the shop has its mug');
		$added = $customer->post($api . 'cart/add-item', (string)json_encode(['id' => $products[0]['id'], 'quantity' => 1]), $json);
		$this->assertSame(201, $added->status, $added->body);

		$cart = $customer->get($api . 'cart')->json();
		$methods = $cart['payment_methods'] ?? [];
		$this->assertContains('monokulo', $methods, 'Monero is offered: ' . json_encode($methods));

		// The block checkout loads the payment method's script and settings.
		if ($checkoutPage) {
			$checkout = $customer->get(self::SHOP . '?pagename=checkout');
			$this->assertSame(200, $checkout->status, $checkout->text());
			$this->assertStringContainsString('wp-block-woocommerce-checkout', $checkout->body, 'the block checkout');
			$this->assertStringContainsString('/monokulo/assets/blocks.js', $checkout->body);
			$this->assertStringContainsString('"monokulo":{"title":', rawurldecode($checkout->body), 'its title, description and icon');
		}

		$address = [
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'address_1'  => '12 Analytical Row',
			'city'       => 'San Francisco',
			'state'      => 'CA',
			'postcode'   => '94103',
			'country'    => 'US',
			'email'      => 'ada@example.test',
		];
		$placed = $customer->post($api . 'checkout', (string)json_encode([
			'billing_address'  => $address,
			'shipping_address' => array_diff_key($address, ['email' => true]),
			'payment_method'   => 'monokulo',
		]), $json);
		if (200 === $placed->status) {
			$result = $placed->json();
			$this->assertSame('success', $result['payment_result']['payment_status'] ?? null, $placed->body);
			$this->assertSame('pending', $result['status'], 'placed as Pending payment');
		}

		return $placed;
	}
}
