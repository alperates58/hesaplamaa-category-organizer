<?php
namespace HCO\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\REST\Endpoints\Category_Endpoints;
use HCO\REST\Endpoints\AI_Endpoints;
use HCO\REST\Endpoints\Bulk_Endpoints;
use HCO\REST\Endpoints\Settings_Endpoints;
use HCO\REST\Endpoints\Analytics_Endpoints;
use HCO\REST\Endpoints\GitHub_Endpoints;

final class REST_Controller {

    private static ?REST_Controller $instance = null;
    public static function get_instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function register(): void {
        ( new Category_Endpoints() )->register();
        ( new AI_Endpoints() )->register();
        ( new Bulk_Endpoints() )->register();
        ( new Settings_Endpoints() )->register();
        ( new Analytics_Endpoints() )->register();
        ( new GitHub_Endpoints() )->register();
    }

    public static function permission_manage(): bool {
        return current_user_can( 'manage_categories' );
    }

    public static function permission_admin(): bool {
        return current_user_can( 'manage_options' );
    }
}
