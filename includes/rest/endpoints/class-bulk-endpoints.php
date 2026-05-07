<?php
namespace HCO\REST\Endpoints;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\Database\DB_Manager;
use HCO\Queue\Queue_Manager;
use HCO\REST\REST_Controller;

class Bulk_Endpoints {

    private const NS = 'hco/v1';

    public function register(): void {
        register_rest_route( self::NS, '/bulk/jobs', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_jobs' ],
                'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_job' ],
                'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
            ],
        ] );

        register_rest_route( self::NS, '/bulk/jobs/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_job' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/bulk/jobs/(?P<id>\d+)/pause', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'pause_job' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/bulk/jobs/(?P<id>\d+)/resume', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'resume_job' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/bulk/jobs/(?P<id>\d+)/suggestions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_job_suggestions' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/bulk/jobs/(?P<id>\d+)/apply', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'apply_job_suggestions' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );
    }

    public function get_jobs( \WP_REST_Request $request ): \WP_REST_Response {
        $jobs = DB_Manager::get_instance()->get_bulk_jobs();
        foreach ( $jobs as &$job ) {
            $job['settings'] = json_decode( $job['settings'] ?? '{}', true );
            $job['progress']  = $job['total_items'] > 0
                ? round( ( $job['processed_items'] / $job['total_items'] ) * 100, 1 )
                : 0;
        }
        return new \WP_REST_Response( $jobs, 200 );
    }

    public function create_job( \WP_REST_Request $request ): \WP_REST_Response {
        $body     = $request->get_json_params();
        $job_type = sanitize_text_field( $body['job_type'] ?? 'categorize_uncategorized' );
        $settings = $body['settings'] ?? [];

        $valid_types = [ 'categorize_uncategorized', 'recategorize_all', 'detect_orphans' ];
        if ( ! in_array( $job_type, $valid_types, true ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid job_type' ], 400 );
        }

        $job_id = Queue_Manager::get_instance()->create_bulk_job( $job_type, $settings );

        // Trigger WP-Cron immediately so the job starts without waiting for a site visit
        spawn_cron();

        return new \WP_REST_Response( [ 'job_id' => $job_id ], 201 );
    }

    public function get_job( \WP_REST_Request $request ): \WP_REST_Response {
        $job = DB_Manager::get_instance()->get_bulk_job( (int) $request['id'] );
        if ( ! $job ) return new \WP_REST_Response( [ 'error' => 'Job not found' ], 404 );

        $job['settings'] = json_decode( $job['settings'] ?? '{}', true );
        $job['progress']  = $job['total_items'] > 0
            ? round( ( $job['processed_items'] / $job['total_items'] ) * 100, 1 )
            : 0;

        return new \WP_REST_Response( $job, 200 );
    }

    public function get_job_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
        $db  = DB_Manager::get_instance();
        $job = $db->get_bulk_job( (int) $request['id'] );

        if ( ! $job ) {
            return new \WP_REST_Response( [ 'error' => 'Job not found' ], 404 );
        }
        if ( $job['status'] !== 'completed' ) {
            return new \WP_REST_Response( [ 'error' => 'Job not completed yet', 'status' => $job['status'] ], 202 );
        }

        $from = $job['started_at']   ?? $job['created_at'];
        $to   = $job['completed_at'] ?? current_time( 'mysql' );

        $suggestions = $db->get_suggestions_by_time_range( $from, $to );

        return new \WP_REST_Response( [
            'job'         => [
                'id'              => $job['id'],
                'job_type'        => $job['job_type'],
                'total_items'     => $job['total_items'],
                'processed_items' => $job['processed_items'],
                'failed_items'    => $job['failed_items'],
            ],
            'suggestions' => $suggestions,
            'total'       => count( $suggestions ),
        ], 200 );
    }

    public function apply_job_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();
        $ids  = array_map( 'absint', $body['ids'] ?? [] );

        if ( empty( $ids ) ) {
            return new \WP_REST_Response( [ 'error' => 'ids required' ], 400 );
        }

        $analyzer = new \HCO\AI\Category_Analyzer();
        $db       = DB_Manager::get_instance();
        $applied  = 0;
        $skipped  = 0;

        foreach ( $ids as $id ) {
            $db->update_suggestion( $id, [
                'status'      => 'approved',
                'reviewed_at' => current_time( 'mysql' ),
                'reviewed_by' => get_current_user_id(),
            ] );
            $analyzer->apply_suggestion( $id ) ? $applied++ : $skipped++;
        }

        return new \WP_REST_Response( [
            'applied'  => $applied,
            'skipped'  => $skipped,
            'total'    => count( $ids ),
        ], 200 );
    }

    public function pause_job( \WP_REST_Request $request ): \WP_REST_Response {
        $result = Queue_Manager::get_instance()->pause_job( (int) $request['id'] );
        return new \WP_REST_Response( [ 'paused' => $result ], 200 );
    }

    public function resume_job( \WP_REST_Request $request ): \WP_REST_Response {
        $result = Queue_Manager::get_instance()->resume_job( (int) $request['id'] );
        return new \WP_REST_Response( [ 'resumed' => $result ], 200 );
    }
}
