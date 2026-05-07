<?php
namespace HCO\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\Database\DB_Manager;

abstract class Abstract_Provider implements AI_Provider_Interface {

    protected array $token_usage = [ 'input' => 0, 'output' => 0, 'total' => 0 ];
    protected int   $request_count = 0;
    protected float $cost = 0.0;

    abstract protected function get_api_key(): string;
    abstract protected function get_endpoint(): string;
    abstract protected function get_cost_per_1k_input(): float;
    abstract protected function get_cost_per_1k_output(): float;

    public function get_token_usage(): array {
        return $this->token_usage;
    }

    public function get_cost_estimate(): float {
        return round(
            ( $this->token_usage['input']  / 1000 * $this->get_cost_per_1k_input() ) +
            ( $this->token_usage['output'] / 1000 * $this->get_cost_per_1k_output() ),
            6
        );
    }

    protected function build_taxonomy_context( array $taxonomy ): string {
        $lines = [];
        foreach ( $taxonomy as $cat ) {
            $parent = $cat['parent_name'] ? " (parent: {$cat['parent_name']})" : ' (top-level)';
            $lines[] = "- ID:{$cat['id']} | {$cat['name']}{$parent} | slug:{$cat['slug']} | posts:{$cat['count']}";
        }
        return implode( "\n", $lines );
    }

    /**
     * Hierarchical view: parents with their children listed beneath.
     * Used in analyze_post prompts so the AI sees exact valid ID/name pairs.
     */
    protected function build_hierarchical_taxonomy_context( array $taxonomy ): string {
        $parents  = [];
        $children = [];

        foreach ( $taxonomy as $cat ) {
            if ( ! $cat['parent'] ) {
                $parents[ $cat['id'] ] = $cat;
            } else {
                $children[ $cat['parent'] ][] = $cat;
            }
        }

        $lines = [];
        foreach ( $parents as $pid => $p ) {
            $lines[] = "▸ [{$p['id']}] {$p['name']}";
            foreach ( $children[ $pid ] ?? [] as $c ) {
                $lines[] = "   └ [{$c['id']}] {$c['name']} (slug: {$c['slug']})";
            }
        }
        return implode( "\n", $lines );
    }

    protected function build_post_context( array $post ): string {
        $parts = [];
        if ( ! empty( $post['title'] ) )           $parts[] = "Title: {$post['title']}";
        if ( ! empty( $post['slug'] ) )             $parts[] = "Slug: {$post['slug']}";
        if ( ! empty( $post['excerpt'] ) )          $parts[] = "Excerpt: " . wp_strip_all_tags( $post['excerpt'] );
        if ( ! empty( $post['seo_title'] ) )        $parts[] = "SEO Title: {$post['seo_title']}";
        if ( ! empty( $post['seo_description'] ) )  $parts[] = "SEO Description: {$post['seo_description']}";
        if ( ! empty( $post['tags'] ) )             $parts[] = "Tags: " . implode( ', ', (array) $post['tags'] );
        if ( ! empty( $post['content'] ) ) {
            $stripped = wp_strip_all_tags( $post['content'] );
            $parts[] = "Content (excerpt): " . mb_substr( $stripped, 0, 600 );
        }
        return implode( "\n", $parts );
    }

    protected function make_request( array $payload, int $retries = 3 ): array {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            throw new \RuntimeException( 'AI provider API key not configured.' );
        }

        $last_error = null;
        $delay_ms   = 1000;

        for ( $attempt = 0; $attempt < $retries; $attempt++ ) {
            if ( $attempt > 0 ) {
                usleep( $delay_ms * 1000 );
                $delay_ms *= 2;
            }

            $response = wp_remote_post( $this->get_endpoint(), [
                'timeout' => 60,
                'headers' => $this->get_headers(),
                'body'    => wp_json_encode( $payload ),
            ] );

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $code === 429 ) {
                // Rate limit — back off longer
                usleep( 5000 * 1000 );
                continue;
            }

            if ( $code >= 400 ) {
                $last_error = $body['error']['message'] ?? "HTTP $code";
                continue;
            }

            if ( isset( $body['usage'] ) ) {
                $usage = $body['usage'];
                $this->token_usage['input']  += $usage['prompt_tokens']     ?? $usage['input_tokens']  ?? 0;
                $this->token_usage['output'] += $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0;
                $this->token_usage['total']  = $this->token_usage['input'] + $this->token_usage['output'];
            }

            $this->request_count++;
            return $body;
        }

        throw new \RuntimeException( "AI request failed after {$retries} attempts: {$last_error}" );
    }

    protected function parse_json_response( string $content ): array {
        // Strip markdown code fences if present
        $content = preg_replace( '/^```(?:json)?\s*/m', '', $content );
        $content = preg_replace( '/\s*```$/m', '', $content );
        $content = trim( $content );

        $data = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new \RuntimeException( 'Failed to parse AI JSON response: ' . json_last_error_msg() );
        }
        return $data;
    }

    protected function get_cached_result( string $cache_key ): mixed {
        $settings = get_option( 'hco_settings', [] );
        $ttl      = absint( $settings['cache_ttl'] ?? 86400 );
        if ( $ttl === 0 ) return null;
        return DB_Manager::get_instance()->get_ai_cache( $cache_key );
    }

    protected function cache_result( string $cache_key, mixed $value ): void {
        $settings = get_option( 'hco_settings', [] );
        $ttl      = absint( $settings['cache_ttl'] ?? 86400 );
        if ( $ttl === 0 ) return;
        DB_Manager::get_instance()->set_ai_cache( $cache_key, $value, $ttl );
    }
}
