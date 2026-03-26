<?php
/**
 * PHPUnit bootstrap for plugin tests.
 *
 * @package Alynt_Customer_Groups
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}

define( 'WCCG_TESTS_PATH', dirname( __DIR__ ) );
define( 'WCCG_VERSION', '1.2.0' );
define( 'WCCG_FILE', WCCG_TESTS_PATH . '/alynt-customer-groups.php' );
define( 'WCCG_PATH', WCCG_TESTS_PATH . '/' );
define( 'WCCG_URL', 'http://example.test/wp-content/plugins/alynt-customer-groups/' );
define( 'WCCG_BASENAME', 'alynt-customer-groups/alynt-customer-groups.php' );

require_once WCCG_TESTS_PATH . '/vendor/autoload.php';
require_once WCCG_TESTS_PATH . '/includes/class-wccg-autoloader.php';
