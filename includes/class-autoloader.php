<?php
/**
 * PSR-0-style autoloader for WCCG_ classes.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers an SPL autoload handler that maps WCCG_ class names to plugin file paths.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Autoloader {

    /**
     * Register the autoload callback.
     *
     * @since 1.0.0
     */
    public function __construct() {
        spl_autoload_register(array($this, 'autoload'));
    }

    /**
     * Load the file for a WCCG_ class.
     *
     * @since  1.0.0
     * @param  string $class_name The fully-qualified class name to load.
     * @return void
     */
    public function autoload($class_name) {
        if (strpos($class_name, 'WCCG_') !== 0) {
            return;
        }

        $file = $this->get_file_path_from_class($class_name);

        if (file_exists($file)) {
            require_once $file;
        }
    }

    /**
     * Convert a WCCG_ class name to its expected filename.
     *
     * @since  1.0.0
     * @param  string $class_name The class name to convert.
     * @return string The expected filename (e.g. class-admin-pricing-rules.php).
     */
    private function get_file_name_from_class($class_name) {
        return 'class-' . str_replace('_', '-',
            strtolower(
                substr($class_name, 5) // Remove 'WCCG_' prefix.
            )
        ) . '.php';
    }

    /**
     * Resolve the absolute file path for a WCCG_ class.
     *
     * @since  1.0.0
     * @param  string $class_name The class name to resolve.
     * @return string Absolute path to the class file.
     */
    private function get_file_path_from_class($class_name) {
        $file_name = $this->get_file_name_from_class($class_name);

        $directories = array(
            'admin'    => WCCG_PATH . 'admin/',
            'public'   => WCCG_PATH . 'public/',
            'includes' => WCCG_PATH . 'includes/'
        );

        if (strpos($class_name, 'WCCG_Admin_') === 0 || $class_name === 'WCCG_Admin') {
            return $directories['admin'] . $file_name;
        }

        if (strpos($class_name, 'WCCG_Public') === 0) {
            return $directories['public'] . $file_name;
        }

        return $directories['includes'] . $file_name;
    }
}
