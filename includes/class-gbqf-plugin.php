<?php
namespace GBQF;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Blocks handler.
     *
     * @var Blocks|null
     */
    public $blocks = null;

    /**
     * Filters handler.
     *
     * @var Filters|null
     */
    public $filters = null;

    /**
     * Settings handler.
     *
     * @var Settings|null
     */
    public $settings = null;

    /**
     * Get singleton instance.
     *
     * @return Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->includes();

        // All plugin hooks bootstrap from here.
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    /**
     * Include required files.
     */
    private function includes() {
        require_once GBQF_PLUGIN_DIR . 'includes/class-gbqf-settings.php';
        require_once GBQF_PLUGIN_DIR . 'includes/class-gbqf-blocks.php';
        require_once GBQF_PLUGIN_DIR . 'includes/class-gbqf-filters.php';
    }

    /**
     * Init hook.
     */
    public function init() {

        // Initialize blocks.
        $this->blocks  = new Blocks();

        // Initialize filters.
        $this->filters = new Filters();

        // Initialize settings (admin only hooks inside constructor).
        $this->settings = new Settings();

        /**
         * Fires when GB Query Filters is fully loaded.
         */
        do_action( 'gbqf_loaded' );
    }
}
