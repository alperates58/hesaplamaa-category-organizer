<?php
namespace HCO\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

class DeepSeek_Provider extends Abstract_Provider {

    public function get_name(): string { return 'DeepSeek'; }

    public function get_model(): string {
        $settings = get_option( 'hco_settings', [] );
        return $settings['deepseek_model'] ?? 'deepseek-chat';
    }

    public function is_configured(): bool {
        return ! empty( $this->get_api_key() );
    }

    protected function get_api_key(): string {
        $settings = get_option( 'hco_settings', [] );
        return $settings['deepseek_api_key'] ?? '';
    }

    protected function get_endpoint(): string {
        return 'https://api.deepseek.com/chat/completions';
    }

    protected function get_headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->get_api_key(),
            'Content-Type'  => 'application/json',
        ];
    }

    protected function get_cost_per_1k_input(): float  { return 0.00014; }
    protected function get_cost_per_1k_output(): float { return 0.00028; }

    public function analyze_post( array $post_data, array $taxonomy ): array {
        $cache_key = 'ds_post_' . md5( serialize( $post_data ) . serialize( array_column( $taxonomy, 'id' ) ) );
        $cached    = $this->get_cached_result( $cache_key );
        if ( $cached ) return $cached;

        $taxonomy_ctx = $this->build_taxonomy_context( $taxonomy );
        $post_ctx     = $this->build_post_context( $post_data );

        $response = $this->make_request( [
            'model'    => $this->get_model(),
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You are a senior SEO strategist and taxonomy architect. Always respond with ONLY valid JSON.',
                ],
                [
                    'role'    => 'user',
                    'content' => "Analyze this post and return the best category assignment as JSON.\n\nExisting taxonomy:\n{$taxonomy_ctx}\n\nPost:\n{$post_ctx}\n\nJSON schema:\n{\"parent_category\":{\"id\":null,\"name\":\"string\",\"is_new\":false},\"subcategory\":{\"id\":null,\"name\":\"string\",\"slug\":\"string\",\"is_new\":false},\"confidence\":0,\"seo_intent\":\"string\",\"needs_new_category\":false,\"reasoning\":\"string\",\"seo_notes\":\"string\"}",
                ],
            ],
            'temperature'     => 0.1,
            'response_format' => [ 'type' => 'json_object' ],
            'max_tokens'      => 800,
        ] );

        $content = $response['choices'][0]['message']['content'] ?? '{}';
        $result  = $this->parse_json_response( $content );
        $result['tokens_used'] = $this->token_usage['total'];

        $this->cache_result( $cache_key, $result );
        return $result;
    }

    public function detect_missing_categories( array $posts, array $taxonomy ): array {
        $taxonomy_ctx = $this->build_taxonomy_context( $taxonomy );
        $titles = array_map( fn($p) => $p['title'] ?? '', array_slice( $posts, 0, 40 ) );
        $posts_ctx = implode( "\n", array_map( fn($i,$t) => ($i+1).". $t", array_keys($titles), $titles ) );

        $response = $this->make_request( [
            'model'    => $this->get_model(),
            'messages' => [
                [ 'role' => 'system', 'content' => 'You are an SEO taxonomy architect. Output ONLY valid JSON.' ],
                [ 'role' => 'user',   'content' => "Find missing categories.\n\nTaxonomy:\n{$taxonomy_ctx}\n\nPost titles:\n{$posts_ctx}\n\nReturn JSON: {\"suggestions\":[{\"category_name\":\"str\",\"parent_name\":\"str\",\"slug\":\"str\",\"seo_title\":\"str\",\"description\":\"str\",\"reason\":\"str\",\"confidence\":0}]}" ],
            ],
            'temperature'     => 0.2,
            'response_format' => [ 'type' => 'json_object' ],
            'max_tokens'      => 1500,
        ] );

        $content = $response['choices'][0]['message']['content'] ?? '{"suggestions":[]}';
        $data    = $this->parse_json_response( $content );
        return $data['suggestions'] ?? [];
    }

    public function detect_seo_clusters( array $posts, array $taxonomy ): array {
        $titles   = array_map( fn($p) => $p['title'] ?? '', array_slice( $posts, 0, 60 ) );
        $post_ctx = implode( "\n", array_map( fn($i,$t) => ($i+1).". $t", array_keys($titles), $titles ) );

        $response = $this->make_request( [
            'model'    => $this->get_model(),
            'messages' => [
                [ 'role' => 'system', 'content' => 'SEO topical authority expert. Output ONLY valid JSON.' ],
                [ 'role' => 'user',   'content' => "Detect topical clusters.\n\nPosts:\n{$post_ctx}\n\nReturn JSON: {\"clusters\":[{\"cluster_name\":\"str\",\"cluster_slug\":\"str\",\"pillar_topic\":\"str\",\"suggested_subcategories\":[\"str\"],\"confidence\":0}]}" ],
            ],
            'temperature'     => 0.3,
            'response_format' => [ 'type' => 'json_object' ],
            'max_tokens'      => 1200,
        ] );

        $content = $response['choices'][0]['message']['content'] ?? '{"clusters":[]}';
        $data    = $this->parse_json_response( $content );
        return $data['clusters'] ?? [];
    }

    public function detect_duplicate_intent( array $taxonomy ): array {
        $taxonomy_ctx = $this->build_taxonomy_context( $taxonomy );

        $response = $this->make_request( [
            'model'    => $this->get_model(),
            'messages' => [
                [ 'role' => 'system', 'content' => 'SEO deduplication specialist. Output ONLY valid JSON.' ],
                [ 'role' => 'user',   'content' => "Detect duplicate intent categories.\n\nTaxonomy:\n{$taxonomy_ctx}\n\nReturn JSON: {\"duplicate_groups\":[{\"group_name\":\"str\",\"categories\":[{\"id\":0,\"name\":\"str\"}],\"overlap_reason\":\"str\",\"recommendation\":\"merge\",\"suggested_canonical\":\"str\",\"confidence\":0}]}" ],
            ],
            'temperature'     => 0.1,
            'response_format' => [ 'type' => 'json_object' ],
            'max_tokens'      => 1000,
        ] );

        $content = $response['choices'][0]['message']['content'] ?? '{"duplicate_groups":[]}';
        $data    = $this->parse_json_response( $content );
        return $data['duplicate_groups'] ?? [];
    }
}
