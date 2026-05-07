<?php
namespace HCO;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\Database\DB_Manager;

class Activator {

    public static function activate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) {
            foreach ( get_sites( [ 'fields' => 'ids' ] ) as $blog_id ) {
                switch_to_blog( $blog_id );
                self::create_tables();
                restore_current_blog();
            }
        } else {
            self::create_tables();
        }

        self::set_defaults();
        flush_rewrite_rules();
    }

    private static function create_tables(): void {
        DB_Manager::get_instance()->create_tables();
    }

    private static function set_defaults(): void {
        if ( ! get_option( 'hco_settings' ) ) {
            update_option( 'hco_settings', [
                'ai_provider'          => 'openai',
                'openai_model'         => 'o4-mini',
                'deepseek_model'       => 'deepseek-chat',
                'confidence_threshold' => 75,
                'auto_create'          => false,
                'auto_assign'          => false,
                'bulk_chunk_size'      => 25,
                'cache_ttl'            => 86400,
                'enable_audit_log'     => true,
                'require_approval'     => true,
            ] );
        }
    }
}
