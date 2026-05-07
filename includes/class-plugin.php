<?php
namespace HCO;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\Admin\Admin_Menu;
use HCO\Database\DB_Manager;
use HCO\GitHub\GitHub_Updater;
use HCO\Queue\Queue_Manager;
use HCO\REST\REST_Controller;

final class Plugin {

    private static ?Plugin $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init(): void {
        add_action( 'init',            [ $this, 'load_textdomain' ] );
        add_action( 'rest_api_init',   [ $this, 'register_rest_routes' ] );
        add_action( 'admin_menu',      [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_hco_bulk_process', [ $this, 'handle_bulk_ajax' ] );

        Queue_Manager::get_instance()->init();
        GitHub_Updater::get_instance()->init();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'hesaplamaa-category-organizer',
            false,
            dirname( plugin_basename( HCO_FILE ) ) . '/languages'
        );
    }

    public function register_rest_routes(): void {
        REST_Controller::get_instance()->register();
    }

    public function register_admin_menu(): void {
        Admin_Menu::get_instance()->register();
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'hco' ) === false && strpos( $hook, 'hesaplamaa' ) === false ) {
            return;
        }

        $manifest = HCO_PATH . 'assets/build/.vite/manifest.json';
        if ( ! file_exists( $manifest ) ) {
            // Dev mode — Vite HMR
            wp_enqueue_script_module(
                'hco-dev',
                'http://localhost:5173/@vite/client',
                [],
                null
            );
            return;
        }

        $assets = json_decode( file_get_contents( $manifest ), true );
        $entry  = $assets['src/main.tsx'] ?? null;

        if ( $entry ) {
            wp_enqueue_script(
                'hco-app',
                HCO_ASSETS_URL . 'build/' . $entry['file'],
                [],
                HCO_VERSION,
                true
            );

            foreach ( $entry['css'] ?? [] as $css ) {
                wp_enqueue_style( 'hco-app-css', HCO_ASSETS_URL . 'build/' . $css, [], HCO_VERSION );
            }
        }

        wp_localize_script( 'hco-app', 'hcoData', [
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'restUrl' => esc_url_raw( rest_url( 'hco/v1' ) ),
            'siteUrl' => esc_url_raw( get_site_url() ),
            'version' => HCO_VERSION,
            'locale'  => get_locale(),
        ] );
    }

    public function handle_bulk_ajax(): void {
        check_ajax_referer( 'hco_bulk', 'nonce' );
        if ( ! current_user_can( 'manage_categories' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }
        Queue_Manager::get_instance()->dispatch_bulk();
        wp_send_json_success();
    }
}
