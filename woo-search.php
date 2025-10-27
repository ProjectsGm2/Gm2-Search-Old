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

if ( ! function_exists( 'woo_search_opt_log' ) ) {
    function woo_search_opt_log( $message, $context = array() ) {
        if ( ! defined( 'WOO_SEARCH_OPT_DEBUG' ) || ! WOO_SEARCH_OPT_DEBUG ) {
            return;
        }

        $log_message = $message;

        if ( ! empty( $context ) ) {
            $encoded_context = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

            if ( false === $encoded_context || null === $encoded_context ) {
                $encoded_context = print_r( $context, true );
            }

            $log_message .= ' ' . $encoded_context;
        }

        error_log( $log_message );
    }
}

if ( ! function_exists( 'woo_search_opt_log_query' ) ) {
    function woo_search_opt_log_query( WP_Query $query ) {
        $is_admin_request     = is_admin();
        $is_elementor_ajax    = isset( $_REQUEST['elementor_ajax'] );
        $is_standard_wp_ajax  = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();

        if ( $is_admin_request && ! ( $is_standard_wp_ajax || $is_elementor_ajax ) ) {
            woo_search_opt_log( 'woo_search_opt pre_get_posts skipped admin request', array(
                'is_admin' => $is_admin_request,
                'is_standard_wp_ajax' => $is_standard_wp_ajax,
                'is_elementor_ajax' => $is_elementor_ajax,
            ) );
            return;
        }

        $post_type = $query->get( 'post_type' );
        $has_product_post_type = ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) || ( 'product' === $post_type );

        $tax_query = $query->get( 'tax_query' );
        $has_product_visibility_tax = false;

        if ( is_array( $tax_query ) ) {
            $stack = $tax_query;

            while ( ! empty( $stack ) ) {
                $item = array_pop( $stack );

                if ( ! is_array( $item ) ) {
                    continue;
                }

                if ( isset( $item['taxonomy'] ) && 'product_visibility' === $item['taxonomy'] ) {
                    $has_product_visibility_tax = true;
                    break;
                }

                foreach ( $item as $value ) {
                    if ( is_array( $value ) ) {
                        $stack[] = $value;
                    }
                }
            }
        }

        if ( ! $has_product_post_type && ! $has_product_visibility_tax ) {
            woo_search_opt_log( 'woo_search_opt pre_get_posts skipped non-product query', array(
                'post_type' => $post_type,
                'tax_query' => $tax_query,
            ) );
            return;
        }

        $context = array(
            'is_main_query' => $query->is_main_query(),
            'is_admin' => $is_admin_request,
            'post_type' => $post_type,
            'query_s' => $query->get( 's' ),
            'get_s' => isset( $_GET['s'] ) ? wp_unslash( $_GET['s'] ) : null,
            'request_s' => isset( $_REQUEST['s'] ) ? wp_unslash( $_REQUEST['s'] ) : null,
            'query_orderby' => $query->get( 'orderby' ),
            'get_orderby' => isset( $_GET['orderby'] ) ? wp_unslash( $_GET['orderby'] ) : null,
            'woo_search_opt_active' => $query->get( 'woo_search_opt_active' ),
            'request_elementor_ajax' => $is_elementor_ajax ? wp_unslash( $_REQUEST['elementor_ajax'] ) : null,
            'request_action' => isset( $_REQUEST['action'] ) ? wp_unslash( $_REQUEST['action'] ) : null,
        );

        woo_search_opt_log( 'woo_search_opt pre_get_posts', $context );
    }
}
add_action( 'pre_get_posts', 'woo_search_opt_log_query', 19, 1 );

/**
 * Add JOINs for _price, _sku, and aggregated product attributes.
 * Unique alias names are used to avoid conflicts.
 */
function woo_search_opt_joins( $join, $wp_query ) {
    global $wpdb;

    $search_term = $wp_query->get('s');
    if ( empty( $search_term ) ) {
        woo_search_opt_log( 'woo_search_opt_joins skipped empty search', array(
            'is_main_query' => $wp_query->is_main_query(),
            'post_type' => $wp_query->get( 'post_type' ),
        ) );
        return $join;
    }

    // Only modify queries for products.
    $post_types = $wp_query->get('post_type');
    if ( (is_array($post_types) && ! in_array('product', $post_types)) ||
         (!is_array($post_types) && 'product' !== $post_types) ) {
        woo_search_opt_log( 'woo_search_opt_joins skipped non-product query', array(
            'post_type' => $post_types,
        ) );
        return $join;
    }

    woo_search_opt_log( 'woo_search_opt_joins start', array(
        'search_term' => $search_term,
        'is_main_query' => $wp_query->is_main_query(),
    ) );
    
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

    $search_term = $wp_query->get('s');
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';
    woo_search_opt_log( 'woo_search_opt_posts_search', array(
        'search_phrase' => $search_phrase,
        'orderby' => $wp_query->get( 'orderby' ),
        'is_main_query' => $wp_query->is_main_query(),
        'initial_search_clause' => $search,
    ) );
    if ( '' === $search_phrase ) {
        woo_search_opt_log( 'woo_search_opt_posts_search skipped empty search', array(
            'search_term' => $search_term,
        ) );
        return $search;
    }

    // Only affect product queries.
    $post_types = $wp_query->get('post_type');
    if ( (is_array($post_types) && ! in_array('product', $post_types)) ||
         (!is_array($post_types) && 'product' !== $post_types) ) {
        woo_search_opt_log( 'woo_search_opt_posts_search skipped non-product query', array(
            'post_type' => $post_types,
        ) );
        return $search;
    }

    $tokenized_phrase = preg_split( '/\s+/', $search_phrase );
    if ( ! is_array( $tokenized_phrase ) ) {
        $tokenized_phrase = array( $search_phrase );
    }

    $tokens = array_filter( array_map( 'trim', $tokenized_phrase ), 'strlen' );
    if ( empty( $tokens ) ) {
        woo_search_opt_log( 'woo_search_opt_posts_search no valid tokens', array(
            'tokenized_phrase' => $tokenized_phrase,
        ) );
        return $search;
    }

    woo_search_opt_log( 'woo_search_opt_posts_search tokenized', array(
        'tokens' => $tokens,
    ) );

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

    woo_search_opt_log( 'woo_search_opt_posts_search custom clause built', array(
        'custom_search' => $custom_search,
    ) );

    // Combine with the default search conditions.
    if ( ! empty( $search ) ) {
        // Remove any leading "AND" from the default search clause.
        $search = preg_replace('/^\s*AND\s*/', '', $search);
        $search = " AND ( ($search) OR $custom_search ) ";
    } else {
        $search = " AND ( $custom_search ) ";
    }

    woo_search_opt_log( 'woo_search_opt_posts_search final clause', array(
        'final_search_clause' => $search,
    ) );

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

    $search_term = $wp_query->get('s');
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';
    woo_search_opt_log( 'woo_search_opt_relevance', array(
        'search_phrase' => $search_phrase,
        'orderby' => $wp_query->get( 'orderby' ),
        'is_main_query' => $wp_query->is_main_query(),
        'initial_fields' => $fields,
    ) );
    if ( '' === $search_phrase ) {
        woo_search_opt_log( 'woo_search_opt_relevance skipped empty search', array(
            'search_term' => $search_term,
        ) );
        return $fields;
    }

    $phrase_like = '%' . $wpdb->esc_like( $search_phrase ) . '%';
    $phrase_like_escaped = esc_sql( $phrase_like );

    $tokenized_phrase = preg_split( '/\s+/', $search_phrase );
    if ( ! is_array( $tokenized_phrase ) ) {
        $tokenized_phrase = array( $search_phrase );
    }

    $tokens = array_filter( array_map( 'trim', $tokenized_phrase ), 'strlen' );

    woo_search_opt_log( 'woo_search_opt_relevance tokens processed', array(
        'tokens' => $tokens,
    ) );

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

    woo_search_opt_log( 'woo_search_opt_relevance final fields', array(
        'final_fields' => $fields,
    ) );

    return $fields;
}
add_filter('posts_fields', 'woo_search_opt_relevance', 20, 2);

/**
 * Order results by exact phrase, token score, overall relevance, and post title.
 */
function woo_search_opt_orderby( $orderby, $wp_query ) {
    global $wpdb;

    $search_term = $wp_query->get('s');
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';
    woo_search_opt_log( 'woo_search_opt_orderby', array(
        'search_phrase' => $search_phrase,
        'orderby' => $wp_query->get( 'orderby' ),
        'is_main_query' => $wp_query->is_main_query(),
    ) );
    if ( '' === $search_phrase ) {
        woo_search_opt_log( 'woo_search_opt_orderby skipped empty search', array(
            'search_term' => $search_term,
        ) );
        return $orderby;
    }

    $orderby = "title_exact_phrase DESC, title_ordered_phrase DESC, title_all_tokens DESC, title_token_hits DESC, attr_all_tokens DESC, content_all_tokens DESC, overall_token_hits DESC, token_score DESC, relevance DESC, {$wpdb->posts}.post_title ASC";
    woo_search_opt_log( 'woo_search_opt_orderby final', array(
        'orderby_clause' => $orderby,
    ) );
    return $orderby;
}
add_filter('posts_orderby', 'woo_search_opt_orderby', 20, 2);

/**
 * Group by post ID to ensure each product appears only once.
 */
function woo_search_opt_groupby( $groupby, $wp_query ) {
    global $wpdb;
    $search_term = $wp_query->get('s');
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';
    woo_search_opt_log( 'woo_search_opt_groupby', array(
        'search_phrase' => $search_phrase,
        'orderby' => $wp_query->get( 'orderby' ),
        'is_main_query' => $wp_query->is_main_query(),
        'initial_groupby' => $groupby,
    ) );
    woo_search_opt_log( 'woo_search_opt_groupby final', array(
        'groupby_clause' => "{$wpdb->posts}.ID",
    ) );
    $groupby = "{$wpdb->posts}.ID";
    return $groupby;
}
add_filter('posts_groupby', 'woo_search_opt_groupby', 20, 2);
