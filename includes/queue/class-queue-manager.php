<?php
namespace HCO\Queue;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\AI\Category_Analyzer;
use HCO\Database\DB_Manager;

final class Queue_Manager {

    private static ?Queue_Manager $instance = null;
    public static function get_instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function init(): void {
        add_action( 'hco_process_queue_batch', [ $this, 'process_batch' ] );
        add_action( 'hco_bulk_analysis',        [ $this, 'run_bulk_job' ] );
    }

    public function create_bulk_job( string $job_type, array $settings = [] ): int {
        $db = DB_Manager::get_instance();

        $query_args = $this->build_query_args( $job_type, $settings );
        $total      = (int) ( new \WP_Query( array_merge( $query_args, [ 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => false ] ) ) )->found_posts;

        $job_id = $db->create_bulk_job( [
            'job_type'    => $job_type,
            'status'      => 'queued',
            'total_items' => $total,
            'settings'    => $settings,
        ] );

        if ( $job_id ) {
            wp_schedule_single_event( time() + 5, 'hco_bulk_analysis', [ $job_id ] );
        }

        return (int) $job_id;
    }

    public function run_bulk_job( int $job_id ): void {
        $db  = DB_Manager::get_instance();
        $job = $db->get_bulk_job( $job_id );

        if ( ! $job || $job['status'] === 'paused' || $job['status'] === 'completed' ) return;

        $db->update_bulk_job( $job_id, [
            'status'     => 'running',
            'started_at' => current_time( 'mysql' ),
        ] );

        $settings = json_decode( $job['settings'], true ) ?: [];
        $chunk    = absint( get_option( 'hco_settings', [] )['bulk_chunk_size'] ?? 25 );

        $query_args = array_merge(
            $this->build_query_args( $job['job_type'], $settings ),
            [
                'fields'         => 'ids',
                'posts_per_page' => $chunk,
                'offset'         => (int) $job['processed_items'],
                'no_found_rows'  => true,
            ]
        );

        $post_ids = get_posts( $query_args );

        if ( empty( $post_ids ) ) {
            $db->update_bulk_job( $job_id, [
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ] );
            return;
        }

        $analyzer = new Category_Analyzer();
        $processed = 0;
        $failed    = 0;

        foreach ( $post_ids as $post_id ) {
            try {
                $analyzer->analyze_single_post( (int) $post_id );
                $processed++;
            } catch ( \Throwable $e ) {
                $failed++;
                error_log( "HCO bulk job #{$job_id} failed for post #{$post_id}: " . $e->getMessage() );
            }
        }

        $new_processed = (int) $job['processed_items'] + $processed;
        $new_failed    = (int) $job['failed_items'] + $failed;

        $is_done = $new_processed >= (int) $job['total_items'] || count( $post_ids ) < $chunk;

        $db->update_bulk_job( $job_id, [
            'processed_items' => $new_processed,
            'failed_items'    => $new_failed,
            'status'          => $is_done ? 'completed' : 'running',
            'completed_at'    => $is_done ? current_time( 'mysql' ) : null,
        ] );

        if ( ! $is_done ) {
            wp_schedule_single_event( time() + 2, 'hco_bulk_analysis', [ $job_id ] );
        }
    }

    public function pause_job( int $job_id ): bool {
        $db  = DB_Manager::get_instance();
        $job = $db->get_bulk_job( $job_id );
        if ( ! $job || $job['status'] !== 'running' ) return false;
        return $db->update_bulk_job( $job_id, [ 'status' => 'paused' ] );
    }

    public function resume_job( int $job_id ): bool {
        $db  = DB_Manager::get_instance();
        $job = $db->get_bulk_job( $job_id );
        if ( ! $job || $job['status'] !== 'paused' ) return false;

        $db->update_bulk_job( $job_id, [ 'status' => 'queued' ] );
        wp_schedule_single_event( time() + 2, 'hco_bulk_analysis', [ $job_id ] );
        return true;
    }

    public function dispatch_bulk(): void {
        $this->create_bulk_job( 'categorize_uncategorized' );
    }

    private function build_query_args( string $job_type, array $settings ): array {
        $base = [
            'post_type'      => $settings['post_types'] ?? 'post',
            'post_status'    => 'publish',
            'suppress_filters' => false,
        ];

        return match( $job_type ) {
            'categorize_uncategorized' => array_merge( $base, [
                'category__in' => [ get_option( 'default_category', 1 ) ],
            ] ),
            'recategorize_all' => $base,
            'detect_orphans'   => array_merge( $base, [
                'tax_query' => [[
                    'taxonomy' => 'category',
                    'operator' => 'NOT EXISTS',
                ]],
            ] ),
            default => $base,
        };
    }
}
