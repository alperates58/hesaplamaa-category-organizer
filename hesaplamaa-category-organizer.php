<?php
/**
 * Plugin Name: Hesaplamaa Category Organizer
 * Plugin URI:  https://hesaplamaa.com
 * Description: AI-powered taxonomy architecture and SEO content structure platform for large-scale content websites.
 * Version:     1.0.0
 * Author:      Hesaplamaa
 * Author URI:  https://hesaplamaa.com
 * Text Domain: hesaplamaa-category-organizer
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Network:     true
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HCO_VERSION',     '1.0.0' );
define( 'HCO_FILE',        __FILE__ );
define( 'HCO_PATH',        plugin_dir_path( __FILE__ ) );
define( 'HCO_URL',         plugin_dir_url( __FILE__ ) );
define( 'HCO_ASSETS_URL',  HCO_URL . 'assets/' );
define( 'HCO_INCLUDES',    HCO_PATH . 'includes/' );

require_once HCO_INCLUDES . 'class-autoloader.php';

use HCO\Plugin;

register_activation_hook(   __FILE__, [ 'HCO\Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'HCO\Deactivator', 'deactivate' ] );

Plugin::get_instance()->init();
