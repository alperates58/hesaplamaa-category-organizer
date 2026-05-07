<?php
namespace HCO\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

class OpenAI_Provider extends Abstract_Provider {

    public function get_name(): string { return 'OpenAI'; }

    public function get_model(): string {
        $settings = get_option( 'hco_settings', [] );
        return $settings['openai_model'] ?? 'o4-mini';
    }

    public function is_configured(): bool {
        return ! empty( $this->get_api_key() );
    }

    protected function get_api_key(): string {
        $settings = get_option( 'hco_settings', [] );
        return $settings['openai_api_key'] ?? '';
    }

    protected function get_endpoint(): string {
        return 'https://api.openai.com/v1/chat/completions';
    }

    protected function get_headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->get_api_key(),
            'Content-Type'  => 'application/json',
        ];
    }

    protected function get_cost_per_1k_input(): float  { return 0.00015; }
    protected function get_cost_per_1k_output(): float { return 0.0006; }

    public function analyze_post( array $post_data, array $taxonomy ): array {
        $cache_key = 'post_' . md5( serialize( $post_data ) . serialize( array_column( $taxonomy, 'id' ) ) );
        $cached    = $this->get_cached_result( $cache_key );
        if ( $cached ) return $cached;

        $taxonomy_ctx = $this->build_hierarchical_taxonomy_context( $taxonomy );
        $post_ctx     = $this->build_post_context( $post_data );

        $system_prompt = <<<PROMPT
You are a senior SEO strategist specializing in Turkish content websites.
Your ONLY job is to assign each WordPress post to the BEST MATCHING category that ALREADY EXISTS in the provided taxonomy.
CRITICAL RULES:
- You MUST select ONLY from the IDs and names shown in the taxonomy. Never invent new names or IDs.
- parent_category.id MUST be a top-level (▸) ID from the list.
- subcategory.id MUST be a child (└) ID listed under that parent.
- Never set needs_new_category to true.
Always output ONLY valid JSON — no explanation outside the JSON.
PROMPT;

        $user_prompt = <<<PROMPT
Assign this post to the most appropriate EXISTING category.

## Available Taxonomy (use ONLY these IDs and names):
{$taxonomy_ctx}

## Post:
{$post_ctx}

## Output JSON (all IDs must come from the taxonomy above):
{
  "parent_category": { "id": <existing_top_level_id>, "name": "<exact_name>" },
  "subcategory":     { "id": <existing_child_id>,     "name": "<exact_name>", "slug": "<slug>" },
  "confidence": <0-100>,
  "seo_intent": "Informational|Calculation|Transactional|Navigational|Commercial",
  "needs_new_category": false,
  "reasoning": "<max 150 chars>",
  "seo_notes": "<max 100 chars>"
}
PROMPT;

        $response = $this->make_request( [
            'model'       => $this->get_model(),
            'messages'    => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
            'temperature'    => 0.1,
            'response_format' => [ 'type' => 'json_object' ],
        ] );

        $content = $response['choices'][0]['message']['content'] ?? '{}';
        $result  = $this->parse_json_response( $content );
        $result['tokens_used'] = $this->token_usage['total'];

        $this->cache_result( $cache_key, $result );
        return $result;
    }

    public function detect_missing_categories( array $posts, array $taxonomy ): array {
        $taxonomy_ctx = $this->build_taxonomy_context( $taxonomy );
        $posts_ctx    = '';
        foreach ( array_slice( $posts, 0, 30 ) as $i => $p ) {
            $posts_ctx .= ( $i + 1 ) . ". " . $this->build_post_context( $p ) . "\n---\n";
        }

        $system_prompt = "You are an SEO taxonomy architect. Output ONLY valid JSON arrays.";

        $user_prompt = <<<PROMPT
Review these posts and the existing taxonomy. Identify categories that SHOULD exist but don't.

## Existing Taxonomy:
{$taxonomy_ctx}

## Posts sample:
{$posts_ctx}

## Output: JSON array of missing categories:
[
  {
    "category_name": "string",
    "parent_name": "string",
    "slug": "string (SEO-friendly, lowercase, hyphens)",
    "seo_title": "string",
    "description": "string",
    "reason": "string (why this category should exist)",
    "post_count_estimate": number,
    "confidence": number
  }
]
PROMPT;

        $response = $this->make_request( [
            'model'       => $this->get_model(),
            'messages'    => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
            'temperature'    => 0.2,
            'response_format' => [ 'type' => 'json_object' ],
        ] );

        $content = $response['choices'][0]['message']['content'] ?? '{"suggestions":[]}';
        $data    = $this->parse_json_response( $content );
        return $data['suggestions'] ?? $data ?? [];
    }

    public function detect_seo_clusters( array $posts, array $taxonomy ): array {
        $posts_ctx = '';
        foreach ( array_slice( $posts, 0, 50 ) as $i => $p ) {
            $posts_ctx .= ( $i + 1 ) . ". Title: " . ( $p['title'] ?? '' ) . " | Slug: " . ( $p['slug'] ?? '' ) . "\n";
        }

        $taxonomy_ctx = $this->build_taxonomy_context( $taxonomy );

        $system_prompt = "You are an SEO topical authority specialist. Output ONLY valid JSON.";
        $user_prompt = <<<PROMPT
Analyze these posts and detect topical SEO clusters (content silos).

## Posts:
{$posts_ctx}

## Existing Taxonomy:
{$taxonomy_ctx}

## Output JSON:
{
  "clusters": [
    {
      "cluster_name": "string",
      "cluster_slug": "string",
      "pillar_topic": "string",
      "suggested_subcategories": ["string"],
      "matching_post_indices": [number],
      "internal_linking_note": "string",
      "confidence": number
    }
  ]
}
PROMPT;

        $response = $this->make_request( [
            'model'       => $this->get_model(),
            'messages'    => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
            'temperature'    => 0.3,
            'response_format' => [ 'type' => 'json_object' ],
        ] );

        $content = $response['choices'][0]['message']['content'] ?? '{"clusters":[]}';
        $data    = $this->parse_json_response( $content );
        return $data['clusters'] ?? [];
    }

    public function detect_duplicate_intent( array $taxonomy ): array {
        $taxonomy_ctx = $this->build_taxonomy_context( $taxonomy );

        $system_prompt = "You are an SEO deduplication specialist. Output ONLY valid JSON.";
        $user_prompt = <<<PROMPT
Analyze this taxonomy and detect categories with duplicate or overlapping search intent.

## Taxonomy:
{$taxonomy_ctx}

## Output JSON:
{
  "duplicate_groups": [
    {
      "group_name": "string",
      "categories": [{ "id": number, "name": "string" }],
      "overlap_reason": "string",
      "recommendation": "merge|redirect|canonical",
      "suggested_canonical": "string",
      "confidence": number
    }
  ]
}
PROMPT;

        $response = $this->make_request( [
            'model'       => $this->get_model(),
            'messages'    => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
            'temperature'    => 0.1,
            'response_format' => [ 'type' => 'json_object' ],
        ] );

        $content = $response['choices'][0]['message']['content'] ?? '{"duplicate_groups":[]}';
        $data    = $this->parse_json_response( $content );
        return $data['duplicate_groups'] ?? [];
    }
}
