<?php
namespace HCO\REST\Endpoints;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\REST\REST_Controller;

class Settings_Endpoints {

    private const NS = 'hco/v1';

    public function register(): void {
        register_rest_route( self::NS, '/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'update_settings' ],
                'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
            ],
        ] );

        register_rest_route( self::NS, '/settings/test-connection', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'test_connection' ],
            'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
        ] );
    }

    public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $settings = get_option( 'hco_settings', [] );
        // Mask API keys
        if ( ! empty( $settings['openai_api_key'] ) ) {
            $settings['openai_api_key'] = $this->mask_key( $settings['openai_api_key'] );
        }
        if ( ! empty( $settings['deepseek_api_key'] ) ) {
            $settings['deepseek_api_key'] = $this->mask_key( $settings['deepseek_api_key'] );
        }
        return new \WP_REST_Response( $settings, 200 );
    }

    public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $body     = $request->get_json_params();
        $current  = get_option( 'hco_settings', [] );

        $allowed = [
            'ai_provider', 'openai_model', 'deepseek_model',
            'confidence_threshold', 'auto_create', 'auto_assign',
            'bulk_chunk_size', 'cache_ttl', 'enable_audit_log',
            'require_approval',
        ];

        foreach ( $allowed as $key ) {
            if ( isset( $body[ $key ] ) ) {
                $current[ $key ] = $body[ $key ];
            }
        }

        // Only update API keys if they're not masked (i.e., user actually changed them)
        foreach ( [ 'openai_api_key', 'deepseek_api_key' ] as $key ) {
            if ( isset( $body[ $key ] ) && ! str_contains( (string) $body[ $key ], '***' ) ) {
                $current[ $key ] = sanitize_text_field( $body[ $key ] );
            }
        }

        update_option( 'hco_settings', $current );
        return new \WP_REST_Response( [ 'saved' => true ], 200 );
    }

    public function test_connection( \WP_REST_Request $request ): \WP_REST_Response {
        $body     = $request->get_json_params();
        $provider = sanitize_text_field( $body['provider'] ?? 'openai' );
        $api_key  = sanitize_text_field( $body['api_key'] ?? '' );

        // If a non-masked key is supplied in the request, persist it before testing.
        if ( ! empty( $api_key ) && ! str_contains( $api_key, '***' ) ) {
            $key_field = $provider === 'deepseek' ? 'deepseek_api_key' : 'openai_api_key';
            $current   = get_option( 'hco_settings', [] );
            $current[ $key_field ] = $api_key;
            update_option( 'hco_settings', $current );
        }

        try {
            $p = \HCO\AI\AI_Provider_Factory::make( $provider );
            if ( ! $p->is_configured() ) {
                return new \WP_REST_Response( [ 'success' => false, 'message' => 'API key not configured.' ], 200 );
            }

            // Minimal test call
            $test_post = [
                'title'   => 'KDV Hesaplama Test',
                'content' => 'Bu bir test içeriğidir.',
                'slug'    => 'kdv-hesaplama-test',
            ];
            $test_tax = [
                [ 'id' => 1, 'name' => 'Finans', 'slug' => 'finans', 'parent' => 0, 'parent_name' => '', 'count' => 10 ],
            ];
            $result = $p->analyze_post( $test_post, $test_tax );
            return new \WP_REST_Response( [
                'success' => true,
                'message' => 'Connection successful.',
                'tokens_used' => $result['tokens_used'] ?? 0,
            ], 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 200 );
        }
    }

    private function mask_key( string $key ): string {
        if ( strlen( $key ) <= 8 ) return '***';
        return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
    }
}
