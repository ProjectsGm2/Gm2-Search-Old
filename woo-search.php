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
 * Add JOINs for _price, _sku, and aggregated product attributes.
 * Unique alias names are used to avoid conflicts.
 */
function woo_search_opt_joins( $join, $wp_query ) {
    global $wpdb;
    
    $search_term = $wp_query->get('s');
    if ( empty( $search_term ) ) {
        return $join;
    }
    
    // Only modify queries for products.
    $post_types = $wp_query->get('post_type');
    if ( (is_array($post_types) && ! in_array('product', $post_types)) || 
         (!is_array($post_types) && 'product' !== $post_types) ) {
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
    
    $search_term = $wp_query->get('s');
    if ( empty( $search_term ) ) {
        return $search;
    }
    
    // Only affect product queries.
    $post_types = $wp_query->get('post_type');
    if ( (is_array($post_types) && ! in_array('product', $post_types)) ||
         (!is_array($post_types) && 'product' !== $post_types) ) {
        return $search;
    }
    
    // Prepare search patterns.
    $like = '%' . $wpdb->esc_like( $search_term ) . '%';
    $price_stripped = str_replace( '$', '', $search_term );
    $price_like_stripped = '%' . $wpdb->esc_like( $price_stripped ) . '%';
    
    // Build our custom search conditions.
    $custom_search = "(
         {$wpdb->posts}.post_title LIKE '{$like}'
         OR {$wpdb->posts}.post_content LIKE '{$like}'
         OR (woo_pm_price.meta_value LIKE '{$like}' OR woo_pm_price.meta_value LIKE '{$price_like_stripped}')
         OR (woo_attr.attributes LIKE '{$like}')
         OR (woo_pm_sku.meta_value LIKE '{$like}')
    )";
    
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
    
    $search_term = $wp_query->get('s');
    if ( empty( $search_term ) ) {
        return $fields;
    }
    
    $like = '%' . $wpdb->esc_like( $search_term ) . '%';
    $price_stripped = str_replace( '$', '', $search_term );
    $price_like_stripped = '%' . $wpdb->esc_like( $price_stripped ) . '%';
    
    $relevance = "(";
    $relevance .= " (CASE WHEN {$wpdb->posts}.post_title LIKE '{$like}' THEN 100 ELSE 0 END) + ";
    $relevance .= " (CASE WHEN (woo_pm_price.meta_value LIKE '{$like}' OR woo_pm_price.meta_value LIKE '{$price_like_stripped}') THEN 90 ELSE 0 END) + ";
    $relevance .= " (CASE WHEN {$wpdb->posts}.post_content LIKE '{$like}' THEN 80 ELSE 0 END) + ";
    $relevance .= " (CASE WHEN woo_attr.attributes LIKE '{$like}' THEN 70 ELSE 0 END) + ";
    $relevance .= " (CASE WHEN woo_pm_sku.meta_value LIKE '{$like}' THEN 60 ELSE 0 END)";
    $relevance .= ") AS relevance";
    
    $fields .= ", " . $relevance;
    return $fields;
}
add_filter('posts_fields', 'woo_search_opt_relevance', 20, 2);

/**
 * Order results by computed relevance (highest first) and then by post title.
 */
function woo_search_opt_orderby( $orderby, $wp_query ) {
    global $wpdb;
    
    $search_term = $wp_query->get('s');
    if ( empty( $search_term ) ) {
        return $orderby;
    }
    
    $orderby = "relevance DESC, {$wpdb->posts}.post_title ASC";
    return $orderby;
}
add_filter('posts_orderby', 'woo_search_opt_orderby', 20, 2);

/**
 * Group by post ID to ensure each product appears only once.
 */
function woo_search_opt_groupby( $groupby, $wp_query ) {
    global $wpdb;
    $groupby = "{$wpdb->posts}.ID";
    return $groupby;
}
add_filter('posts_groupby', 'woo_search_opt_groupby', 20, 2);
