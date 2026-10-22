<?php
/**
 * WordPress's PHPUnit test library config (tests/bootstrap.php loads the
 * library, which reads this): the WordPress in /var/www/html, with its own
 * tables in the `wordpress_tests` database, which the library empties and
 * installs afresh on every run. The shop's own database is left alone.
 */
define( 'ABSPATH', '/var/www/html/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', 'wordpress_tests' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', '127.0.0.1:' . ( getenv( 'DB_PORT' ) ? getenv( 'DB_PORT' ) : '33062' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'monokulo-tests' );
define( 'SECURE_AUTH_KEY', 'monokulo-tests' );
define( 'LOGGED_IN_KEY', 'monokulo-tests' );
define( 'NONCE_KEY', 'monokulo-tests' );
define( 'AUTH_SALT', 'monokulo-tests' );
define( 'SECURE_AUTH_SALT', 'monokulo-tests' );
define( 'LOGGED_IN_SALT', 'monokulo-tests' );
define( 'NONCE_SALT', 'monokulo-tests' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );
