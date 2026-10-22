<?php
namespace Monokulo\WooCommerce\Tests\Smoke;

/** One HTTP response, with what a test reads from it. */
final class Page {
	public function __construct(
		public readonly string $url,
		public readonly int $status,
		public readonly string $headers,
		public readonly string $body,
	) {
	}

	/** Where a redirect goes, made absolute, or null. */
	public function location(): ?string {
		if (!preg_match('/^location:\s*(.+?)\r?$/mi', $this->headers, $m)) {
			return null;
		}

		return self::absolute(trim($m[1]), $this->url);
	}

	/** A response header's value, or null. */
	public function header(string $name): ?string {
		if (!preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\r?$/mi', $this->headers, $m)) {
			return null;
		}

		return trim($m[1]);
	}

	/** @return array<string, mixed> */
	public function json(): array {
		$data = json_decode($this->body, true);
		if (!is_array($data)) {
			throw new \RuntimeException('not JSON from ' . $this->url . ' (' . $this->status . '): ' . substr($this->body, 0, 500));
		}

		return $data;
	}

	/**
	 * The form whose action contains `action`: its absolute action and its
	 * fields by name (inputs with a value, checked boxes, each select's
	 * selected or first option, textareas).
	 *
	 * @return array{action: string, fields: array<string, string>}
	 */
	public function form(string $action): array {
		$dom = new \DOMDocument();
		@$dom->loadHTML('<?xml encoding="UTF-8">' . $this->body);
		foreach ($dom->getElementsByTagName('form') as $form) {
			$target = html_entity_decode($form->getAttribute('action'), ENT_QUOTES);
			if (!str_contains($target, $action)) {
				continue;
			}
			$fields = [];
			$xpath = new \DOMXPath($dom);
			foreach ($xpath->query('.//input[@name]', $form) as $input) {
				$type = strtolower($input->getAttribute('type'));
				if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) {
					continue;
				}
				if (in_array($type, ['submit', 'button', 'file'], true)) {
					continue;
				}
				$fields[$input->getAttribute('name')] = $input->getAttribute('value');
			}
			foreach ($xpath->query('.//select[@name]', $form) as $select) {
				$chosen = $xpath->query('.//option[@selected]', $select)->item(0) ?? $xpath->query('.//option', $select)->item(0);
				$fields[$select->getAttribute('name')] = $chosen ? $chosen->getAttribute('value') : '';
			}
			foreach ($xpath->query('.//textarea[@name]', $form) as $textarea) {
				$fields[$textarea->getAttribute('name')] = $textarea->textContent;
			}

			return ['action' => self::absolute($target, $this->url), 'fields' => $fields];
		}

		throw new \RuntimeException('no form with action containing "' . $action . '" on ' . $this->url . ' (' . $this->status . '): ' . substr(strip_tags($this->body), 0, 800));
	}

	/** The page's text, tags removed and space collapsed. */
	public function text(): string {
		return trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->body), ENT_QUOTES)));
	}

	private static function absolute(string $url, string $base): string {
		if (preg_match('#\A[a-z][a-z0-9+.-]*://#i', $url)) {
			return $url;
		}
		$parts = parse_url($base);
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (str_starts_with($url, '/')) {
			return $origin . $url;
		}
		$dir = isset($parts['path']) ? preg_replace('#/[^/]*\z#', '/', $parts['path']) : '/';

		return $origin . $dir . $url;
	}
}
