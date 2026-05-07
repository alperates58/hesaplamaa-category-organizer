<?php
namespace HCO\REST\Endpoints;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\GitHub\GitHub_Updater;
use HCO\REST\REST_Controller;

class GitHub_Endpoints {

    private const NS = 'hco/v1';

    public function register(): void {
        register_rest_route( self::NS, '/github/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_settings' ],
                'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
            ],
        ] );

        register_rest_route( self::NS, '/github/check-version', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'check_version' ],
            'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
        ] );

        register_rest_route( self::NS, '/github/update', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'run_update' ],
            'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
        ] );

        register_rest_route( self::NS, '/github/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
        ] );
    }

    public function get_settings(): \WP_REST_Response {
        $s = GitHub_Updater::get_instance()->get_settings();
        // Token'ı maskele
        if ( ! empty( $s['token'] ) ) {
            $s['token'] = substr( $s['token'], 0, 4 ) . str_repeat( '*', max( 0, strlen($s['token']) - 8 ) ) . substr( $s['token'], -4 );
        }
        return new \WP_REST_Response( $s, 200 );
    }

    public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();
        $current = GitHub_Updater::get_instance()->get_settings();

        $new = [
            'repo'   => sanitize_text_field( $body['repo']   ?? $current['repo'] ),
            'branch' => sanitize_text_field( $body['branch'] ?? $current['branch'] ),
        ];

        // Token: maskelenmemişse güncelle
        if ( isset( $body['token'] ) && ! str_contains( (string) $body['token'], '****' ) ) {
            $new['token'] = sanitize_text_field( $body['token'] );
        } else {
            $new['token'] = $current['token'];
        }

        GitHub_Updater::get_instance()->save_settings( $new );
        return new \WP_REST_Response( [ 'saved' => true ], 200 );
    }

    public function check_version(): \WP_REST_Response {
        $sha = GitHub_Updater::get_instance()->get_remote_commit();

        if ( ! $sha ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'GitHub sürümü okunamadı. Repo, branch veya token bilgisini kontrol edin.',
            ], 200 );
        }

        return new \WP_REST_Response( [
            'success' => true,
            'sha'     => substr( $sha, 0, 7 ),
            'sha_full'=> $sha,
            'message' => 'Son commit: ' . substr( $sha, 0, 7 ),
        ], 200 );
    }

    public function run_update( \WP_REST_Request $request ): \WP_REST_Response {
        // Güncelleme işlemi arka planda çalışmalı — action hook ile tetikle
        $result = $this->do_update();

        if ( true === $result ) {
            return new \WP_REST_Response( [
                'success' => true,
                'message' => 'Eklenti GitHub üzerinden başarıyla güncellendi.',
            ], 200 );
        }

        return new \WP_REST_Response( [
            'success' => false,
            'message' => $result,
        ], 200 );
    }

    public function get_status(): \WP_REST_Response {
        return new \WP_REST_Response( [
            'last_update'     => get_option( 'hco_last_update', null ),
            'last_update_sha' => get_option( 'hco_last_update_sha', null ),
            'active_version'  => HCO_VERSION,
            'settings'        => ( function() {
                $s = GitHub_Updater::get_instance()->get_settings();
                unset( $s['token'] );
                return $s;
            } )(),
        ], 200 );
    }

    private function do_update(): bool|string {
        // GitHub_Updater'ın private metodunu invoke etmek için kendi REST bağlamında çalıştır
        // admin-post olmadan çalışacak şekilde — updater nesnesini reflect ile çalıştır
        $updater = GitHub_Updater::get_instance();

        try {
            $ref = new \ReflectionMethod( $updater, 'download_and_install' );
            $ref->setAccessible( true );
            return $ref->invoke( $updater );
        } catch ( \Throwable $e ) {
            return $e->getMessage();
        }
    }
}
