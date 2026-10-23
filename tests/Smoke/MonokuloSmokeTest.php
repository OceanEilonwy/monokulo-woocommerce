<?php
namespace Monokulo\WooCommerce\Tests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * The installed plugin against a real Monokulo (MONOKULO_URL, a fresh
 * instance started with docker/monokulo-smoke.toml), as a merchant and a
 * customer use it, over HTTP:
 *
 * 1. the merchant signs up to Monokulo and adds a watch-only wallet;
 * 2. in WordPress, WooCommerce > Settings > Payments > Monokulo, types
 *    this Monokulo's address in place of www.monokulo.com, saves, and presses
 *    Connect;
 * 3. on Monokulo, sets up the store for the shop, picks the wallet, and
 *    goes back to WooCommerce, which redeems the token: connected;
 * 4. a guest finds Monero in the block checkout, and buys the shop's mug
 *    with it through WooCommerce's Store API (what the block checkout
 *    posts): the plugin creates the Monokulo order and sends them to its
 *    checkout, which loads and says pending;
 * 5. the shop's webhook address answers, and refuses an unsigned delivery.
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
	private Browser $browser;

	protected function setUp(): void {
		$url = getenv('MONOKULO_URL');
		if (!$url) {
			$this->markTestSkipped('Set MONOKULO_URL to a fresh Monokulo (scripts/smoke starts one).');
		}
		$this->monokulo = rtrim($url, '/');
		$this->browser = new Browser();
	}

	public function test_connect_then_a_customer_is_sent_to_pay(): void {
		$this->signUpToMonokulo();
		$publicKey = $this->connectTheShop();
		$checkout = $this->buyWithMonero();
		$this->assertStringStartsWith($this->monokulo . '/pay/' . $publicKey . '/orders/', $checkout);

		// The Monokulo checkout loads, and the new order is waiting for payment.
		$page = $this->browser->get($checkout);
		$this->assertSame(200, $page->status, $page->text());
		$status = $this->browser->get($checkout . '/status');
		$this->assertSame(200, $status->status);
		$this->assertSame('pending', $status->json()['status']);
		$this->assertSame(0, $status->json()['confirmations']);

		// Plain permalinks: the REST API is ?rest_route=.
		$webhook = $this->browser->post(self::SHOP . '?rest_route=/monokulo/v1/webhook', '{"event":"order.paid"}', ['Content-Type' => 'application/json']);
		$this->assertSame(401, $webhook->status, $webhook->body);
	}

	/** A fresh Monokulo asks for its operator first; that account is the merchant's too. */
	private function signUpToMonokulo(): void {
		$home = $this->browser->get($this->monokulo . '/');
		$email = 'merchant-' . bin2hex(random_bytes(4)) . '@example.test';
		if (str_ends_with((string)$home->location(), '/admin/setup')) {
			$page = $this->browser->get($this->monokulo . '/admin/setup');
			$done = $this->browser->submit($page, '/admin/setup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		} else {
			$page = $this->browser->get($this->monokulo . '/dashboard/signup');
			$done = $this->browser->submit($page, '/dashboard/signup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
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
	 * A guest checkout with Monero through the Store API, in a browser of
	 * its own; returns the Monokulo checkout it was sent to.
	 */
	private function buyWithMonero(): string {
		$customer = new Browser();
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
		$checkout = $customer->get(self::SHOP . '?pagename=checkout');
		$this->assertSame(200, $checkout->status, $checkout->text());
		$this->assertStringContainsString('wp-block-woocommerce-checkout', $checkout->body, 'the block checkout');
		$this->assertStringContainsString('/monokulo/assets/blocks.js', $checkout->body);
		$this->assertStringContainsString('"monokulo":{"title":', rawurldecode($checkout->body), 'its title, description and icon');

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
		$this->assertSame(200, $placed->status, $placed->body);
		$result = $placed->json();
		$this->assertSame('success', $result['payment_result']['payment_status'] ?? null, $placed->body);
		$this->assertSame('pending', $result['status'], 'placed as Pending payment');

		return (string)$result['payment_result']['redirect_url'];
	}
}
