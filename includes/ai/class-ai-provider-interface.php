<?php
namespace HCO\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

interface AI_Provider_Interface {

    public function get_name(): string;

    public function get_model(): string;

    public function is_configured(): bool;

    /**
     * Analyze a post and return category suggestion.
     *
     * @param array $post_data { title, content, excerpt, slug, tags, seo_title, seo_description }
     * @param array $taxonomy  Existing categories flat list
     * @return array { parent_category, subcategory, confidence, seo_intent, reasoning, tokens_used }
     */
    public function analyze_post( array $post_data, array $taxonomy ): array;

    /**
     * Detect missing categories from a batch of posts.
     *
     * @param array $posts     Array of post_data arrays
     * @param array $taxonomy  Existing categories
     * @return array[] Array of { category_name, parent_name, slug, seo_title, reason, confidence }
     */
    public function detect_missing_categories( array $posts, array $taxonomy ): array;

    /**
     * Detect SEO topical clusters.
     *
     * @param array $posts    Array of post_data arrays
     * @param array $taxonomy Existing categories
     * @return array[] Array of cluster objects
     */
    public function detect_seo_clusters( array $posts, array $taxonomy ): array;

    /**
     * Detect duplicate intent categories.
     *
     * @param array $taxonomy Existing categories
     * @return array[] Array of duplicate groups
     */
    public function detect_duplicate_intent( array $taxonomy ): array;

    public function get_token_usage(): array;
    public function get_cost_estimate(): float;
}
