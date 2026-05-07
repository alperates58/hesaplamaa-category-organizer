<?php
namespace HCO\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AI_Provider_Factory {

    private static array $providers = [];

    public static function make( string $provider = '' ): AI_Provider_Interface {
        if ( empty( $provider ) ) {
            $settings = get_option( 'hco_settings', [] );
            $provider = $settings['ai_provider'] ?? 'openai';
        }

        if ( isset( self::$providers[ $provider ] ) ) {
            return self::$providers[ $provider ];
        }

        $instance = match( $provider ) {
            'deepseek' => new DeepSeek_Provider(),
            default    => new OpenAI_Provider(),
        };

        self::$providers[ $provider ] = $instance;
        return $instance;
    }

    public static function get_available_providers(): array {
        $settings = get_option( 'hco_settings', [] );
        $providers = [];

        $openai = new OpenAI_Provider();
        $providers[] = [
            'id'           => 'openai',
            'name'         => $openai->get_name(),
            'model'        => $openai->get_model(),
            'is_configured' => $openai->is_configured(),
            'cost_input'    => 0.00015,
            'cost_output'   => 0.0006,
        ];

        $deepseek = new DeepSeek_Provider();
        $providers[] = [
            'id'           => 'deepseek',
            'name'         => $deepseek->get_name(),
            'model'        => $deepseek->get_model(),
            'is_configured' => $deepseek->is_configured(),
            'cost_input'    => 0.00014,
            'cost_output'   => 0.00028,
        ];

        return $providers;
    }
}
