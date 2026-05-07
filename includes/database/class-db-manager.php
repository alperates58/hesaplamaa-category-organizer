<?php
namespace HCO\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

final class DB_Manager {

    private static ?DB_Manager $instance = null;
    public static function get_instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // AI Suggestions table
        dbDelta( "CREATE TABLE {$wpdb->prefix}hco_suggestions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id         BIGINT UNSIGNED NOT NULL,
            current_cat_id  BIGINT UNSIGNED DEFAULT NULL,
            suggested_cat_id BIGINT UNSIGNED DEFAULT NULL,
            suggested_cat_name VARCHAR(255) DEFAULT NULL,
            suggested_cat_slug VARCHAR(255) DEFAULT NULL,
            suggested_parent_id BIGINT UNSIGNED DEFAULT NULL,
            suggested_parent_name VARCHAR(255) DEFAULT NULL,
            confidence      TINYINT UNSIGNED NOT NULL DEFAULT 0,
            seo_intent      VARCHAR(100) DEFAULT NULL,
            ai_reasoning    TEXT DEFAULT NULL,
            ai_provider     VARCHAR(50) NOT NULL DEFAULT 'openai',
            ai_model        VARCHAR(100) DEFAULT NULL,
            token_used      INT UNSIGNED DEFAULT 0,
            status          ENUM('pending','approved','rejected','applied','rolled_back') NOT NULL DEFAULT 'pending',
            type            ENUM('categorize','new_category','merge','redirect','cluster') NOT NULL DEFAULT 'categorize',
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at     DATETIME DEFAULT NULL,
            reviewed_by     BIGINT UNSIGNED DEFAULT NULL,
            applied_at      DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_status (status),
            KEY idx_type (type),
            KEY idx_confidence (confidence),
            KEY idx_created_at (created_at)
        ) $charset;" );

        // Audit log table
        dbDelta( "CREATE TABLE {$wpdb->prefix}hco_audit_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action      VARCHAR(100) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id   BIGINT UNSIGNED DEFAULT NULL,
            user_id     BIGINT UNSIGNED DEFAULT NULL,
            before_data LONGTEXT DEFAULT NULL,
            after_data  LONGTEXT DEFAULT NULL,
            meta        TEXT DEFAULT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_user_id (user_id),
            KEY idx_created_at (created_at)
        ) $charset;" );

        // Bulk jobs table
        dbDelta( "CREATE TABLE {$wpdb->prefix}hco_bulk_jobs (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type        VARCHAR(100) NOT NULL,
            status          ENUM('queued','running','paused','completed','failed') NOT NULL DEFAULT 'queued',
            total_items     INT UNSIGNED NOT NULL DEFAULT 0,
            processed_items INT UNSIGNED NOT NULL DEFAULT 0,
            failed_items    INT UNSIGNED NOT NULL DEFAULT 0,
            settings        LONGTEXT DEFAULT NULL,
            started_at      DATETIME DEFAULT NULL,
            completed_at    DATETIME DEFAULT NULL,
            created_by      BIGINT UNSIGNED DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            error_log       TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_job_type (job_type)
        ) $charset;" );

        // Cache table for AI embeddings/results
        dbDelta( "CREATE TABLE {$wpdb->prefix}hco_ai_cache (
            cache_key   VARCHAR(64) NOT NULL,
            cache_value LONGTEXT NOT NULL,
            expires_at  DATETIME NOT NULL,
            PRIMARY KEY (cache_key),
            KEY idx_expires_at (expires_at)
        ) $charset;" );

        // SEO Clusters table
        dbDelta( "CREATE TABLE {$wpdb->prefix}hco_seo_clusters (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cluster_name    VARCHAR(255) NOT NULL,
            cluster_slug    VARCHAR(255) NOT NULL,
            parent_cat_id   BIGINT UNSIGNED DEFAULT NULL,
            post_ids        LONGTEXT DEFAULT NULL,
            subcategories   LONGTEXT DEFAULT NULL,
            confidence      TINYINT UNSIGNED DEFAULT 0,
            status          ENUM('suggested','approved','applied') NOT NULL DEFAULT 'suggested',
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cluster_slug (cluster_slug),
            KEY idx_status (status)
        ) $charset;" );

        update_option( 'hco_db_version', HCO_VERSION );
    }

    public function get_suggestions( array $args = [] ): array {
        global $wpdb;
        $defaults = [
            'status'  => null,
            'type'    => null,
            'limit'   => 50,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        ];
        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $params = [];

        if ( $args['status'] ) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }
        if ( $args['type'] ) {
            $where .= ' AND type = %s';
            $params[] = $args['type'];
        }

        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] ) ?: 'created_at DESC';
        $sql = "SELECT s.*, p.post_title, p.post_status FROM {$wpdb->prefix}hco_suggestions s
                LEFT JOIN {$wpdb->posts} p ON p.ID = s.post_id
                WHERE $where ORDER BY $orderby LIMIT %d OFFSET %d";

        $params[] = absint( $args['limit'] );
        $params[] = absint( $args['offset'] );

        return $wpdb->get_results(
            ! empty( $params ) ? $wpdb->prepare( $sql, ...$params ) : $sql,
            ARRAY_A
        ) ?: [];
    }

    public function count_suggestions( array $args = [] ): int {
        global $wpdb;
        $where = '1=1';
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }

        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}hco_suggestions WHERE $where";
        return (int) $wpdb->get_var(
            ! empty( $params ) ? $wpdb->prepare( $sql, ...$params ) : $sql
        );
    }

    public function insert_suggestion( array $data ): int|false {
        global $wpdb;
        $result = $wpdb->insert( $wpdb->prefix . 'hco_suggestions', $data );
        return $result ? $wpdb->insert_id : false;
    }

    public function update_suggestion( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'hco_suggestions',
            $data,
            [ 'id' => $id ]
        );
    }

    public function get_suggestion( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hco_suggestions WHERE id = %d", $id ),
            ARRAY_A
        );
    }

    public function log_audit( string $action, string $entity_type, int $entity_id, $before = null, $after = null, array $meta = [] ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hco_audit_log', [
            'action'      => $action,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'user_id'     => get_current_user_id(),
            'before_data' => $before ? wp_json_encode( $before ) : null,
            'after_data'  => $after ? wp_json_encode( $after ) : null,
            'meta'        => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
        ] );
    }

    public function get_audit_log( array $args = [] ): array {
        global $wpdb;
        $limit  = absint( $args['limit'] ?? 50 );
        $offset = absint( $args['offset'] ?? 0 );
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, u.display_name FROM {$wpdb->prefix}hco_audit_log l
                 LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
                 ORDER BY l.created_at DESC LIMIT %d OFFSET %d",
                $limit, $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    public function get_ai_cache( string $key ): mixed {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cache_value FROM {$wpdb->prefix}hco_ai_cache WHERE cache_key = %s AND expires_at > NOW()",
                $key
            )
        );
        return $row ? json_decode( $row->cache_value, true ) : null;
    }

    public function set_ai_cache( string $key, mixed $value, int $ttl = 86400 ): void {
        global $wpdb;
        $wpdb->replace( $wpdb->prefix . 'hco_ai_cache', [
            'cache_key'   => $key,
            'cache_value' => wp_json_encode( $value ),
            'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
        ] );
    }

    public function create_bulk_job( array $data ): int|false {
        global $wpdb;
        $result = $wpdb->insert( $wpdb->prefix . 'hco_bulk_jobs', array_merge( [
            'created_by' => get_current_user_id(),
            'settings'   => wp_json_encode( $data['settings'] ?? [] ),
        ], $data ) );
        return $result ? $wpdb->insert_id : false;
    }

    public function update_bulk_job( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( $wpdb->prefix . 'hco_bulk_jobs', $data, [ 'id' => $id ] );
    }

    public function get_bulk_job( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hco_bulk_jobs WHERE id = %d", $id ),
            ARRAY_A
        );
    }

    public function get_bulk_jobs( int $limit = 20 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hco_bulk_jobs ORDER BY created_at DESC LIMIT %d", $limit ),
            ARRAY_A
        ) ?: [];
    }
}
