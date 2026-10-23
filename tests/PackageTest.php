<?php
/**
 * The version and requirements, written in several places, agree.
 *
 * @package Monokulo
 */

declare(strict_types=1);

class PackageTest extends WP_UnitTestCase {

	public function test_the_version_is_the_same_everywhere() {
		$root    = dirname( __DIR__ );
		$version = trim( (string) file_get_contents( $root . '/VERSION' ) );
		$this->assertMatchesRegularExpression( '/\A\d+\.\d+\.\d+\z/', $version );

		$header = get_file_data( $root . '/monokulo.php', array( 'Version' => 'Version' ) );
		$this->assertSame( $version, $header['Version'], 'monokulo.php header' );
		$this->assertSame( $version, WC_Gateway_Monokulo::VERSION );
		$this->assertSame( 'woocommerce/' . $version, WC_Gateway_Monokulo::CLIENT );
	}

	public function test_the_requirements_are_the_same_everywhere() {
		$root   = dirname( __DIR__ );
		$header = get_file_data(
			$root . '/monokulo.php',
			array(
				'php' => 'Requires PHP',
				'wp'  => 'Requires at least',
				'wc'  => 'WC requires at least',
			)
		);
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
		$this->assertSame( '>=' . $header['php'], $composer['require']['php'] );
		$this->assertSame( $header['php'] . '.0', $composer['config']['platform']['php'] );

		$readme = (string) file_get_contents( $root . '/README.md' );
		$this->assertStringContainsString( 'WordPress **' . $header['wp'] . '** or newer', $readme );
		$this->assertStringContainsString( 'WooCommerce **' . $header['wc'] . '** or newer', $readme );
		$this->assertStringContainsString( 'PHP **' . $header['php'] . '** or', $readme );
	}
}
