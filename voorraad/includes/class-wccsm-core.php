<?php
defined( 'ABSPATH' ) || exit;

/**
 * Core loader - registers all hooks and loads classes.
 */
class WCCSM_Core {

    private static ?WCCSM_Core $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_classes();
        $this->init_hooks();
    }

    private function load_classes(): void {
        require_once WCCSM_PLUGIN_DIR . 'includes/class-wccsm-components.php';
        require_once WCCSM_PLUGIN_DIR . 'includes/class-wccsm-stock.php';
        require_once WCCSM_PLUGIN_DIR . 'includes/class-wccsm-admin-overview.php';
        require_once WCCSM_PLUGIN_DIR . 'includes/class-wccsm-admin-product.php';
        require_once WCCSM_PLUGIN_DIR . 'includes/class-wccsm-bulk.php';
    }

    private function init_hooks(): void {
        new WCCSM_Components();
        new WCCSM_Stock();
        new WCCSM_Admin_Overview();
        new WCCSM_Admin_Product();
        new WCCSM_Bulk();
    }
}
