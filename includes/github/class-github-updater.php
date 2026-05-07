<?php
namespace HCO\GitHub;

if ( ! defined( 'ABSPATH' ) ) exit;

final class GitHub_Updater {

    private const OPTION_KEY = 'hco_github_settings';

    private static ?GitHub_Updater $instance = null;
    public static function get_instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function init(): void {
        add_action( 'admin_post_hco_save_github_settings', [ $this, 'handle_save_settings' ] );
        add_action( 'admin_post_hco_update_from_github',   [ $this, 'handle_update' ] );
    }

    public function get_settings(): array {
        return wp_parse_args(
            get_option( self::OPTION_KEY, [] ),
            [
                'repo'   => 'alperates58/hesaplamaa-category-organizer',
                'branch' => 'main',
                'token'  => '',
            ]
        );
    }

    public function save_settings( array $data ): void {
        update_option( self::OPTION_KEY, [
            'repo'   => sanitize_text_field( wp_unslash( $data['repo']   ?? '' ) ),
            'branch' => sanitize_text_field( wp_unslash( $data['branch'] ?? 'main' ) ),
            'token'  => sanitize_text_field( wp_unslash( $data['token']  ?? '' ) ),
        ] );
    }

    public function handle_save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Yetkiniz yok.', 'hesaplamaa-category-organizer' ) );
        }
        check_admin_referer( 'hco_save_github_settings' );
        $this->save_settings( $_POST );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'hco-github', 'saved' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function get_remote_commit(): ?string {
        $s = $this->get_settings();
        if ( empty( $s['repo'] ) || empty( $s['branch'] ) ) return null;

        $url = sprintf(
            'https://api.github.com/repos/%s/commits/%s',
            str_replace( '%2F', '/', rawurlencode( $s['repo'] ) ),
            rawurlencode( $s['branch'] )
        );

        $response = wp_remote_get( $url, $this->request_args( 20 ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? ( $body['sha'] ?? null ) : null;
    }

    public function handle_update(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Yetkiniz yok.', 'hesaplamaa-category-organizer' ) );
        }
        check_admin_referer( 'hco_update_from_github' );

        $result = $this->download_and_install();
        $args   = [ 'page' => 'hco-github' ];

        if ( true === $result ) {
            $args['update'] = 'success';
        } else {
            $args['update_error'] = rawurlencode( (string) $result );
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function download_and_install(): bool|string {
        $s = $this->get_settings();
        if ( empty( $s['repo'] ) || empty( $s['branch'] ) ) {
            return __( 'Repo veya branch ayarı eksik.', 'hesaplamaa-category-organizer' );
        }

        $tmp = $this->download_zip( $s );
        if ( is_wp_error( $tmp ) ) {
            return $tmp->get_error_message();
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        WP_Filesystem();

        $plugin_base = dirname( HCO_PATH );
        $destination = untrailingslashit( HCO_PATH );

        $unzip = unzip_file( $tmp, $plugin_base );
        @unlink( $tmp );

        if ( is_wp_error( $unzip ) ) {
            return $unzip->get_error_message();
        }

        $repo_name     = basename( $s['repo'] );
        $branch_suffix = str_replace( '/', '-', $s['branch'] );
        $extracted_dir = trailingslashit( $plugin_base ) . $repo_name . '-' . $branch_suffix;

        if ( ! is_dir( $extracted_dir ) ) {
            return __( 'İndirilen paket açıldı ama beklenen klasör bulunamadı.', 'hesaplamaa-category-organizer' );
        }

        $plugin_slug = basename( $destination );
        $source_dir  = trailingslashit( $extracted_dir ) . $plugin_slug;
        if ( ! is_dir( $source_dir ) ) {
            $source_dir = $extracted_dir;
        }

        if ( ! $wp_filesystem->delete( $destination, true ) ) {
            return __( 'Mevcut eklenti klasörü silinemedi.', 'hesaplamaa-category-organizer' );
        }

        if ( ! @rename( $source_dir, $destination ) ) {
            return __( 'Yeni eklenti klasörü yerine taşınamadı.', 'hesaplamaa-category-organizer' );
        }

        if ( is_dir( $extracted_dir ) && $extracted_dir !== $source_dir ) {
            $wp_filesystem->delete( $extracted_dir, true );
        }

        $remote_sha = $this->get_remote_commit();
        update_option( 'hco_last_update',     current_time( 'mysql' ) );
        update_option( 'hco_last_update_sha', $remote_sha ?? '' );

        if ( function_exists( 'wp_clean_plugins_cache' ) ) wp_clean_plugins_cache( true );
        if ( function_exists( 'wp_cache_flush' ) )         wp_cache_flush();
        if ( function_exists( 'opcache_reset' ) )           @opcache_reset();

        return true;
    }

    private function download_zip( array $s ): string|\WP_Error {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $zip_url = sprintf(
            'https://github.com/%s/archive/refs/heads/%s.zip',
            $s['repo'],
            rawurlencode( $s['branch'] )
        );

        if ( empty( $s['token'] ) ) {
            return download_url( $zip_url, 60 );
        }

        $tmp = wp_tempnam( basename( $s['repo'] ) . '-' . $s['branch'] . '.zip' );
        if ( ! $tmp ) {
            return new \WP_Error( 'hco_temp_file', __( 'Geçici dosya oluşturulamadı.', 'hesaplamaa-category-organizer' ) );
        }

        $args             = $this->request_args( 60 );
        $args['stream']   = true;
        $args['filename'] = $tmp;

        $response = wp_remote_get( $zip_url, $args );
        if ( is_wp_error( $response ) ) { @unlink( $tmp ); return $response; }
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
            @unlink( $tmp );
            return new \WP_Error( 'hco_download_failed', __( 'GitHub ZIP indirilemedi.', 'hesaplamaa-category-organizer' ) );
        }

        return $tmp;
    }

    private function request_args( int $timeout ): array {
        $s       = $this->get_settings();
        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'hesaplamaa-category-organizer',
        ];
        if ( ! empty( $s['token'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $s['token'];
        }
        return [ 'timeout' => $timeout, 'headers' => $headers ];
    }
}
