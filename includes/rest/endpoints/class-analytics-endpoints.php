<?php
namespace HCO\REST\Endpoints;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\Database\DB_Manager;
use HCO\REST\REST_Controller;

class Analytics_Endpoints {

    private const NS = 'hco/v1';

    public function register(): void {
        register_rest_route( self::NS, '/analytics/overview', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_overview' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/analytics/token-usage', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_token_usage' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );
    }

    public function get_overview( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $db = DB_Manager::get_instance();

        $suggestions_by_status = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}hco_suggestions GROUP BY status",
            ARRAY_A
        ) ?: [];

        $status_map = [];
        foreach ( $suggestions_by_status as $row ) {
            $status_map[ $row['status'] ] = (int) $row['count'];
        }

        $avg_confidence = (float) $wpdb->get_var(
            "SELECT AVG(confidence) FROM {$wpdb->prefix}hco_suggestions WHERE status != 'rejected'"
        );

        $recent_jobs = $db->get_bulk_jobs( 5 );

        $total_posts = (int) wp_count_posts()->publish;
        $total_cats  = (int) wp_count_terms( 'category', [ 'hide_empty' => false ] );

        return new \WP_REST_Response( [
            'suggestions'       => $status_map,
            'avg_confidence'    => round( $avg_confidence, 1 ),
            'total_posts'       => $total_posts,
            'total_categories'  => $total_cats,
            'recent_bulk_jobs'  => $recent_jobs,
        ], 200 );
    }

    public function get_token_usage( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ai_provider, ai_model, SUM(token_used) as total_tokens, COUNT(*) as suggestion_count
             FROM {$wpdb->prefix}hco_suggestions
             GROUP BY ai_provider, ai_model",
            ARRAY_A
        ) ?: [];

        $settings = get_option( 'hco_settings', [] );
        $costs    = [];

        foreach ( $rows as $row ) {
            $input_cost  = $row['ai_provider'] === 'deepseek' ? 0.00014 : 0.00015;
            $output_cost = $row['ai_provider'] === 'deepseek' ? 0.00028 : 0.0006;
            $tokens      = (int) $row['total_tokens'];
            $costs[]     = [
                'provider'         => $row['ai_provider'],
                'model'            => $row['ai_model'],
                'total_tokens'     => $tokens,
                'suggestion_count' => (int) $row['suggestion_count'],
                'estimated_cost'   => round( $tokens / 1000 * ( ( $input_cost + $output_cost ) / 2 ), 4 ),
            ];
        }

        return new \WP_REST_Response( [ 'usage' => $costs ], 200 );
    }
}
