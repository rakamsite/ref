<?php
/**
 * Plugin Name: Rakam Reference Builder
 * Plugin URI: https://example.com/
 * Description: Build WooCommerce product tags from BehranFilter cross reference codes.
 * Version: 1.0.0
 * Author: Rakam
 * Text Domain: rakam-reference-builder
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RRB_PLUGIN_FILE', __FILE__);
define('RRB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RRB_PLUGIN_URL', plugin_dir_url(__FILE__));

autoload_rrb();

function autoload_rrb() {
    require_once RRB_PLUGIN_DIR . 'includes/class-rrb-db.php';
    require_once RRB_PLUGIN_DIR . 'includes/class-rrb-queue.php';
    require_once RRB_PLUGIN_DIR . 'includes/class-rrb-parser.php';
    require_once RRB_PLUGIN_DIR . 'includes/class-rrb-tags.php';
    require_once RRB_PLUGIN_DIR . 'includes/class-rrb-admin.php';
}

function rrb_init_plugin() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    RRB_DB::init();
    RRB_Queue::init();
    RRB_Admin::init();
}
add_action('plugins_loaded', 'rrb_init_plugin');

register_activation_hook(__FILE__, array('RRB_DB', 'activate'));
register_deactivation_hook(__FILE__, array('RRB_Queue', 'deactivate'));
