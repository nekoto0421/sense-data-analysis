<?php
/**
 * Plugin Name: Sense Data Analysis
 * Description: 會員資料篩選與分析系統
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Sense
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SDA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SDA_PLUGIN_DIR . 'includes/class-sda-admin.php';
require_once SDA_PLUGIN_DIR . 'includes/class-sda-ajax.php';

add_action('admin_menu', ['SDA_Admin', 'add_menu']);
add_action('admin_enqueue_scripts', ['SDA_Admin', 'enqueue_assets']);
SDA_Ajax::init();
