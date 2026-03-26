<?php
/**
 * Plugin lifecycle bootstrapping.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers WordPress hooks and initializes plugin components after dependency checks.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Plugin_Bootstrap {
    private static $instance = null;
    private $plugin;
    private $dependencies;

    /**
     * Return the singleton instance of this class.
     *
     * @since  1.0.0
     * @return WCCG_Plugin_Bootstrap
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->dependencies = WCCG_Plugin_Dependencies::instance();
    }

    /**
     * Attach the main plugin instance and register all core WordPress hooks.
     *
     * @since  1.0.0
     * @param  WCCG_Customer_Groups $plugin The main plugin instance.
     * @return void
     */
    public function register($plugin) {
        $this->plugin = $plugin;

        /**
         * Fires once all active plugins are loaded so dependency checks can run before startup.
         *
         * @since 1.0.0
         */
        add_action('plugins_loaded', array($this, 'check_dependencies'), 1);

        /**
         * Fires once all active plugins are loaded so the plugin can bootstrap after dependencies.
         *
         * @since 1.0.0
         */
        add_action('plugins_loaded', array($this, 'init_plugin'), 20);

        /**
         * Fires after WordPress has finished loading but before headers are sent.
         *
         * @since 1.0.0
         */
        add_action('plugins_loaded', array($this, 'load_textdomain'), 5);

        /**
         * Filters the available cron schedules so the plugin can register its custom interval.
         *
         * @since 1.1.0
         *
         * @param array $schedules Existing cron schedule definitions.
         */
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));

        /**
         * Fires after WordPress has finished loading but before headers are sent.
         *
         * @since 1.0.0
         */
        add_action('init', array($this, 'init'));

        /**
         * Fires when the plugin's daily cleanup cron event runs.
         *
         * @since 1.0.0
         */
        add_action('wccg_cleanup_cron', array($this, 'run_cleanup_tasks'));
    }

    /**
     * Check plugin dependencies and queue admin notices on failure.
     *
     * @since  1.0.0
     * @return bool True if all dependencies pass, false otherwise.
     */
    public function check_dependencies() {
        return $this->dependencies->check_dependencies($this->plugin);
    }

    /**
     * Load the plugin text domain for translations.
     *
     * @since  1.0.0
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'alynt-customer-groups',
            false,
            dirname(WCCG_BASENAME) . '/languages/'
        );
    }

    /**
     * Initialize plugin components after dependency checks pass.
     *
     * Loads the autoloader then instantiates core, admin, or public components
     * depending on the current context.
     *
     * @since  1.0.0
     * @return void
     */
    public function init_plugin() {
        if (!$this->check_dependencies()) {
            return;
        }

        require_once WCCG_PATH . 'includes/class-autoloader.php';
        new WCCG_Autoloader();

        if (!class_exists('WCCG_Admin')) {
            error_log('WCCG Autoloader failed to load WCCG_Admin class');
            return;
        }

        $this->init_core_components();

        if (is_admin()) {
            $this->init_admin();
        } else {
            $this->init_public();
        }
    }

    private function init_core_components() {
        WCCG_Core::instance();
        WCCG_Database::instance();
        WCCG_Utilities::instance();
    }

    private function init_admin() {
        WCCG_Admin::instance();
        WCCG_Admin_Customer_Groups::instance();
        WCCG_Admin_User_Assignments::instance();
        WCCG_Admin_Pricing_Rules::instance();
    }

    private function init_public() {
        WCCG_Public::instance();
    }

    /**
     * Register the wccg_five_minutes custom cron interval.
     *
     * @since  1.1.0
     * @param  array $schedules Existing cron schedule definitions.
     * @return array Modified schedules including the wccg_five_minutes interval.
     */
    public function add_cron_schedules($schedules) {
        $schedules['wccg_five_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => esc_html__('Every 5 Minutes', 'alynt-customer-groups')
        );

        return $schedules;
    }

    /**
     * Run early init tasks hooked to WordPress 'init'.
     *
     * @since  1.0.0
     * @return void
     */
    public function init() {
        return;
    }

    /**
     * Execute daily database cleanup tasks via cron.
     *
     * @since  1.0.0
     * @return void
     */
    public function run_cleanup_tasks() {
        WCCG_Core::instance()->run_cleanup_tasks();
    }
}

