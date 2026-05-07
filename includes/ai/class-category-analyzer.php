<?php
namespace HCO\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\Database\DB_Manager;

class Category_Analyzer {

    private AI_Provider_Interface $provider;
    private DB_Manager            $db;

    public function __construct( ?AI_Provider_Interface $provider = null ) {
        $this->provider = $provider ?? AI_Provider_Factory::make();
        $this->db       = DB_Manager::get_instance();
    }

    public function get_flat_taxonomy( int $blog_id = 0 ): array {
        if ( $blog_id && is_multisite() ) {
            switch_to_blog( $blog_id );
        }

        $terms = get_terms( [
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'number'     => 0,
        ] );

        if ( $blog_id && is_multisite() ) {
            restore_current_blog();
        }

        if ( is_wp_error( $terms ) ) return [];

        $tax = [];
        foreach ( $terms as $term ) {
            $parent_name = '';
            if ( $term->parent ) {
                $parent = get_term( $term->parent, 'category' );
                if ( ! is_wp_error( $parent ) ) $parent_name = $parent->name;
            }
            $tax[] = [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'parent'      => $term->parent,
                'parent_name' => $parent_name,
                'count'       => $term->count,
            ];
        }

        return $tax;
    }

    public function build_post_data( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) return [];

        $data = [
            'id'      => $post->ID,
            'title'   => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'slug'    => $post->post_name,
            'tags'    => wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] ),
            'categories' => wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] ),
        ];

        // Yoast SEO
        $data['seo_title']       = get_post_meta( $post->ID, '_yoast_wpseo_title', true )
                                   ?: get_post_meta( $post->ID, 'rank_math_title', true )
                                   ?: '';
        $data['seo_description'] = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
                                   ?: get_post_meta( $post->ID, 'rank_math_description', true )
                                   ?: '';

        return $data;
    }

    public function analyze_single_post( int $post_id ): array {
        $post_data = $this->build_post_data( $post_id );
        if ( empty( $post_data ) ) {
            return [ 'error' => 'Post not found' ];
        }

        $taxonomy = $this->get_flat_taxonomy();
        $result   = $this->provider->analyze_post( $post_data, $taxonomy );

        $settings = get_option( 'hco_settings', [] );
        $threshold = absint( $settings['confidence_threshold'] ?? 75 );
        $require_approval = (bool) ( $settings['require_approval'] ?? true );

        $suggestion_data = [
            'post_id'               => $post_id,
            'suggested_cat_name'    => $result['subcategory']['name'] ?? '',
            'suggested_cat_slug'    => $result['subcategory']['slug'] ?? '',
            'suggested_parent_name' => $result['parent_category']['name'] ?? '',
            'confidence'            => $result['confidence'] ?? 0,
            'seo_intent'            => $result['seo_intent'] ?? '',
            'ai_reasoning'          => $result['reasoning'] ?? '',
            'ai_provider'           => $this->provider->get_name(),
            'ai_model'              => $this->provider->get_model(),
            'token_used'            => $result['tokens_used'] ?? 0,
            'status'                => 'pending',
            'type'                  => $result['needs_new_category'] ?? false ? 'new_category' : 'categorize',
        ];

        // Set IDs if existing categories matched
        if ( ! empty( $result['subcategory']['id'] ) ) {
            $suggestion_data['suggested_cat_id'] = $result['subcategory']['id'];
        }
        if ( ! empty( $result['parent_category']['id'] ) ) {
            $suggestion_data['suggested_parent_id'] = $result['parent_category']['id'];
        }

        $current_cats = wp_get_post_categories( $post_id );
        if ( ! empty( $current_cats ) ) {
            $suggestion_data['current_cat_id'] = $current_cats[0];
        }

        $suggestion_id = $this->db->insert_suggestion( $suggestion_data );

        $this->db->log_audit( 'suggestion_created', 'post', $post_id, null, $suggestion_data );

        if ( ! $require_approval && $result['confidence'] >= $threshold && ! ( $result['needs_new_category'] ?? false ) ) {
            $this->apply_suggestion( $suggestion_id );
        }

        return array_merge( $result, [
            'suggestion_id' => $suggestion_id,
            'auto_applied'  => ! $require_approval && $result['confidence'] >= $threshold,
        ] );
    }

    public function apply_suggestion( int $suggestion_id ): bool {
        $suggestion = $this->db->get_suggestion( $suggestion_id );
        if ( ! $suggestion ) return false;

        $post_id = (int) $suggestion['post_id'];
        $before  = wp_get_post_categories( $post_id );

        // Find or create the category
        $cat_id = (int) ( $suggestion['suggested_cat_id'] ?? 0 );

        if ( ! $cat_id && ! empty( $suggestion['suggested_cat_name'] ) ) {
            $parent_id = $this->find_or_create_parent( $suggestion['suggested_parent_name'] ?? '' );

            $existing = get_term_by( 'slug', $suggestion['suggested_cat_slug'], 'category' );
            if ( $existing ) {
                $cat_id = $existing->term_id;
            } else {
                $term = wp_insert_term( $suggestion['suggested_cat_name'], 'category', [
                    'slug'   => $suggestion['suggested_cat_slug'],
                    'parent' => $parent_id,
                ] );
                if ( is_wp_error( $term ) ) return false;
                $cat_id = $term['term_id'];
            }
        }

        if ( ! $cat_id ) return false;

        $result = wp_set_post_categories( $post_id, [ $cat_id ] );
        if ( is_wp_error( $result ) ) return false;

        $this->db->update_suggestion( $suggestion_id, [
            'status'            => 'applied',
            'suggested_cat_id'  => $cat_id,
            'applied_at'        => current_time( 'mysql' ),
        ] );

        $this->db->log_audit( 'suggestion_applied', 'post', $post_id, $before, [ $cat_id ] );
        return true;
    }

    public function reject_suggestion( int $suggestion_id ): bool {
        $suggestion = $this->db->get_suggestion( $suggestion_id );
        if ( ! $suggestion ) return false;

        $this->db->update_suggestion( $suggestion_id, [
            'status'      => 'rejected',
            'reviewed_at' => current_time( 'mysql' ),
            'reviewed_by' => get_current_user_id(),
        ] );

        $this->db->log_audit( 'suggestion_rejected', 'suggestion', $suggestion_id );
        return true;
    }

    public function rollback_suggestion( int $suggestion_id ): bool {
        $suggestion = $this->db->get_suggestion( $suggestion_id );
        if ( ! $suggestion || $suggestion['status'] !== 'applied' ) return false;

        $post_id    = (int) $suggestion['post_id'];
        $old_cat_id = (int) ( $suggestion['current_cat_id'] ?? 0 );

        if ( $old_cat_id ) {
            wp_set_post_categories( $post_id, [ $old_cat_id ] );
        }

        $this->db->update_suggestion( $suggestion_id, [ 'status' => 'rolled_back' ] );
        $this->db->log_audit( 'suggestion_rolled_back', 'post', $post_id );
        return true;
    }

    private function find_or_create_parent( string $name ): int {
        if ( empty( $name ) ) return 0;

        $existing = get_term_by( 'name', $name, 'category' );
        if ( $existing ) return $existing->term_id;

        $slug = sanitize_title( $name );
        $term = wp_insert_term( $name, 'category', [ 'slug' => $slug ] );
        return is_wp_error( $term ) ? 0 : $term['term_id'];
    }
}
