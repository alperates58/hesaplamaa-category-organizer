<?php
namespace HCO\REST\Endpoints;

if ( ! defined( 'ABSPATH' ) ) exit;

use HCO\REST\REST_Controller;

class Category_Endpoints {

    private const NS = 'hco/v1';

    public function register(): void {
        register_rest_route( self::NS, '/categories', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_categories' ],
                'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
            ],
        ] );

        register_rest_route( self::NS, '/categories/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_category' ],
                'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn($v) => is_numeric($v) ] ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_category' ],
                'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_category' ],
                'permission_callback' => [ REST_Controller::class, 'permission_admin' ],
            ],
        ] );

        register_rest_route( self::NS, '/categories/tree', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_category_tree' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );

        register_rest_route( self::NS, '/categories/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_stats' ],
            'permission_callback' => [ REST_Controller::class, 'permission_manage' ],
        ] );
    }

    public function get_categories( \WP_REST_Request $request ): \WP_REST_Response {
        $terms = get_terms( [
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'number'     => 0,
        ] );

        if ( is_wp_error( $terms ) ) {
            return new \WP_REST_Response( [ 'error' => $terms->get_error_message() ], 500 );
        }

        $data = array_map( [ $this, 'format_term' ], $terms );
        return new \WP_REST_Response( $data, 200 );
    }

    public function get_category( \WP_REST_Request $request ): \WP_REST_Response {
        $term = get_term( (int) $request['id'], 'category' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new \WP_REST_Response( [ 'error' => 'Category not found' ], 404 );
        }
        return new \WP_REST_Response( $this->format_term( $term ), 200 );
    }

    public function update_category( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request['id'];
        $body = $request->get_json_params();

        $args = [];
        if ( isset( $body['name'] ) )        $args['name']   = sanitize_text_field( $body['name'] );
        if ( isset( $body['slug'] ) )        $args['slug']   = sanitize_title( $body['slug'] );
        if ( isset( $body['description'] ) ) $args['description'] = wp_kses_post( $body['description'] );
        if ( isset( $body['parent'] ) )      $args['parent'] = absint( $body['parent'] );

        $result = wp_update_term( $id, 'category', $args );
        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
        }

        return new \WP_REST_Response( $this->format_term( get_term( $id, 'category' ) ), 200 );
    }

    public function delete_category( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request['id'];
        $result = wp_delete_term( $id, 'category' );
        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
        }
        return new \WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    public function get_category_tree( \WP_REST_Request $request ): \WP_REST_Response {
        $terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 0 ] );
        if ( is_wp_error( $terms ) ) return new \WP_REST_Response( [], 200 );

        $all = [];
        foreach ( $terms as $term ) {
            $all[ $term->term_id ] = array_merge( $this->format_term( $term ), [ 'children' => [] ] );
        }

        $tree = [];
        foreach ( $all as $id => &$cat ) {
            if ( $cat['parent'] && isset( $all[ $cat['parent'] ] ) ) {
                $all[ $cat['parent'] ]['children'][] = &$cat;
            } else {
                $tree[] = &$cat;
            }
        }
        unset( $cat );

        return new \WP_REST_Response( $tree, 200 );
    }

    public function get_stats( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $total_cats = wp_count_terms( 'category', [ 'hide_empty' => false ] );
        $empty_cats = wp_count_terms( 'category', [ 'hide_empty' => true ] );

        $uncategorized_id  = get_option( 'default_category', 1 );
        $uncategorized_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tt.term_id = %d AND tt.taxonomy = 'category'",
                $uncategorized_id
            )
        );

        return new \WP_REST_Response( [
            'total_categories'    => (int) $total_cats,
            'empty_categories'    => (int) ( $total_cats - ( is_wp_error($empty_cats) ? 0 : $empty_cats ) ),
            'uncategorized_posts' => $uncategorized_count,
            'max_depth'           => $this->get_max_depth(),
        ], 200 );
    }

    private function get_max_depth(): int {
        $terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] );
        if ( is_wp_error($terms) ) return 0;
        $max = 0;
        foreach ( $terms as $term ) {
            $depth = 0;
            $p = $term->parent;
            while ( $p ) {
                $depth++;
                $parent = get_term( $p, 'category' );
                $p = ( $parent && ! is_wp_error($parent) ) ? $parent->parent : 0;
            }
            $max = max( $max, $depth );
        }
        return $max;
    }

    private function format_term( \WP_Term $term ): array {
        return [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'parent'      => $term->parent,
            'count'       => $term->count,
            'link'        => get_term_link( $term ),
        ];
    }
}
