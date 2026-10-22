<?php
/**
 * The version is written in three places that must agree: VERSION (the
 * release tag), the plugin header WordPress reads, and the
 * `Monokulo-Client` header the plugin names itself with.
 *
 * @package Monokulo
 */
class PackageTest extends WP_UnitTestCase {

	public function test_the_version_is_the_same_everywhere() {
		$root    = dirname( __DIR__ );
		$version = trim( (string) file_get_contents( $root . '/VERSION' ) );
		$this->assertMatchesRegularExpression( '/\A\d+\.\d+\.\d+\z/', $version );

		$header = get_file_data( $root . '/monokulo.php', array( 'Version' => 'Version' ) );
		$this->assertSame( $version, $header['Version'], 'monokulo.php header' );
		$this->assertSame( 'woocommerce/' . $version, WC_Gateway_Monokulo::CLIENT, 'WC_Gateway_Monokulo::CLIENT' );
	}
}
