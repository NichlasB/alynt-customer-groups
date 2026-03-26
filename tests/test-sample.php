<?php
/**
 * Sample PHPUnit coverage for plugin bootstrap constants.
 *
 * @package Alynt_Customer_Groups
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;

class Test_Sample extends TestCase {
    public function test_plugin_bootstrap_constants_are_defined() {
        $this->assertSame( '1.1.0', WCCG_VERSION );
        $this->assertTrue( class_exists( 'WCCG_Autoloader' ) );
    }
}
