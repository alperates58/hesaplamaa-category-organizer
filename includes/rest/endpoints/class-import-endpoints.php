<?php
namespace HCO\REST\Endpoints;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\Import\Excel_Importer;
use HCO\REST\REST_Controller;

class Import_Endpoints {

    private const NS = 'hco/v1';

    public function register(): void {
        register_rest_route( self::NS, '/import/preview', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'preview' ],
            'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
        ] );

        register_rest_route( self::NS, '/import/execute', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'execute' ],
            'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
        ] );
    }

    public function preview( \WP_REST_Request $request ): \WP_REST_Response {
        $files = $request->get_file_params();

        if ( empty( $files['excel_file'] ) || $files['excel_file']['error'] !== UPLOAD_ERR_OK ) {
            return new \WP_REST_Response( [ 'error' => 'Dosya yüklenemedi.' ], 400 );
        }

        $file = $files['excel_file'];
        $ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

        if ( $ext !== 'xlsx' ) {
            return new \WP_REST_Response( [ 'error' => 'Sadece .xlsx formatı desteklenir.' ], 400 );
        }

        try {
            $rows = Excel_Importer::parse_xlsx( $file['tmp_name'] );
            $diff = Excel_Importer::diff( $rows );
            return new \WP_REST_Response( $diff, 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function execute( \WP_REST_Request $request ): \WP_REST_Response {
        $body    = $request->get_json_params();
        $actions = $body['actions'] ?? [];

        if ( empty( $actions ) ) {
            return new \WP_REST_Response( [ 'error' => 'Eylem listesi boş.' ], 400 );
        }

        try {
            $result = Excel_Importer::execute( $actions );
            return new \WP_REST_Response( $result, 200 );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }
}
