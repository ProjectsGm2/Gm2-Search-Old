<?php
/**
 * Plugin Name: Woo Search Optimized
 * Plugin URI:  https://gm2web.com/
 * Description: Optimized WooCommerce product search to include product title, price, description, attributes, and SKU with weighted ranking.
 * Version:     1.8.1
 * Author:      Your Name
 * Author URI:  https://gm2web.com/
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retrieve the active search phrase for the main WooCommerce product query.
 *
 * Prefers the custom woo_search_phrase query var, falling back to the standard
 * search query var. Returns an empty string when no phrase is available.
 *
 * @param WP_Query|null $query Optional query instance.
 * @return string
 */
function woo_search_opt_get_search_phrase( $query = null ) {
    global $wp_query;

    if ( ! class_exists( 'WP_Query' ) ) {
        return '';
    }

    $active_query = ( $query instanceof WP_Query ) ? $query : $wp_query;
    if ( ! $active_query instanceof WP_Query ) {
        return '';
    }

    $phrase = $active_query->get( 'woo_search_phrase' );
    if ( ! is_string( $phrase ) ) {
        $phrase = $active_query->get( 's' );
    }

    return is_string( $phrase ) ? trim( $phrase ) : '';
}

/**
 * Determine whether the given query is the main WooCommerce product search query.
 *
 * @param WP_Query $wp_query Query instance.
 * @return bool
 */
function woo_search_opt_is_main_product_query( $wp_query ) {
    if ( ! class_exists( 'WP_Query' ) ) {
        return false;
    }

    if ( ! $wp_query instanceof WP_Query || ! $wp_query->is_main_query() ) {
        return false;
    }

    $post_types = $wp_query->get( 'post_type' );
    if ( is_array( $post_types ) ) {
        return in_array( 'product', $post_types, true );
    }

    if ( 'product' === $post_types ) {
        return true;
    }

    if ( empty( $post_types ) ) {
        $query_var = isset( $wp_query->query_vars['post_type'] ) ? $wp_query->query_vars['post_type'] : null;
        if ( is_array( $query_var ) ) {
            return in_array( 'product', $query_var, true );
        }

        if ( 'product' === $query_var ) {
            return true;
        }
    }

    return false;
}

/**
 * Preserve the raw search phrase for the main WooCommerce product query.
 *
 * @param WP_Query $query Query instance.
 */
function woo_search_opt_pre_get_posts( $query ) {
    if ( ! class_exists( 'WP_Query' ) ) {
        return;
    }

    if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
        return;
    }

    if ( ! woo_search_opt_is_main_product_query( $query ) ) {
        return;
    }

    if ( ! isset( $_GET['s'] ) ) {
        return;
    }

    $raw_value = wp_unslash( $_GET['s'] );
    if ( ! is_string( $raw_value ) ) {
        return;
    }

    $raw_search = $raw_value;
    if ( '' === trim( $raw_search ) ) {
        return;
    }

    $query->set( 's', $raw_search );
    $query->set( 'woo_search_phrase', $raw_search );
}
add_action( 'pre_get_posts', 'woo_search_opt_pre_get_posts' );

/**
 * Add JOINs for _price, _sku, and aggregated product attributes.
 * Unique alias names are used to avoid conflicts.
 */
function woo_search_opt_joins( $join, $wp_query ) {
    global $wpdb;

    if ( ! woo_search_opt_is_main_product_query( $wp_query ) ) {
        return $join;
    }

    $search_phrase = woo_search_opt_get_search_phrase( $wp_query );
    if ( '' === $search_phrase ) {
        return $join;
    }

    // Join postmeta for price and SKU.
    $join .= " LEFT JOIN {$wpdb->postmeta} AS woo_pm_price ON ({$wpdb->posts}.ID = woo_pm_price.post_id AND woo_pm_price.meta_key = '_price') ";
    $join .= " LEFT JOIN {$wpdb->postmeta} AS woo_pm_sku ON ({$wpdb->posts}.ID = woo_pm_sku.post_id AND woo_pm_sku.meta_key = '_sku') ";
    
    // Join a subquery to aggregate attribute names (from taxonomies starting with 'pa_').
    $join .= " LEFT JOIN (
                SELECT tr.object_id, GROUP_CONCAT(t.name SEPARATOR ' ') AS attributes
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                WHERE tt.taxonomy LIKE 'pa\\_%'
                GROUP BY tr.object_id
               ) AS woo_attr ON woo_attr.object_id = {$wpdb->posts}.ID ";
    
    return $join;
}
add_filter('posts_join', 'woo_search_opt_joins', 20, 2);

/**
 * Use the posts_search filter to add custom search conditions.
 * This will combine the default search conditions with our optimized conditions.
 */
function woo_search_opt_posts_search( $search, $wp_query ) {
    global $wpdb;

    if ( ! woo_search_opt_is_main_product_query( $wp_query ) ) {
        return $search;
    }

    $search_phrase = woo_search_opt_get_search_phrase( $wp_query );
    if ( '' === $search_phrase ) {
        return $search;
    }

    $tokenized_phrase = preg_split( '/\s+/', $search_phrase );
    if ( ! is_array( $tokenized_phrase ) ) {
        $tokenized_phrase = array( $search_phrase );
    }

    $tokens = array_filter( array_map( 'trim', $tokenized_phrase ), 'strlen' );
    if ( empty( $tokens ) ) {
        return $search;
    }

    $token_clauses = array();
    foreach ( $tokens as $token_original ) {
        $token_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $token_original, 'UTF-8' ) : strtolower( $token_original );
        $token_like = '%' . $wpdb->esc_like( $token_lower ) . '%';
        $token_like_escaped = esc_sql( $token_like );

        $token_price = str_replace( '$', '', $token_lower );
        $token_price_like = '%' . $wpdb->esc_like( $token_price ) . '%';
        $token_price_like_escaped = esc_sql( $token_price_like );

        $token_clauses[] = "("
            . "LOWER({$wpdb->posts}.post_title) LIKE '{$token_like_escaped}'"
            . " OR LOWER({$wpdb->posts}.post_content) LIKE '{$token_like_escaped}'"
            . " OR (LOWER(COALESCE(woo_pm_price.meta_value, '')) LIKE '{$token_like_escaped}'"
                . " OR LOWER(REPLACE(COALESCE(woo_pm_price.meta_value, ''), '$', '')) LIKE '{$token_price_like_escaped}')"
            . " OR LOWER(COALESCE(woo_attr.attributes, '')) LIKE '{$token_like_escaped}'"
            . " OR LOWER(COALESCE(woo_pm_sku.meta_value, '')) LIKE '{$token_like_escaped}'"
        . ")";
    }

    $custom_search = '( ' . implode( ' AND ', $token_clauses ) . ' )';

    // Combine with the default search conditions.
    if ( ! empty( $search ) ) {
        // Remove any leading "AND" from the default search clause.
        $search = preg_replace('/^\s*AND\s*/', '', $search);
        $search = " AND ( ($search) OR $custom_search ) ";
    } else {
        $search = " AND ( $custom_search ) ";
    }

    return $search;
}
add_filter('posts_search', 'woo_search_opt_posts_search', 20, 2);

/**
 * Add a computed "relevance" field to the SELECT clause.
 * The relevance is weighted as follows:
 *   - Title: 100
 *   - Price: 90
 *   - Description: 80
 *   - Attributes: 70
 *   - SKU: 60
 */
function woo_search_opt_relevance( $fields, $wp_query ) {
    global $wpdb;

    if ( ! woo_search_opt_is_main_product_query( $wp_query ) ) {
        return $fields;
    }

    $search_phrase = woo_search_opt_get_search_phrase( $wp_query );
    if ( '' === $search_phrase ) {
        return $fields;
    }

    $phrase_like = '%' . $wpdb->esc_like( $search_phrase ) . '%';
    $phrase_like_escaped = esc_sql( $phrase_like );

    $tokenized_phrase = preg_split( '/\s+/', $search_phrase );
    if ( ! is_array( $tokenized_phrase ) ) {
        $tokenized_phrase = array( $search_phrase );
    }

    $tokens = array_filter( array_map( 'trim', $tokenized_phrase ), 'strlen' );

    $title_exact_phrase_sql = "(CASE WHEN {$wpdb->posts}.post_title LIKE '{$phrase_like_escaped}' THEN 1 ELSE 0 END) AS title_exact_phrase";
    $exact_match_sql = "(CASE WHEN ( {$wpdb->posts}.post_title LIKE '{$phrase_like_escaped}' OR {$wpdb->posts}.post_content LIKE '{$phrase_like_escaped}' ) THEN 1 ELSE 0 END) AS exact_match";

    $token_score_parts = array();
    $relevance_parts = array();
    $title_token_hit_parts = array();
    $attr_token_hit_parts = array();
    $content_token_hit_parts = array();
    $overall_token_hit_parts = array();
    $ordered_regex_parts = array();

    $seen_tokens = array();

    foreach ( $tokens as $token_original ) {
        $token_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $token_original, 'UTF-8' ) : strtolower( $token_original );
        if ( isset( $seen_tokens[ $token_lower ] ) ) {
            continue;
        }
        $seen_tokens[ $token_lower ] = true;
        $token_like = '%' . $wpdb->esc_like( $token_lower ) . '%';
        $token_like_escaped = esc_sql( $token_like );

        $token_price = str_replace( '$', '', $token_lower );
        $token_price_like = '%' . $wpdb->esc_like( $token_price ) . '%';
        $token_price_like_escaped = esc_sql( $token_price_like );

        $title_match_case = "CASE WHEN LOWER({$wpdb->posts}.post_title) LIKE '{$token_like_escaped}' THEN 1 ELSE 0 END";
        $attr_match_case = "CASE WHEN LOWER(COALESCE(woo_attr.attributes, '')) LIKE '{$token_like_escaped}' THEN 1 ELSE 0 END";
        $content_match_case = "CASE WHEN LOWER({$wpdb->posts}.post_content) LIKE '{$token_like_escaped}' THEN 1 ELSE 0 END";
        $title_token_hit_parts[] = $title_match_case;
        $attr_token_hit_parts[] = $attr_match_case;
        $content_token_hit_parts[] = $content_match_case;

        $overall_condition = "("
            . "LOWER({$wpdb->posts}.post_title) LIKE '{$token_like_escaped}'"
            . " OR LOWER({$wpdb->posts}.post_content) LIKE '{$token_like_escaped}'"
            . " OR LOWER(COALESCE(woo_attr.attributes, '')) LIKE '{$token_like_escaped}'"
            . " OR LOWER(COALESCE(woo_pm_sku.meta_value, '')) LIKE '{$token_like_escaped}'"
            . " OR LOWER(COALESCE(woo_pm_price.meta_value, '')) LIKE '{$token_like_escaped}'"
            . " OR LOWER(REPLACE(COALESCE(woo_pm_price.meta_value, ''), '$', '')) LIKE '{$token_price_like_escaped}'"
        . ")";
        $overall_token_hit_parts[] = "CASE WHEN {$overall_condition} THEN 1 ELSE 0 END";

        $token_score_cases = array(
            "CASE WHEN LOWER({$wpdb->posts}.post_title) LIKE '{$token_like_escaped}' THEN 10 ELSE 0 END",
            "CASE WHEN LOWER(COALESCE(woo_pm_sku.meta_value, '')) LIKE '{$token_like_escaped}' THEN 8 ELSE 0 END",
            "CASE WHEN LOWER(COALESCE(woo_attr.attributes, '')) LIKE '{$token_like_escaped}' THEN 6 ELSE 0 END",
            "CASE WHEN LOWER({$wpdb->posts}.post_content) LIKE '{$token_like_escaped}' THEN 5 ELSE 0 END",
            "CASE WHEN (LOWER(COALESCE(woo_pm_price.meta_value, '')) LIKE '{$token_like_escaped}'"
                . " OR LOWER(REPLACE(COALESCE(woo_pm_price.meta_value, ''), '$', '')) LIKE '{$token_price_like_escaped}') THEN 4 ELSE 0 END",
        );

        $token_score_parts[] = 'GREATEST(' . implode( ', ', $token_score_cases ) . ', 0)';

        $relevance_parts[] = "(CASE WHEN LOWER({$wpdb->posts}.post_title) LIKE '{$token_like_escaped}' THEN 100 ELSE 0 END)";
        $relevance_parts[] = "(CASE WHEN (LOWER(COALESCE(woo_pm_price.meta_value, '')) LIKE '{$token_like_escaped}'"
            . " OR LOWER(REPLACE(COALESCE(woo_pm_price.meta_value, ''), '$', '')) LIKE '{$token_price_like_escaped}') THEN 90 ELSE 0 END)";
        $relevance_parts[] = "(CASE WHEN LOWER({$wpdb->posts}.post_content) LIKE '{$token_like_escaped}' THEN 80 ELSE 0 END)";
        $relevance_parts[] = "(CASE WHEN LOWER(COALESCE(woo_attr.attributes, '')) LIKE '{$token_like_escaped}' THEN 70 ELSE 0 END)";
        $relevance_parts[] = "(CASE WHEN LOWER(COALESCE(woo_pm_sku.meta_value, '')) LIKE '{$token_like_escaped}' THEN 60 ELSE 0 END)";

        $ordered_regex_parts[] = preg_quote( $token_lower, '/' );
    }

    $token_count = count( $seen_tokens );

    $token_score_sql = empty( $token_score_parts ) ? '0 AS token_score' : '( ' . implode( ' + ', $token_score_parts ) . ' ) AS token_score';
    $relevance_sql = empty( $relevance_parts ) ? '0' : '( ' . implode( ' + ', $relevance_parts ) . ' )';

    $title_token_hits_expr = empty( $title_token_hit_parts ) ? '0' : '( ' . implode( ' + ', $title_token_hit_parts ) . ' )';
    $attr_token_hits_expr = empty( $attr_token_hit_parts ) ? '0' : '( ' . implode( ' + ', $attr_token_hit_parts ) . ' )';
    $content_token_hits_expr = empty( $content_token_hit_parts ) ? '0' : '( ' . implode( ' + ', $content_token_hit_parts ) . ' )';
    $overall_token_hits_expr = empty( $overall_token_hit_parts ) ? '0' : '( ' . implode( ' + ', $overall_token_hit_parts ) . ' )';

    $title_all_tokens_expr = ( 0 === $token_count ) ? '0' : '(CASE WHEN ' . $title_token_hits_expr . ' >= ' . $token_count . ' THEN 1 ELSE 0 END)';
    $attr_all_tokens_expr = ( 0 === $token_count ) ? '0' : '(CASE WHEN ' . $attr_token_hits_expr . ' >= ' . $token_count . ' THEN 1 ELSE 0 END)';
    $content_all_tokens_expr = ( 0 === $token_count ) ? '0' : '(CASE WHEN ' . $content_token_hits_expr . ' >= ' . $token_count . ' THEN 1 ELSE 0 END)';

    $title_ordered_phrase_expr = '0';
    if ( ! empty( $ordered_regex_parts ) ) {
        $ordered_regex_pattern = implode( '.*', $ordered_regex_parts );
        $ordered_regex_escaped = esc_sql( $ordered_regex_pattern );
        $title_ordered_phrase_expr = "(CASE WHEN {$wpdb->posts}.post_title REGEXP '{$ordered_regex_escaped}' THEN 1 ELSE 0 END)";
    }

    $search_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search_phrase, 'UTF-8' ) : strtolower( $search_phrase );
    $search_lower_escaped = esc_sql( $search_lower );
    $universal_penalty_sql = "CASE WHEN LOCATE('universal', '{$search_lower_escaped}') = 0"
        . " AND ("
            . "LOWER({$wpdb->posts}.post_title) LIKE '%universal%'"
            . " OR LOWER({$wpdb->posts}.post_content) LIKE '%universal%'"
            . " OR LOWER(COALESCE(woo_attr.attributes, '')) LIKE '%universal%'"
            . " OR LOWER(COALESCE(woo_pm_sku.meta_value, '')) LIKE '%universal%'"
        . ") THEN 20 ELSE 0 END";

    $fields .= ', ' . $title_exact_phrase_sql;
    $fields .= ', ' . $exact_match_sql;
    $fields .= ', ' . $title_token_hits_expr . ' AS title_token_hits';
    $fields .= ', ' . $attr_token_hits_expr . ' AS attr_token_hits';
    $fields .= ', ' . $content_token_hits_expr . ' AS content_token_hits';
    $fields .= ', ' . $overall_token_hits_expr . ' AS overall_token_hits';
    $fields .= ', ' . $title_all_tokens_expr . ' AS title_all_tokens';
    $fields .= ', ' . $attr_all_tokens_expr . ' AS attr_all_tokens';
    $fields .= ', ' . $content_all_tokens_expr . ' AS content_all_tokens';
    $fields .= ', ' . $title_ordered_phrase_expr . ' AS title_ordered_phrase';
    $fields .= ', ' . $token_score_sql;
    $fields .= ', (' . $universal_penalty_sql . ') AS universal_penalty';
    $fields .= ', (' . $relevance_sql . ' - (' . $universal_penalty_sql . ')) AS relevance';

    return $fields;
}
add_filter('posts_fields', 'woo_search_opt_relevance', 20, 2);

/**
 * Order results by exact phrase, token score, overall relevance, and post title.
 */
function woo_search_opt_orderby( $orderby, $wp_query ) {
    global $wpdb;

    if ( ! woo_search_opt_is_main_product_query( $wp_query ) ) {
        return $orderby;
    }

    $search_phrase = woo_search_opt_get_search_phrase( $wp_query );
    if ( '' === $search_phrase ) {
        return $orderby;
    }

    $explicit_orderby = '';

    if ( isset( $_GET['orderby'] ) ) {
        $orderby_request = wp_unslash( $_GET['orderby'] );
        if ( is_string( $orderby_request ) ) {
            $explicit_orderby = trim( $orderby_request );
        }
    }

    if ( '' === $explicit_orderby ) {
        $query_orderby = $wp_query->get( 'orderby' );
        if ( is_string( $query_orderby ) ) {
            $explicit_orderby = trim( $query_orderby );
        }
    }

    if ( '' !== $explicit_orderby && 'relevance' !== $explicit_orderby ) {
        return $orderby;
    }

    $orderby = "title_exact_phrase DESC, title_ordered_phrase DESC, title_all_tokens DESC, title_token_hits DESC, attr_all_tokens DESC, content_all_tokens DESC, overall_token_hits DESC, token_score DESC, relevance DESC, {$wpdb->posts}.post_title ASC";
    return $orderby;
}
add_filter('posts_orderby', 'woo_search_opt_orderby', 20, 2);

/**
 * Group by post ID to ensure each product appears only once.
 */
function woo_search_opt_groupby( $groupby, $wp_query ) {
    global $wpdb;

    if ( ! woo_search_opt_is_main_product_query( $wp_query ) ) {
        return $groupby;
    }

    $search_phrase = woo_search_opt_get_search_phrase( $wp_query );
    if ( '' === $search_phrase ) {
        return $groupby;
    }

    return "{$wpdb->posts}.ID";
}
add_filter('posts_groupby', 'woo_search_opt_groupby', 20, 2);
