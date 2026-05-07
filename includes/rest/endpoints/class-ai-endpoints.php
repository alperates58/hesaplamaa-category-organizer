<?php
namespace HCO\REST\Endpoints;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\AI\AI_Provider_Factory;
use HCO\AI\Category_Analyzer;
use HCO\Database\DB_Manager;
use HCO\REST\REST_Controller;

class AI_Endpoints {

    private const NS = 'hco/v1';

    public function register(): void {
        register_rest_route( self::NS, '/ai/analyze-post', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'analyze_post' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/ai/suggestions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_suggestions' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/ai/suggestions/(?P<id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'approve_suggestion' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/ai/suggestions/(?P<id>\d+)/reject', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'reject_suggestion' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/ai/suggestions/(?P<id>\d+)/rollback', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rollback_suggestion' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/ai/suggestions/bulk-approve', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'bulk_approve' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/ai/detect-clusters', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'detect_clusters' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/ai/detect-duplicates', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'detect_duplicates' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/ai/detect-missing-categories', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'detect_missing_categories' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/ai/providers', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_providers' ],
            'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
        ] );

        register_rest_route( self::NS, '/ai/audit-log', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_audit_log' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );
    }

    public function analyze_post( \WP_REST_Request $request ): \WP_REST_Response {
        $body    = $request->get_json_params();
        $post_id = absint( $body['post_id'] ?? 0 );

        if ( ! $post_id ) {
            return new \WP_REST_Response( [ 'error' => 'post_id required' ], 400 );
        }

        try {
            $analyzer = new Category_Analyzer();
            $result   = $analyzer->analyze_single_post( $post_id );
            return new \WP_REST_Response( $result, 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function get_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'status'  => $request->get_param( 'status' ),
            'type'    => $request->get_param( 'type' ),
            'limit'   => absint( $request->get_param( 'limit' ) ?: 50 ),
            'offset'  => absint( $request->get_param( 'offset' ) ?: 0 ),
            'orderby' => $request->get_param( 'orderby' ) ?: 'created_at',
            'order'   => strtoupper( $request->get_param( 'order' ) ?: 'DESC' ),
        ];

        $db   = DB_Manager::get_instance();
        $data = $db->get_suggestions( $args );
        $total = $db->count_suggestions( $args );

        return new \WP_REST_Response( [
            'items' => $data,
            'total' => $total,
            'limit' => $args['limit'],
            'offset'=> $args['offset'],
        ], 200 );
    }

    public function approve_suggestion( \WP_REST_Request $request ): \WP_REST_Response {
        $id       = (int) $request['id'];
        $analyzer = new Category_Analyzer();
        $db       = DB_Manager::get_instance();

        $db->update_suggestion( $id, [
            'status'      => 'approved',
            'reviewed_at' => current_time( 'mysql' ),
            'reviewed_by' => get_current_user_id(),
        ] );

        $applied = $analyzer->apply_suggestion( $id );
        return new \WP_REST_Response( [ 'applied' => $applied ], 200 );
    }

    public function reject_suggestion( \WP_REST_Request $request ): \WP_REST_Response {
        $id       = (int) $request['id'];
        $analyzer = new Category_Analyzer();
        $result   = $analyzer->reject_suggestion( $id );
        return new \WP_REST_Response( [ 'rejected' => $result ], 200 );
    }

    public function rollback_suggestion( \WP_REST_Request $request ): \WP_REST_Response {
        $id       = (int) $request['id'];
        $analyzer = new Category_Analyzer();
        $result   = $analyzer->rollback_suggestion( $id );
        return new \WP_REST_Response( [ 'rolled_back' => $result ], 200 );
    }

    public function bulk_approve( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();
        $ids  = array_map( 'absint', $body['ids'] ?? [] );

        if ( empty( $ids ) ) {
            return new \WP_REST_Response( [ 'error' => 'ids required' ], 400 );
        }

        $analyzer = new Category_Analyzer();
        $db       = DB_Manager::get_instance();
        $applied  = 0;

        foreach ( $ids as $id ) {
            $db->update_suggestion( $id, [
                'status'      => 'approved',
                'reviewed_at' => current_time( 'mysql' ),
                'reviewed_by' => get_current_user_id(),
            ] );
            if ( $analyzer->apply_suggestion( $id ) ) {
                $applied++;
            }
        }

        return new \WP_REST_Response( [ 'applied' => $applied, 'total' => count($ids) ], 200 );
    }

    public function detect_clusters( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $analyzer = new Category_Analyzer();
            $taxonomy = $analyzer->get_flat_taxonomy();

            $posts = get_posts( [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'fields'         => 'ids',
            ] );

            $post_data = array_map( [ $analyzer, 'build_post_data' ], $posts );
            $provider  = AI_Provider_Factory::make();
            $clusters  = $provider->detect_seo_clusters( $post_data, $taxonomy );

            return new \WP_REST_Response( [ 'clusters' => $clusters ], 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function detect_duplicates( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $analyzer  = new Category_Analyzer();
            $taxonomy  = $analyzer->get_flat_taxonomy();
            $provider  = AI_Provider_Factory::make();
            $duplicates = $provider->detect_duplicate_intent( $taxonomy );

            return new \WP_REST_Response( [ 'duplicate_groups' => $duplicates ], 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function detect_missing_categories( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $analyzer = new Category_Analyzer();
            $taxonomy = $analyzer->get_flat_taxonomy();

            $posts = get_posts( [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 60,
                'fields'         => 'ids',
            ] );

            $post_data = array_map( [ $analyzer, 'build_post_data' ], $posts );
            $provider  = AI_Provider_Factory::make();
            $missing   = $provider->detect_missing_categories( $post_data, $taxonomy );

            return new \WP_REST_Response( [ 'missing_categories' => $missing ], 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function get_providers( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( AI_Provider_Factory::get_available_providers(), 200 );
    }

    public function get_audit_log( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'limit'  => absint( $request->get_param( 'limit' ) ?: 50 ),
            'offset' => absint( $request->get_param( 'offset' ) ?: 0 ),
        ];
        $db   = DB_Manager::get_instance();
        $data = $db->get_audit_log( $args );
        return new \WP_REST_Response( $data, 200 );
    }
}
