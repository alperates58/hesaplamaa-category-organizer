<?php
namespace HCO;

if ( ! defined( 'ABSPATH' ) ) exit;

class Deactivator {
    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'hco_process_queue' );
        wp_clear_scheduled_hook( 'hco_bulk_analysis' );
        flush_rewrite_rules();
    }
}
