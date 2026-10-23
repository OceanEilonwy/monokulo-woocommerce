<?php
/**
 * A stand-in for CoinGecko's price API, for the smoke test's Monokulo
 * (docker/monokulo-smoke*.toml), so making an order never waits on, or is
 * refused by, the real one. A router for PHP's built-in server, started
 * by scripts/smoke.
 *
 * - `/api/v3/simple/supported_vs_currencies`: USD and EUR.
 * - `/api/v3/simple/price`: 1 XMR is 150 USD. EUR is rate limited
 *   (`429`, `Retry-After: 120`), so an order in euros meets a real
 *   `rates-unavailable` from Monokulo.
 */

$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ('/api/v3/simple/supported_vs_currencies' === $path) {
	echo json_encode(['usd', 'eur']);
	return true;
}
if ('/api/v3/simple/price' !== $path) {
	http_response_code(404);
	echo '{}';
	return true;
}
$prices = [];
foreach (explode(',', strtolower((string) ($_GET['vs_currencies'] ?? ''))) as $currency) {
	if ('eur' === $currency) {
		http_response_code(429);
		header('Retry-After: 120');
		echo json_encode(['status' => ['error_code' => 429, 'error_message' => 'You\'ve exceeded the Rate Limit.']]);
		return true;
	}
	if ('' !== $currency) {
		$prices[$currency] = 150;
	}
}
echo json_encode(['monero' => $prices]);
return true;
