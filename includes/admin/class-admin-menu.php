<?php
namespace HCO\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Admin_Menu {

    private static ?Admin_Menu $instance = null;
    public static function get_instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function register(): void {
        add_menu_page(
            __( 'Category Organizer', 'hesaplamaa-category-organizer' ),
            __( 'Cat Organizer', 'hesaplamaa-category-organizer' ),
            'manage_categories',
            'hco-dashboard',
            [ $this, 'render_app' ],
            $this->get_svg_icon(),
            25
        );

        $pages = [
            [ 'hco-dashboard',    __( 'Dashboard', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-ai-assistant', __( 'AI Assistant', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-bulk',         __( 'Bulk Analysis', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-suggestions',  __( 'Suggestions', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-clusters',     __( 'SEO Clusters', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-audit-log',    __( 'Audit Log', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-settings',     __( 'Settings', 'hesaplamaa-category-organizer' ) ],
            [ 'hco-github',       __( 'GitHub Güncelle', 'hesaplamaa-category-organizer' ) ],
        ];

        foreach ( $pages as [ $slug, $title ] ) {
            add_submenu_page(
                'hco-dashboard',
                $title,
                $title,
                'manage_categories',
                $slug,
                [ $this, 'render_app' ]
            );
        }
    }

    public function render_app(): void {
        echo '<div id="hco-root" class="hco-app-root"></div>';
    }

    private function get_svg_icon(): string {
        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white">
              <path d="M4 6h16M4 12h10M4 18h7"/>
              <circle cx="19" cy="17" r="3"/>
              <path d="M17.5 17h3M19 15.5v3"/>
            </svg>'
        );
    }
}
