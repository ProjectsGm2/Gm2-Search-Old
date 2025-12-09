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

// --- Robust file logger to uploads/woo-search.log ---
if ( ! function_exists( 'wso_log' ) ) {
    function wso_log( $msg, $ctx = array() ) {
        if ( ! defined( 'WOO_SEARCH_OPT_DEBUG' ) || ! WOO_SEARCH_OPT_DEBUG ) {
            return;
        }

        $prefix = '[woo-search] ';
        if ( ! empty( $ctx ) ) {
            $msg .= ' | ' . wp_json_encode( $ctx );
        }
        $line = gmdate( 'c' ) . ' ' . $prefix . $msg . PHP_EOL;

        if ( function_exists( 'wp_upload_dir' ) ) {
            $ud = wp_upload_dir();
            if ( ! empty( $ud['basedir'] ) && is_dir( $ud['basedir'] ) && is_writable( $ud['basedir'] ) ) {
                $file = trailingslashit( $ud['basedir'] ) . 'woo-search.log';
                @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
            }
        }

        error_log( $line );
    }
}

if ( ! function_exists( 'woo_search_opt_log' ) ) {
    function woo_search_opt_log( $msg, $ctx = array() ) {
        if ( function_exists( 'wso_log' ) ) {
            wso_log( $msg, $ctx );
            return;
        }

        if ( ! empty( $ctx ) ) {
            $msg .= ' | ' . wp_json_encode( $ctx );
        }
        error_log( '[woo-search] ' . $msg );
    }
}

// ---- Logger shim (uses existing logger if available) ----
if ( ! function_exists( 'woo_search_opt_debug' ) ) {
    function woo_search_opt_debug( $msg, $ctx = array() ) {
        // Prefer existing plugin logger if defined
        if ( function_exists( 'woo_search_opt_log' ) ) {
            woo_search_opt_log( $msg, $ctx );
            return;
        }
        // Fallback to error_log
        if ( ! empty( $ctx ) ) {
            $msg .= ' | ' . wp_json_encode( $ctx );
        }
        error_log( '[woo-search] ' . $msg );
    }
}

/**
 * Initialize the diagnostics logger and ensure the uploads directory exists.
 *
 * Logging is only enabled when the WOO_SEARCH_OPT_DEBUG constant is truthy.
 * When enabled, a JSON-lines log is written to wp-content/uploads/woo-search-optimized/woo-search.log.
 * To activate logging add the following to wp-config.php before WP loads plugins:
 *
 *     define( 'WOO_SEARCH_OPT_DEBUG', true );
 *
 * @return void
 */
function woo_search_opt_init_logger() {
    static $initialized = false;

    if ( $initialized ) {
        return;
    }

    if ( ! defined( 'WOO_SEARCH_OPT_DEBUG' ) || ! WOO_SEARCH_OPT_DEBUG ) {
        return;
    }

    $initialized = true;

    try {
        $upload_dir = wp_upload_dir();

        if ( empty( $upload_dir['basedir'] ) ) {
            throw new RuntimeException( 'Upload base directory is unavailable.' );
        }

        $target_dir = trailingslashit( $upload_dir['basedir'] ) . 'woo-search-optimized/';

        if ( ! wp_mkdir_p( $target_dir ) ) {
            throw new RuntimeException( 'Unable to create logger directory.' );
        }

        $log_file = $target_dir . 'woo-search.log';

        if ( ! file_exists( $log_file ) ) {
            if ( false === @touch( $log_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                throw new RuntimeException( 'Unable to create log file.' );
            }
        }

        @chmod( $log_file, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        $GLOBALS['woo_search_opt_log_file'] = $log_file;
    } catch ( Exception $e ) {
        error_log( 'Woo Search Optimized logger bootstrap failed: ' . $e->getMessage() );
        $GLOBALS['woo_search_opt_log_file_error'] = true;
    }
}
add_action( 'plugins_loaded', 'woo_search_opt_init_logger' );

// === Woo Search Optimized: force product search & default sort =================
add_action( 'pre_get_posts', function( $q ) {
    if ( is_admin() || ! ( $q instanceof WP_Query ) || ! $q->is_main_query() || ! $q->is_search() ) {
        return;
    }

    $post_type = $q->get( 'post_type' );
    if ( empty( $post_type ) || 'any' === $post_type ) {
        $q->set( 'post_type', 'product' );
        woo_search_opt_debug( 'pre_get_posts:set_post_type_product' );
    }

    if ( ! $q->get( 'wc_query' ) ) {
        $q->set( 'wc_query', 'product_query' );
        woo_search_opt_debug( 'pre_get_posts:set_wc_query_flag' );
    }

    $orderby = (string) $q->get( 'orderby' );
    if ( '' === $orderby ) {
        $q->set( 'orderby', 'relevance' );
        woo_search_opt_debug( 'pre_get_posts:set_default_orderby', array( 'orderby' => 'relevance' ) );
    } else {
        woo_search_opt_debug( 'pre_get_posts:keep_orderby', array( 'orderby' => $orderby ) );
    }
}, 1 );

add_filter( 'woocommerce_default_catalog_orderby', function( $default ) {
    if ( is_search() ) {
        woo_search_opt_debug( 'default_catalog_orderby:override_for_search', array( 'default' => 'relevance' ) );
        return 'relevance';
    }
    return $default;
}, 999 );

// ---- Stable ORDER BY for relevance ----
add_filter( 'posts_orderby', function( $orderby_sql, $query ) {
    if ( is_admin() || ! ( $query instanceof WP_Query ) || ! $query->is_main_query() || ! $query->is_search() ) {
        return $orderby_sql;
    }

    $pt      = $query->get( 'post_type' );
    $orderby = (string) $query->get( 'orderby' );

    if ( 'product' !== $pt ) {
        return $orderby_sql;
    }

    if ( 'relevance' !== $orderby && '' !== $orderby ) {
        return $orderby_sql;
    }

    global $wpdb;
    $s = (string) $query->get( 's' );

    if ( '' === $s ) {
        $stable = "{$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
        woo_search_opt_debug( 'posts_orderby:empty_search_fallback', array( 'orderby' => $stable ) );
        return $stable;
    }

    $base_orderby = trim( (string) $orderby_sql );

    if ( '' === $base_orderby ) {
        $like     = '%' . $wpdb->esc_like( $s ) . '%';
        $like_sql = esc_sql( $like );

        $score_sql =
            "( (CASE WHEN {$wpdb->posts}.post_title LIKE '{$like_sql}' THEN 2 ELSE 0 END) " .
            " + (CASE WHEN {$wpdb->posts}.post_content LIKE '{$like_sql}' THEN 1 ELSE 0 END) )";

        $base_orderby = "{$score_sql} DESC";
    }

    $final = $base_orderby;

    $tie_breakers = array(
        "{$wpdb->posts}.post_date DESC",
        "{$wpdb->posts}.ID DESC",
    );

    foreach ( $tie_breakers as $tie_breaker ) {
        if ( false === stripos( $final, $tie_breaker ) ) {
            $final .= ( '' === $final ? '' : ', ' ) . $tie_breaker;
        }
    }

    woo_search_opt_debug( 'posts_orderby:relevance', array( 'orderby' => $final ) );

    return $final;
}, 999, 2 );

/**
 * Sanitize scalar values for logging output.
 *
 * @param mixed $value Value to sanitize.
 *
 * @return mixed
 */
function woo_search_opt_sanitize_scalar_for_logging( $value ) {
    if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
        return $value;
    }

    if ( is_string( $value ) ) {
        return esc_html( wp_unslash( $value ) );
    }

    if ( null === $value ) {
        return null;
    }

    if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
        return esc_html( wp_unslash( (string) $value ) );
    }

    return $value;
}

/**
 * Recursively sanitize array data for inclusion in log payloads.
 *
 * @param array $value Array data to sanitize.
 *
 * @return array
 */
function woo_search_opt_sanitize_array_for_logging( $value ) {
    $sanitized = array();

    foreach ( (array) $value as $key => $item ) {
        if ( is_array( $item ) ) {
            $sanitized[ $key ] = woo_search_opt_sanitize_array_for_logging( $item );
        } else {
            $sanitized[ $key ] = woo_search_opt_sanitize_scalar_for_logging( $item );
        }
    }

    return $sanitized;
}

/**
 * Normalize context values for logging ensuring arrays and objects are serialized safely.
 *
 * @param mixed $value Value to normalize.
 * @param int   $depth Current recursion depth to prevent runaway structures.
 *
 * @return mixed
 */
function woo_search_opt_normalize_context_value( $value, $depth = 0 ) {
    if ( $depth > 4 ) {
        return 'depth_limit_reached';
    }

    if ( is_array( $value ) ) {
        $normalized = array();
        foreach ( $value as $key => $item ) {
            $normalized[ $key ] = woo_search_opt_normalize_context_value( $item, $depth + 1 );
        }
        return $normalized;
    }

    if ( $value instanceof WP_Query ) {
        return woo_search_opt_extract_query_flags( $value );
    }

    if ( $value instanceof WP_Post ) {
        return array(
            'ID'         => $value->ID,
            'post_type'  => $value->post_type,
            'post_name'  => $value->post_name,
            'post_title' => woo_search_opt_sanitize_scalar_for_logging( $value->post_title ),
        );
    }

    if ( is_object( $value ) ) {
        return array( 'object_class' => get_class( $value ) );
    }

    return woo_search_opt_sanitize_scalar_for_logging( $value );
}

/**
 * Normalize a token array for readable logging.
 *
 * @param array $tokens Token list.
 *
 * @return array
 */
function woo_search_opt_normalize_tokens( $tokens ) {
    $normalized = array();

    if ( ! is_array( $tokens ) ) {
        return array(
            'tokens'      => array(),
            'token_count' => 0,
            'truncated'   => false,
        );
    }

    foreach ( $tokens as $token ) {
        if ( ! is_scalar( $token ) ) {
            continue;
        }

        $token = trim( (string) $token );

        if ( '' === $token ) {
            continue;
        }

        $token_value = esc_html( wp_unslash( $token ) );

        if ( function_exists( 'mb_substr' ) ) {
            $token_value = mb_substr( $token_value, 0, 120, 'UTF-8' );
        } else {
            $token_value = substr( $token_value, 0, 120 );
        }

        $normalized[] = $token_value;
    }

    $limited = array_slice( $normalized, 0, 20 );

    return array(
        'tokens'      => $limited,
        'token_count' => count( $normalized ),
        'truncated'   => count( $normalized ) > count( $limited ),
    );
}

/**
 * Extract key flags and query vars from a WP_Query for logging.
 *
 * @param WP_Query $query Query instance.
 *
 * @return array
 */
function woo_search_opt_extract_query_flags( $query ) {
    if ( ! $query instanceof WP_Query ) {
        return array();
    }

    $vars = array();

    foreach ( $query->query_vars as $key => $value ) {
        if ( is_scalar( $value ) || null === $value ) {
            $vars[ $key ] = woo_search_opt_sanitize_scalar_for_logging( $value );
        } elseif ( is_array( $value ) ) {
            $vars[ $key ] = woo_search_opt_normalize_context_value( $value );
        }
    }

    $post_type = $query->get( 'post_type' );
    $orderby   = $query->get( 'orderby' );

    return array(
        'is_main_query' => $query->is_main_query(),
        'is_search'     => $query->is_search(),
        'is_admin'      => is_admin(),
        'post_type'     => woo_search_opt_normalize_context_value( $post_type ),
        'orderby'       => woo_search_opt_normalize_context_value( $orderby ),
        'vars'          => $vars,
    );
}

/**
 * Collect sanitized request variables for logging payloads.
 *
 * @param array|null $keys Optional list of keys to include. Defaults to entire request payload.
 *
 * @return array
 */
function woo_search_opt_collect_request_vars( $keys = null ) {
    $request = isset( $_REQUEST ) && is_array( $_REQUEST ) ? $_REQUEST : array();

    if ( empty( $request ) ) {
        return array();
    }

    $extra_keys = array( 'gm2_search_term' );

    if ( is_array( $keys ) ) {
        $whitelist = array_unique( array_merge( $keys, $extra_keys ) );
    } else {
        $whitelist = array_keys( $request );
    }

    $collected = array();

    foreach ( $whitelist as $key ) {
        if ( ! array_key_exists( $key, $request ) ) {
            continue;
        }

        $value = $request[ $key ];

        if ( is_array( $value ) ) {
            $collected[ $key ] = woo_search_opt_sanitize_array_for_logging( $value );
            continue;
        }

        $collected[ $key ] = woo_search_opt_sanitize_scalar_for_logging( $value );
    }

    return $collected;
}

/**
 * Normalize a request payload into an associative array for inspection.
 *
 * @param mixed $value Raw payload value from the request superglobal.
 *
 * @return array
 */
function woo_search_opt_normalize_request_payload_to_array( $value ) {
    if ( is_object( $value ) ) {
        $value = (array) $value;
    }

    if ( is_array( $value ) ) {
        return wp_unslash( $value );
    }

    if ( is_string( $value ) ) {
        $value = trim( wp_unslash( $value ) );

        if ( '' === $value ) {
            return array();
        }

        $decoded = json_decode( $value, true );

        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        $maybe_unserialized = maybe_unserialize( $value );

        if ( is_array( $maybe_unserialized ) ) {
            return $maybe_unserialized;
        }

        $parsed = array();
        parse_str( $value, $parsed );

        if ( is_array( $parsed ) ) {
            return $parsed;
        }
    }

    return array();
}

/**
 * Recursively search a payload for the first non-empty scalar value matching the provided keys.
 *
 * @param mixed $payload Payload data to inspect.
 * @param array $keys    Keys to inspect.
 * @param int   $depth   Current recursion depth.
 *
 * @return string
 */
function woo_search_opt_find_scalar_in_payload( $payload, array $keys, $depth = 0 ) {
    if ( $depth > 6 ) {
        return '';
    }

    if ( is_object( $payload ) ) {
        $payload = (array) $payload;
    }

    if ( ! is_array( $payload ) ) {
        return '';
    }

    foreach ( $keys as $key ) {
        if ( ! array_key_exists( $key, $payload ) ) {
            continue;
        }

        $value = $payload[ $key ];

        if ( is_array( $value ) || is_object( $value ) ) {
            $nested = woo_search_opt_find_search_term_in_payload( $value, $keys, $depth + 1 );

            if ( '' !== $nested ) {
                return $nested;
            }

            continue;
        }

        $value = sanitize_text_field( wp_unslash( $value ) );
        $value = trim( $value );

        if ( '' !== $value ) {
            return $value;
        }
    }

    foreach ( $payload as $value ) {
        if ( ! is_array( $value ) && ! is_object( $value ) ) {
            continue;
        }

        $nested = woo_search_opt_find_scalar_in_payload( $value, $keys, $depth + 1 );

        if ( '' !== $nested ) {
            return $nested;
        }
    }

    return '';
}

/**
 * Recursively search a payload for the first non-empty search phrase.
 *
 * @param mixed $payload Payload data to inspect.
 * @param array $keys    Search term keys to inspect.
 * @param int   $depth   Current recursion depth.
 *
 * @return string
 */
function woo_search_opt_find_search_term_in_payload( $payload, array $keys, $depth = 0 ) {
    return woo_search_opt_find_scalar_in_payload( $payload, $keys, $depth );
}

/**
 * Resolve the active search phrase for the current query or request context.
 *
 * @param WP_Query|null $wp_query Optional query instance to inspect.
 *
 * @return string
 */
function woo_search_opt_resolve_search_phrase( $wp_query = null ) {
    if ( $wp_query instanceof WP_Query ) {
        $search_term = $wp_query->get( 's' );

        if ( is_string( $search_term ) ) {
            $search_term = trim( $search_term );

            if ( '' !== $search_term ) {
                return $search_term;
            }
        }
    }

    $request_keys = array( 's', 'search', 'gm2_search_term', 'keyword' );

    foreach ( $request_keys as $key ) {
        if ( ! isset( $_REQUEST[ $key ] ) ) {
            continue;
        }

        $value = $_REQUEST[ $key ];

        if ( is_array( $value ) ) {
            continue;
        }

        $value = sanitize_text_field( wp_unslash( $value ) );
        $value = trim( $value );

        if ( '' !== $value ) {
            return $value;
        }
    }

    $payload_keys = array( 'query_vars', 'query', 'queryArgs', 'query_args', 'form_data', 'data' );

    foreach ( $payload_keys as $payload_key ) {
        if ( ! isset( $_REQUEST[ $payload_key ] ) ) {
            continue;
        }

        $payload = woo_search_opt_normalize_request_payload_to_array( $_REQUEST[ $payload_key ] );

        if ( empty( $payload ) ) {
            continue;
        }

        $value = woo_search_opt_find_search_term_in_payload( $payload, $request_keys );

        if ( '' !== $value ) {
            return $value;
        }
    }

    return '';
}

/**
 * Resolve the first scalar value matching the provided keys from the current request payload.
 *
 * @param array $keys Keys to inspect within the request payloads.
 *
 * @return string
 */
function woo_search_opt_resolve_request_scalar( array $keys ) {
    foreach ( $keys as $key ) {
        if ( ! isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            continue;
        }

        $value = $_REQUEST[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( is_array( $value ) || is_object( $value ) ) {
            $value = woo_search_opt_normalize_request_payload_to_array( $value );
            $value = woo_search_opt_find_scalar_in_payload( $value, $keys );
        } else {
            $value = sanitize_text_field( wp_unslash( $value ) );
            $value = trim( $value );
        }

        if ( is_string( $value ) && '' !== $value ) {
            return $value;
        }
    }

    $payload_keys = array( 'query_vars', 'query', 'queryArgs', 'query_args', 'form_data', 'data' );

    foreach ( $payload_keys as $payload_key ) {
        if ( ! isset( $_REQUEST[ $payload_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            continue;
        }

        $payload = woo_search_opt_normalize_request_payload_to_array( $_REQUEST[ $payload_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( empty( $payload ) ) {
            continue;
        }

        $value = woo_search_opt_find_scalar_in_payload( $payload, $keys );

        if ( '' !== $value ) {
            return $value;
        }
    }

    return '';
}

/**
 * Normalize incoming WooCommerce sort requests to a concise internal token.
 *
 * @param WP_Query $wp_query Query instance under inspection.
 *
 * @return string Normalized sort token or empty string when no explicit sort is detected.
 */
function woo_search_opt_normalize_orderby_token( $value ) {
    if ( ! is_string( $value ) ) {
        return '';
    }

    $value = strtolower( trim( $value ) );

    if ( '' === $value ) {
        return '';
    }

    $value = str_replace( array( '-', '.' ), '_', $value );

    return $value;
}

/**
 * Normalize ORDER direction tokens to `asc`/`desc`.
 *
 * @param mixed $value Incoming value.
 *
 * @return string
 */
function woo_search_opt_normalize_order_direction( $value ) {
    if ( ! is_string( $value ) ) {
        return '';
    }

    $value = strtolower( trim( $value ) );

    return in_array( $value, array( 'asc', 'desc' ), true ) ? $value : '';
}

/**
 * Append a normalized ORDER BY token to the collection ensuring uniqueness and first-position tracking.
 *
 * @param array  $tokens          Reference to the token collection.
 * @param string $token           Token to append.
 * @param string $primary_orderby Reference to the primary token holder.
 *
 * @return void
 */
function woo_search_opt_add_orderby_token( array &$tokens, $token, &$primary_orderby ) {
    if ( '' === $token ) {
        return;
    }

    if ( ! in_array( $token, $tokens, true ) ) {
        $tokens[] = $token;
    }

    if ( '' === $primary_orderby ) {
        $primary_orderby = $token;
    }
}

/**
 * Retrieve and normalize the requested WooCommerce orderby value.
 *
 * @return string Normalized request token or empty string when absent.
 */
function woo_search_opt_get_requested_orderby() {
    $requested = woo_search_opt_resolve_request_scalar( array( 'orderby' ) );

    if ( '' === $requested ) {
        return '';
    }

    $requested = strtolower( trim( $requested ) );

    if ( '' === $requested ) {
        return '';
    }

    $map = array(
        'menu_order' => 'menu_order',
        'menu-order' => 'menu_order',
        'popularity' => 'popularity',
        'rating'     => 'rating',
        'date'       => 'date',
        'post_date'  => 'date',
        'price'      => 'price',
        'price-asc'  => 'price',
        'price_asc'  => 'price',
        'price-desc' => 'price-desc',
        'price_desc' => 'price-desc',
        'rand'       => 'rand',
    );

    if ( array_key_exists( $requested, $map ) ) {
        return $map[ $requested ];
    }

    return $requested;
}

/**
 * Collect potential Elementor query identifiers from the current request/query context.
 *
 * @param WP_Query|null $query Optional query instance.
 *
 * @return array
 */
function woo_search_opt_collect_elementor_query_ids( $query = null ) {
    $query_ids       = array();
    $candidate_keys  = array( 'elementor_query_id', 'query_id', 'elementor_widget_id' );
    $additional_keys = array( 'elementor_page_id' );

    if ( $query instanceof WP_Query ) {
        foreach ( array_merge( $candidate_keys, $additional_keys ) as $key ) {
            $value = $query->get( $key );

            if ( is_string( $value ) && '' !== trim( $value ) ) {
                $query_ids[] = trim( $value );
            }
        }

        if ( isset( $query->query_vars ) && is_array( $query->query_vars ) ) {
            foreach ( array_merge( $candidate_keys, $additional_keys ) as $key ) {
                if ( isset( $query->query_vars[ $key ] ) && is_string( $query->query_vars[ $key ] ) ) {
                    $value = trim( $query->query_vars[ $key ] );

                    if ( '' !== $value ) {
                        $query_ids[] = $value;
                    }
                }
            }
        }
    }

    if ( function_exists( 'get_query_var' ) ) {
        foreach ( array_merge( $candidate_keys, $additional_keys ) as $key ) {
            $value = get_query_var( $key );

            if ( is_string( $value ) && '' !== trim( $value ) ) {
                $query_ids[] = trim( $value );
            }
        }
    }

    foreach ( $candidate_keys as $key ) {
        $value = woo_search_opt_resolve_request_scalar( array( $key ) );

        if ( '' !== $value ) {
            $query_ids[] = $value;
        }
    }

    $query_ids = array_filter( array_map( 'trim', $query_ids ) );

    return array_values( array_unique( $query_ids ) );
}

/**
 * Determine whether the current query likely originates from Elementor.
 *
 * @param WP_Query|null $query Optional query instance.
 *
 * @return bool
 */
function woo_search_opt_is_elementor_context( $query = null ) {
    if ( $query instanceof WP_Query ) {
        $elementor_keys = array( 'elementor_ajax', 'elementor_page', 'elementor_query_id', 'elementor_widget_id', 'elementor_page_id' );

        foreach ( $elementor_keys as $key ) {
            $value = $query->get( $key );

            if ( ! empty( $value ) ) {
                return true;
            }
        }
    }

    if ( isset( $_REQUEST['elementor_ajax'] ) || isset( $_REQUEST['elementor_page'] ) || isset( $_REQUEST['elementor_page_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return true;
    }

    return false;
}

/**
 * Compile the supported Elementor pagination keys derived from the request/query context.
 *
 * @param WP_Query|null $query Optional query instance.
 *
 * @return array
 */
function woo_search_opt_collect_elementor_pagination_keys( $query = null ) {
    $query_ids = woo_search_opt_collect_elementor_query_ids( $query );
    $keys      = array();

    foreach ( $query_ids as $query_id ) {
        $variants = array( $query_id );
        $variants[] = sanitize_key( $query_id );
        $variants[] = strtolower( $query_id );
        $variants[] = str_replace( '-', '_', $query_id );
        $variants[] = str_replace( '-', '_', sanitize_key( $query_id ) );

        foreach ( $variants as $variant ) {
            if ( ! is_string( $variant ) ) {
                continue;
            }

            $variant = trim( $variant );

            if ( '' === $variant ) {
                continue;
            }

            $variant = preg_replace( '/[^a-z0-9_\-]/i', '_', $variant );
            $variant = trim( $variant, '_' );

            if ( '' === $variant ) {
                continue;
            }

            $keys[] = $variant . '_page';
        }
    }

    return array_values( array_unique( $keys ) );
}

/**
 * Resolve the requested pagination page from the current request payloads.
 *
 * @param WP_Query|null $query Optional query instance.
 *
 * @return int
 */
function woo_search_opt_get_requested_paged( $query = null ) {
    $fallback_keys   = array( 'paged', 'page', 'current_page', 'product-page', 'elementor_page' );
    $resolved_key    = '';
    $value           = '';
    $elementor_keys  = woo_search_opt_collect_elementor_pagination_keys( $query );
    $candidate_keys  = array_merge( $fallback_keys, $elementor_keys );

    foreach ( $candidate_keys as $key ) {
        $candidate_value = woo_search_opt_resolve_request_scalar( array( $key ) );

        if ( '' !== $candidate_value ) {
            $resolved_key = $key;
            $value        = $candidate_value;
            break;
        }
    }

    if ( '' === $resolved_key ) {
        if ( ! empty( $elementor_keys ) ) {
            $resolved_key = $elementor_keys[0];
        } elseif ( woo_search_opt_is_elementor_context( $query ) ) {
            $resolved_key = 'product-page';
        } else {
            $resolved_key = 'paged';
        }
    }

    if ( $query instanceof WP_Query ) {
        $query->set( 'woo_search_opt_resolved_paged_var', $resolved_key );
    }

    if ( '' === $value ) {
        return 0;
    }

    if ( is_numeric( $value ) ) {
        $value = absint( $value );

        return ( $value > 0 ) ? $value : 0;
    }

    return 0;
}

/**
 * Normalize incoming WooCommerce sort requests to a concise internal token.
 *
 * @param WP_Query $wp_query Query instance under inspection.
 *
 * @return string Normalized sort token or empty string when no explicit sort is detected.
 */
function woo_search_opt_detect_sort( $wp_query ) {
    if ( ! ( $wp_query instanceof WP_Query ) ) {
        return '';
    }

    if ( isset( $wp_query->query_vars['woo_search_opt_resolved_sort'] ) ) {
        $cached = $wp_query->query_vars['woo_search_opt_resolved_sort'];
        return is_string( $cached ) ? $cached : '';
    }

    $orderby_raw = $wp_query->get( 'orderby' );
    $meta_key    = $wp_query->get( 'meta_key' );
    $order_raw   = $wp_query->get( 'order' );
    $requested   = woo_search_opt_get_requested_orderby();

    $wp_query->query_vars['woo_search_opt_requested_orderby'] = $requested;
    $meta_key_lower   = is_string( $meta_key ) ? strtolower( trim( $meta_key ) ) : '';
    $normalized_tokens = array();
    $primary_orderby   = '';
    $derived_order     = woo_search_opt_normalize_order_direction( $order_raw );

    if ( is_array( $orderby_raw ) ) {
        foreach ( $orderby_raw as $key => $value ) {
            if ( is_string( $key ) ) {
                $token = woo_search_opt_normalize_orderby_token( $key );
                if ( '' !== $token ) {
                    woo_search_opt_add_orderby_token( $normalized_tokens, $token, $primary_orderby );
                }
            }

            if ( is_string( $value ) ) {
                $direction = woo_search_opt_normalize_order_direction( $value );
                if ( '' === $derived_order && '' !== $direction ) {
                    $derived_order = $direction;
                }

                if ( '' === $direction ) {
                    $token = woo_search_opt_normalize_orderby_token( $value );
                    if ( '' !== $token ) {
                        woo_search_opt_add_orderby_token( $normalized_tokens, $token, $primary_orderby );
                    }
                }
            }
        }
    } elseif ( is_string( $orderby_raw ) ) {
        $orderby_string = strtolower( trim( $orderby_raw ) );

        if ( '' !== $orderby_string ) {
            $orderby_string = str_replace( array( '-', ',' ), ' ', $orderby_string );
            $orderby_string = str_replace( '.', '_', $orderby_string );
            $parts          = preg_split( '/\s+/', $orderby_string );

            if ( is_array( $parts ) ) {
                foreach ( $parts as $part ) {
                    $token = woo_search_opt_normalize_orderby_token( $part );
                    if ( '' === $token ) {
                        continue;
                    }

                    if ( in_array( $token, array( 'asc', 'desc' ), true ) ) {
                        if ( '' === $derived_order ) {
                            $derived_order = $token;
                        }
                        continue;
                    }

                    if ( '' !== $token ) {
                        woo_search_opt_add_orderby_token( $normalized_tokens, $token, $primary_orderby );
                    }
                }
            }
        }
    }

    $normalized_tokens = array_values( array_filter( $normalized_tokens ) );

    $resolved = '';

    if ( '' !== $requested ) {
        switch ( $requested ) {
            case 'price-desc':
                $resolved      = 'price_desc';
                $derived_order = ( '' === $derived_order ) ? 'desc' : $derived_order;
                break;
            case 'price':
                $resolved      = 'price';
                $derived_order = ( '' === $derived_order ) ? 'asc' : $derived_order;
                break;
            case 'popularity':
                $resolved = 'popularity';
                break;
            case 'rating':
                $resolved      = 'rating';
                $derived_order = ( '' === $derived_order ) ? 'desc' : $derived_order;
                break;
            case 'date':
                $resolved      = 'date';
                $derived_order = ( '' === $derived_order ) ? 'desc' : $derived_order;
                break;
            case 'menu_order':
                $resolved      = 'menu_order';
                $derived_order = ( '' === $derived_order ) ? 'asc' : $derived_order;
                break;
            case 'rand':
                $resolved = 'rand';
                break;
            default:
                break;
        }
    } else {
        if ( in_array( 'price_desc', $normalized_tokens, true ) ) {
            $resolved      = 'price_desc';
            $derived_order = ( '' === $derived_order ) ? 'desc' : $derived_order;
        } elseif ( in_array( 'price_asc', $normalized_tokens, true ) ) {
            $resolved      = 'price';
            $derived_order = ( '' === $derived_order ) ? 'asc' : $derived_order;
        } elseif ( in_array( 'price', $normalized_tokens, true ) ) {
            $resolved      = ( 'desc' === $derived_order ) ? 'price_desc' : 'price';
            $derived_order = ( '' === $derived_order ) ? 'asc' : $derived_order;
        }

        if ( '' === $resolved && in_array( 'popularity', $normalized_tokens, true ) ) {
            $resolved = 'popularity';
        }

        if ( '' === $resolved && in_array( 'rating', $normalized_tokens, true ) ) {
            $resolved      = 'rating';
            $derived_order = ( '' === $derived_order ) ? 'desc' : $derived_order;
        }

        if ( '' === $resolved && in_array( 'menu_order', $normalized_tokens, true ) ) {
            $resolved = 'menu_order';
        }

        if ( '' === $resolved && '_price' === $meta_key_lower && ( in_array( 'meta_value_num', $normalized_tokens, true ) || in_array( 'meta_value', $normalized_tokens, true ) ) ) {
            $resolved      = ( 'desc' === $derived_order ) ? 'price_desc' : 'price';
            $derived_order = ( '' === $derived_order ) ? 'asc' : $derived_order;
        }

        if ( '' === $resolved && '_wc_average_rating' === $meta_key_lower && ( in_array( 'meta_value_num', $normalized_tokens, true ) || in_array( 'meta_value', $normalized_tokens, true ) || in_array( 'rating', $normalized_tokens, true ) ) ) {
            $resolved      = 'rating';
            $derived_order = ( '' === $derived_order ) ? 'desc' : $derived_order;
        }

        if ( '' === $resolved && 'total_sales' === $meta_key_lower && in_array( 'meta_value_num', $normalized_tokens, true ) ) {
            $resolved      = 'popularity';
            $derived_order = ( '' === $derived_order ) ? 'desc' : $derived_order;
        }
    }

    $wp_query->query_vars['woo_search_opt_resolved_sort'] = $resolved;
    $wp_query->query_vars['woo_search_opt_sort_details']   = array(
        'orderby'           => $orderby_raw,
        'meta_key'          => $meta_key,
        'order'             => $order_raw,
        'primary_orderby'   => $primary_orderby,
        'derived_order'     => $derived_order,
        'normalized_tokens' => $normalized_tokens,
        'requested_orderby' => $requested,
    );

    return $resolved;
}

/**
 * Collect the resolved sort token and raw orderby data for diagnostics.
 *
 * @param WP_Query $wp_query Query instance.
 *
 * @return array
 */
function woo_search_opt_collect_sort_diagnostics( $wp_query ) {
    $defaults = array(
        'token'             => '',
        'orderby_raw'       => '',
        'meta_key_raw'      => '',
        'order_raw'         => '',
        'primary_orderby'   => '',
        'derived_order'     => '',
        'normalized_tokens' => array(),
        'requested_orderby' => '',
    );

    if ( ! ( $wp_query instanceof WP_Query ) ) {
        return $defaults;
    }

    $token = $wp_query->get( 'woo_search_opt_resolved_sort' );
    if ( ! is_string( $token ) ) {
        $token = '';
    }

    if ( '' === $token ) {
        $token = woo_search_opt_detect_sort( $wp_query );
    } elseif ( ! isset( $wp_query->query_vars['woo_search_opt_sort_details'] ) ) {
        woo_search_opt_detect_sort( $wp_query );
    }

    $details = array();
    if ( isset( $wp_query->query_vars['woo_search_opt_sort_details'] ) && is_array( $wp_query->query_vars['woo_search_opt_sort_details'] ) ) {
        $details = $wp_query->query_vars['woo_search_opt_sort_details'];
    }

    $normalized_tokens   = array();
    $requested_orderby   = '';
    if ( isset( $details['normalized_tokens'] ) && is_array( $details['normalized_tokens'] ) ) {
        $normalized_tokens = array_values( array_unique( array_filter( $details['normalized_tokens'] ) ) );
    }

    if ( array_key_exists( 'requested_orderby', $details ) ) {
        $requested_orderby = $details['requested_orderby'];
    } elseif ( $wp_query->get( 'woo_search_opt_requested_orderby' ) ) {
        $requested_orderby = $wp_query->get( 'woo_search_opt_requested_orderby' );
    }

    if ( ! is_string( $requested_orderby ) ) {
        $requested_orderby = '';
    }

    return array(
        'token'             => $token,
        'orderby_raw'       => array_key_exists( 'orderby', $details ) ? $details['orderby'] : $wp_query->get( 'orderby' ),
        'meta_key_raw'      => array_key_exists( 'meta_key', $details ) ? $details['meta_key'] : $wp_query->get( 'meta_key' ),
        'order_raw'         => array_key_exists( 'order', $details ) ? $details['order'] : $wp_query->get( 'order' ),
        'primary_orderby'   => isset( $details['primary_orderby'] ) ? $details['primary_orderby'] : '',
        'derived_order'     => isset( $details['derived_order'] ) ? $details['derived_order'] : '',
        'normalized_tokens' => $normalized_tokens,
        'requested_orderby' => $requested_orderby,
    );
}

/**
 * Enrich a logging context array with sort diagnostics.
 *
 * @param array    $context          Context array.
 * @param WP_Query $wp_query         Query instance.
 * @param array    $sort_diagnostics Optional precomputed diagnostics.
 *
 * @return array
 */
function woo_search_opt_append_sort_context( array $context, $wp_query, $sort_diagnostics = null ) {
    if ( ! ( $wp_query instanceof WP_Query ) ) {
        $context['resolved_sort'] = '';
        return $context;
    }

    if ( null === $sort_diagnostics ) {
        $sort_diagnostics = woo_search_opt_collect_sort_diagnostics( $wp_query );
    }

    $context['resolved_sort']       = woo_search_opt_sanitize_scalar_for_logging( $sort_diagnostics['token'] );
    $context['requested_orderby'] = woo_search_opt_sanitize_scalar_for_logging( $sort_diagnostics['requested_orderby'] );
    $context['sort_orderby_raw']    = woo_search_opt_normalize_context_value( $sort_diagnostics['orderby_raw'] );
    $context['sort_meta_key_raw']   = woo_search_opt_sanitize_scalar_for_logging( $sort_diagnostics['meta_key_raw'] );
    $context['sort_order_raw']      = woo_search_opt_sanitize_scalar_for_logging( $sort_diagnostics['order_raw'] );
    $context['sort_primary_orderby']= woo_search_opt_sanitize_scalar_for_logging( $sort_diagnostics['primary_orderby'] );
    $context['sort_derived_order']  = woo_search_opt_sanitize_scalar_for_logging( $sort_diagnostics['derived_order'] );
    $context['sort_tokens']         = woo_search_opt_normalize_context_value( $sort_diagnostics['normalized_tokens'] );

    return $context;
}

/**
 * Truncate long strings to avoid excessive payload sizes in the log file.
 *
 * @param mixed $value Value to truncate when applicable.
 * @param int   $limit Maximum length for strings.
 *
 * @return mixed
 */
function woo_search_opt_truncate_for_logging( $value, $limit = 4000 ) {
    if ( ! is_string( $value ) ) {
        return $value;
    }

    $value = esc_html( wp_unslash( $value ) );

    if ( strlen( $value ) <= $limit ) {
        return $value;
    }

    return substr( $value, 0, $limit ) . '…';
}

if ( ! function_exists( 'woo_search_opt_log' ) ) {
    /**
     * Write a diagnostics entry to the Woo Search Optimized log file.
     *
     * Each entry is encoded as JSON and includes the message, context, request metadata,
     * and a microtime timestamp for ordering.
     *
     * @param string $message Human-readable message.
     * @param array  $context Additional contextual information to include in the log entry.
     *
     * @return void
     */
    function woo_search_opt_log( $message, $context = array() ) {
        if ( ! defined( 'WOO_SEARCH_OPT_DEBUG' ) || ! WOO_SEARCH_OPT_DEBUG ) {
            return;
        }

        static $failed  = false;
        static $logging = false;

        if ( $failed || $logging ) {
            return;
        }

        $logging = true;

        woo_search_opt_init_logger();

        $log_file = isset( $GLOBALS['woo_search_opt_log_file'] ) ? $GLOBALS['woo_search_opt_log_file'] : null;

        $entry = array(
            'timestamp'      => gmdate( 'c' ),
            'microtime'      => microtime( true ),
            'message'        => (string) $message,
            'context'        => woo_search_opt_normalize_context_value( $context ),
            'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? woo_search_opt_sanitize_scalar_for_logging( $_SERVER['REQUEST_METHOD'] ) : 'CLI',
            'current_url'    => home_url( add_query_arg( null, null ) ),
            'user_id'        => get_current_user_id(),
        );

        $json = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( false === $json || null === $json ) {
            $json = wp_json_encode( array( 'message' => 'Failed to encode log entry', 'entry' => (string) print_r( $entry, true ) ) );
        }

        try {
            if ( empty( $log_file ) || ! is_string( $log_file ) ) {
                throw new RuntimeException( 'Log file path not initialized.' );
            }

            $handle = @fopen( $log_file, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( false === $handle ) {
                throw new RuntimeException( 'Unable to open log file for writing.' );
            }

            if ( false === @fwrite( $handle, $json . "\n" ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                fclose( $handle );
                throw new RuntimeException( 'Unable to write to log file.' );
            }

            fclose( $handle );
        } catch ( Exception $e ) {
            $failed = true;
            error_log( 'Woo Search Optimized logger error: ' . $e->getMessage() );
        }

        $logging = false;
    }
}

if ( ! function_exists( 'woo_search_opt_log_query' ) ) {
    function woo_search_opt_log_query( WP_Query $query ) {
        $is_admin_request    = is_admin();
        $is_elementor_ajax   = isset( $_REQUEST['elementor_ajax'] );
        $is_standard_wp_ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
        $resolved_search       = woo_search_opt_resolve_search_phrase( $query );
        $resolved_search_safe  = woo_search_opt_sanitize_scalar_for_logging( $resolved_search );
        $sort_diagnostics      = woo_search_opt_collect_sort_diagnostics( $query );
        $request_action        = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
        $search_term_injected  = false;

        if ( 'gm2_filter_products' === $request_action && '' !== $resolved_search ) {
            $query_search = $query->get( 's' );

            if ( ! is_string( $query_search ) || '' === trim( $query_search ) ) {
                $query->set( 's', $resolved_search );
                $search_term_injected = true;
            }
        }

        $context = array(
            'flags'                => woo_search_opt_extract_query_flags( $query ),
            'request_vars'         => woo_search_opt_collect_request_vars( array( 's', 'gm2_search_term', 'post_type', 'orderby', 'order', 'paged', 'page', 'elementor_ajax', 'action' ) ),
            'tax_query'            => woo_search_opt_normalize_context_value( $query->get( 'tax_query' ) ),
            'product_visibility'   => false,
            'decision'             => 'pending',
            'elementor_ajax_input' => $is_elementor_ajax,
            'wp_ajax'              => $is_standard_wp_ajax,
            'resolved_search'      => $resolved_search_safe,
            'search_term_injected' => $search_term_injected,
        );

        $context = woo_search_opt_append_sort_context( $context, $query, $sort_diagnostics );

        if ( $is_admin_request && ! ( $is_standard_wp_ajax || $is_elementor_ajax ) ) {
            $context['decision'] = 'bail_admin_request';
            woo_search_opt_log( 'woo_search_opt pre_get_posts bail', $context );
            return;
        }

        $post_type             = $query->get( 'post_type' );
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

        $context['product_visibility'] = $has_product_visibility_tax;
        $context['flags']['post_type'] = woo_search_opt_normalize_context_value( $post_type );

        if ( ! $has_product_post_type && ! $has_product_visibility_tax ) {
            $context['decision'] = 'bail_non_product_query';
            woo_search_opt_log( 'woo_search_opt pre_get_posts bail', $context );
            return;
        }

        $no_found_rows_adjusted = false;
        $requested_paged        = 0;
        $current_paged          = 0;
        $resolved_paged         = 0;
        $paged_overridden       = array();
        $orderby_overridden     = array();
        $order_overridden       = array();

        if ( '' !== $resolved_search && woo_search_opt_is_product_query( $query ) ) {
            $no_found_rows = $query->get( 'no_found_rows' );

            if ( $no_found_rows ) {
                $query->set( 'no_found_rows', false );
                $no_found_rows_adjusted             = true;
                $context['no_found_rows_overridden'] = array(
                    'previous' => woo_search_opt_normalize_context_value( $no_found_rows ),
                    'current'  => false,
                );
            }

            // Ensure paging is enabled for main product searches (avoid "show all" behavior).
            if ( $query->is_main_query() && $query->is_search() && woo_search_opt_is_product_query( $query ) ) {
                $ppp    = (int) $query->get( 'posts_per_page' );
                $nopage = $query->get( 'nopaging' );

                if ( -1 === $ppp || true === $nopage ) {
                    // Use a sensible per-page value:
                    // Prefer WordPress default; allow Woo/Theme filters to adjust via 'loop_shop_per_page'.
                    $default_ppp = (int) get_option( 'posts_per_page' );
                    if ( $default_ppp < 1 ) {
                        $default_ppp = 12; // fallback
                    }
                    $default_ppp = (int) apply_filters( 'loop_shop_per_page', $default_ppp, $query );

                    $query->set( 'posts_per_page', max( 1, $default_ppp ) );
                    $query->set( 'nopaging', false );

                    // We already force this elsewhere, but keep it explicit for safety:
                    $query->set( 'no_found_rows', false );

                    // Flag for diagnostics (optional):
                    $query->set( 'woo_search_opt_paging_fixed', true );
                }
            }

            $requested_paged = woo_search_opt_get_requested_paged( $query );
            $current_paged   = absint( $query->get( 'paged' ) );
            $resolved_paged  = $current_paged;

            if ( $requested_paged > 0 ) {
                $resolved_paged = $requested_paged;
            } elseif ( $current_paged < 1 ) {
                $resolved_paged = 1;
            }

            if ( $resolved_paged < 1 ) {
                $resolved_paged = 1;
            }

            // Respect existing paged unless the request explicitly asked for a page.
            // (Elementor/Woo will pass the correct var; we just thread it through.)
            if ( $requested_paged > 0 && $resolved_paged !== $current_paged ) {
                $query->set( 'paged', $resolved_paged );
                $paged_overridden = array(
                    'previous'  => woo_search_opt_normalize_context_value( $current_paged ),
                    'current'   => woo_search_opt_normalize_context_value( $resolved_paged ),
                    'requested' => woo_search_opt_normalize_context_value( $requested_paged ),
                );
            }

            $query->set( 'woo_search_opt_enable_pagination', true );
            $query->set( 'woo_search_opt_resolved_paged', $resolved_paged );

            $requested_orderby = woo_search_opt_get_requested_orderby();

            if ( '' !== $requested_orderby ) {
                $previous_orderby = $query->get( 'orderby' );

                if ( $previous_orderby !== $requested_orderby ) {
                    $query->set( 'orderby', $requested_orderby );
                    $orderby_overridden = array(
                        'previous' => woo_search_opt_normalize_context_value( $previous_orderby ),
                        'current'  => woo_search_opt_sanitize_scalar_for_logging( $requested_orderby ),
                    );
                }
            }

            $requested_order  = woo_search_opt_resolve_request_scalar( array( 'order', 'direction' ) );
            $normalized_order = woo_search_opt_normalize_order_direction( $requested_order );

            if ( '' !== $normalized_order ) {
                $previous_order = $query->get( 'order' );
                $upper_order    = strtoupper( $normalized_order );

                if ( ! is_string( $previous_order ) || strtoupper( $previous_order ) !== $upper_order ) {
                    $query->set( 'order', $upper_order );
                    $order_overridden = array(
                        'previous' => woo_search_opt_sanitize_scalar_for_logging( $previous_order ),
                        'current'  => woo_search_opt_sanitize_scalar_for_logging( $upper_order ),
                    );
                }
            }

            $context['resolved_paged'] = woo_search_opt_normalize_context_value(
                array(
                    'requested' => $requested_paged,
                    'previous'  => $current_paged,
                    'current'   => $resolved_paged,
                )
            );

            if ( ! empty( $orderby_overridden ) ) {
                $context['orderby_overridden'] = $orderby_overridden;
            }

            if ( ! empty( $order_overridden ) ) {
                $context['order_overridden'] = $order_overridden;
            }
        }

        $context['decision'] = 'target_query';

        if ( $no_found_rows_adjusted || ! empty( $paged_overridden ) ) {
            $context['pagination_adjusted'] = true;
        }

        if ( ! empty( $paged_overridden ) ) {
            $context['paged_overridden'] = $paged_overridden;
        }

        woo_search_opt_log( 'woo_search_opt pre_get_posts target', $context );
    }
}
add_action( 'pre_get_posts', 'woo_search_opt_log_query', 19, 1 );

/**
 * Determine whether a query targets WooCommerce products.
 *
 * @param WP_Query $wp_query Query instance.
 *
 * @return bool
 */
function woo_search_opt_is_product_query( $wp_query ) {
    if ( ! ( $wp_query instanceof WP_Query ) ) {
        return false;
    }

    $post_types = $wp_query->get( 'post_type' );

    if ( is_array( $post_types ) ) {
        foreach ( $post_types as $post_type ) {
            if ( 'product' === $post_type ) {
                return true;
            }
        }

        return false;
    }

    return ( 'product' === $post_types );
}

/**
 * Check whether the JOIN clause already contains an alias.
 *
 * @param string $join_sql JOIN clause.
 * @param string $alias    Alias to detect.
 *
 * @return bool
 */
function woo_search_opt_join_contains_alias( $join_sql, $alias ) {
    if ( ! is_string( $join_sql ) || '' === $join_sql || '' === $alias ) {
        return false;
    }

    return (bool) preg_match( '/\b' . preg_quote( $alias, '/' ) . '\b/i', $join_sql );
}

/**
 * Add JOINs for _price, _sku, and aggregated product attributes.
 * Unique alias names are used to avoid conflicts.
 */
function woo_search_opt_joins( $join, $wp_query ) {
    global $wpdb;

    $search_phrase    = woo_search_opt_resolve_search_phrase( $wp_query );
    $sort_token       = '';

    if ( $wp_query instanceof WP_Query ) {
        $sort_token = $wp_query->get( 'woo_search_opt_resolved_sort' );
        if ( ! is_string( $sort_token ) ) {
            $sort_token = '';
        }

        if ( '' === $sort_token ) {
            $sort_token = woo_search_opt_detect_sort( $wp_query );
        }
    }

    $sort_diagnostics = woo_search_opt_collect_sort_diagnostics( $wp_query );

    if ( '' === $sort_token ) {
        $sort_token = $sort_diagnostics['token'];
    }
    if ( '' === $search_phrase ) {
        $context = array(
            'reason'          => 'empty_search',
            'flags'           => woo_search_opt_extract_query_flags( $wp_query ),
            'resolved_search' => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        );
        $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );
        woo_search_opt_log( 'woo_search_opt posts_join bail', $context );
        return $join;
    }

    // Only modify queries for products.
    if ( ! woo_search_opt_is_product_query( $wp_query ) ) {
        $post_types = ( $wp_query instanceof WP_Query ) ? $wp_query->get( 'post_type' ) : null;
        $context = array(
            'reason'     => 'non_product_query',
            'flags'      => woo_search_opt_extract_query_flags( $wp_query ),
            'post_types' => woo_search_opt_normalize_context_value( $post_types ),
        );
        $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );
        woo_search_opt_log( 'woo_search_opt posts_join bail', $context );
        return $join;
    }

    $initial_join = woo_search_opt_truncate_for_logging( $join );
    $added_joins  = array();

    // Join postmeta for price and SKU.
    if ( ! woo_search_opt_join_contains_alias( $join, 'woo_pm_price' ) ) {
        $join        .= " LEFT JOIN {$wpdb->postmeta} AS woo_pm_price ON ({$wpdb->posts}.ID = woo_pm_price.post_id AND woo_pm_price.meta_key = '_price') ";
        $added_joins[] = 'woo_pm_price';
    }
    if ( ! woo_search_opt_join_contains_alias( $join, 'woo_pm_sku' ) ) {
        $join        .= " LEFT JOIN {$wpdb->postmeta} AS woo_pm_sku ON ({$wpdb->posts}.ID = woo_pm_sku.post_id AND woo_pm_sku.meta_key = '_sku') ";
        $added_joins[] = 'woo_pm_sku';
    }

    // Join a subquery to aggregate attribute names (from taxonomies starting with 'pa_').
    if ( ! woo_search_opt_join_contains_alias( $join, 'woo_attr' ) ) {
        $join        .= " LEFT JOIN (
                SELECT tr.object_id, GROUP_CONCAT(t.name SEPARATOR ' ') AS attributes
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                WHERE tt.taxonomy LIKE 'pa\\_%'
                GROUP BY tr.object_id
               ) AS woo_attr ON woo_attr.object_id = {$wpdb->posts}.ID ";
        $added_joins[] = 'woo_attr';
    }

    if ( 'popularity' === $sort_token && ! woo_search_opt_join_contains_alias( $join, 'woo_pm_total_sales' ) ) {
        $join        .= " LEFT JOIN {$wpdb->postmeta} AS woo_pm_total_sales ON ({$wpdb->posts}.ID = woo_pm_total_sales.post_id AND woo_pm_total_sales.meta_key = 'total_sales') ";
        $added_joins[] = 'woo_pm_total_sales';
    }

    if ( 'rating' === $sort_token && ! woo_search_opt_join_contains_alias( $join, 'woo_pm_rating' ) ) {
        $join        .= " LEFT JOIN {$wpdb->postmeta} AS woo_pm_rating ON ({$wpdb->posts}.ID = woo_pm_rating.post_id AND woo_pm_rating.meta_key = '_wc_average_rating') ";
        $added_joins[] = 'woo_pm_rating';
    }

    $context = array(
        'flags'          => woo_search_opt_extract_query_flags( $wp_query ),
        'search_term'    => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'incoming_join'  => $initial_join,
        'outgoing_join'  => woo_search_opt_truncate_for_logging( $join ),
        'attribute_join' => 'pa_% taxonomy aggregation',
        'sort_joins'     => woo_search_opt_sanitize_array_for_logging( $added_joins ),
    );
    $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );

    woo_search_opt_log( 'woo_search_opt posts_join applied', $context );

    return $join;
}
add_filter('posts_join', 'woo_search_opt_joins', 20, 2);

/**
 * Use the posts_search filter to add custom search conditions.
 * This will combine the default search conditions with our optimized conditions.
 */
function woo_search_opt_posts_search( $search, $wp_query ) {
    global $wpdb;

    $search_phrase    = woo_search_opt_resolve_search_phrase( $wp_query );
    $sort_token       = '';

    if ( $wp_query instanceof WP_Query ) {
        $sort_token = $wp_query->get( 'woo_search_opt_resolved_sort' );
        if ( ! is_string( $sort_token ) ) {
            $sort_token = '';
        }

        if ( '' === $sort_token ) {
            $sort_token = woo_search_opt_detect_sort( $wp_query );
        }
    }

    $sort_diagnostics = woo_search_opt_collect_sort_diagnostics( $wp_query );

    if ( '' === $sort_token ) {
        $sort_token = $sort_diagnostics['token'];
    }
    $context = array(
        'flags'           => woo_search_opt_extract_query_flags( $wp_query ),
        'incoming_search' => woo_search_opt_truncate_for_logging( $search ),
        'search_phrase'   => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'resolved_search' => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'decision'        => 'pending',
    );
    $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );
    if ( '' === $search_phrase ) {
        $context['decision'] = 'bail_empty_search';
        woo_search_opt_log( 'woo_search_opt posts_search bail', $context );
        return $search;
    }

    // Only affect product queries.
    if ( ! woo_search_opt_is_product_query( $wp_query ) ) {
        $post_types = ( $wp_query instanceof WP_Query ) ? $wp_query->get( 'post_type' ) : null;
        $context['decision']   = 'bail_non_product_query';
        $context['post_types'] = woo_search_opt_normalize_context_value( $post_types );
        woo_search_opt_log( 'woo_search_opt posts_search bail', $context );
        return $search;
    }

    $tokenized_phrase = preg_split( '/\s+/', $search_phrase );
    if ( ! is_array( $tokenized_phrase ) ) {
        $tokenized_phrase = array( $search_phrase );
    }

    $tokens = array_filter( array_map( 'trim', $tokenized_phrase ), 'strlen' );
    if ( empty( $tokens ) ) {
        $context['decision'] = 'bail_no_tokens';
        woo_search_opt_log( 'woo_search_opt posts_search bail', $context );
        return $search;
    }

    $normalized_tokens = woo_search_opt_normalize_tokens( $tokens );

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

    $context['decision']        = 'applied';
    $context['tokens']          = $normalized_tokens;
    $context['token_clauses']   = woo_search_opt_truncate_for_logging( implode( ' AND ', $token_clauses ) );
    $context['custom_search']   = woo_search_opt_truncate_for_logging( $custom_search );
    $context['outgoing_search'] = woo_search_opt_truncate_for_logging( $search );

    woo_search_opt_log( 'woo_search_opt posts_search applied', $context );

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

    $search_phrase    = woo_search_opt_resolve_search_phrase( $wp_query );
    $sort_token       = '';

    if ( $wp_query instanceof WP_Query ) {
        $sort_token = $wp_query->get( 'woo_search_opt_resolved_sort' );
        if ( ! is_string( $sort_token ) ) {
            $sort_token = '';
        }

        if ( '' === $sort_token ) {
            $sort_token = woo_search_opt_detect_sort( $wp_query );
        }
    }

    $sort_diagnostics = woo_search_opt_collect_sort_diagnostics( $wp_query );

    if ( '' === $sort_token ) {
        $sort_token = $sort_diagnostics['token'];
    }
    $context = array(
        'flags'          => woo_search_opt_extract_query_flags( $wp_query ),
        'search_phrase'  => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'incoming_field' => woo_search_opt_truncate_for_logging( $fields ),
        'resolved_search' => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'decision'       => 'pending',
    );
    $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );
    if ( '' === $search_phrase ) {
        $context['decision'] = 'bail_empty_search';
        woo_search_opt_log( 'woo_search_opt posts_fields bail', $context );
        return $fields;
    }

    if ( ! woo_search_opt_is_product_query( $wp_query ) ) {
        $post_types = ( $wp_query instanceof WP_Query ) ? $wp_query->get( 'post_type' ) : null;
        $context['decision']   = 'bail_non_product_query';
        $context['post_types'] = woo_search_opt_normalize_context_value( $post_types );
        woo_search_opt_log( 'woo_search_opt posts_fields bail', $context );
        return $fields;
    }

    $phrase_like = '%' . $wpdb->esc_like( $search_phrase ) . '%';
    $phrase_like_escaped = esc_sql( $phrase_like );

    $tokenized_phrase = preg_split( '/\s+/', $search_phrase );
    if ( ! is_array( $tokenized_phrase ) ) {
        $tokenized_phrase = array( $search_phrase );
    }

    $tokens = array_filter( array_map( 'trim', $tokenized_phrase ), 'strlen' );

    $normalized_tokens = woo_search_opt_normalize_tokens( $tokens );

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

    $context['decision']              = 'applied';
    $context['tokens']                = $normalized_tokens;
    $context['token_score_parts']     = count( $token_score_parts );
    $context['relevance_parts_count'] = count( $relevance_parts );
    $context['ordered_regex']         = woo_search_opt_truncate_for_logging( implode( '.*', $ordered_regex_parts ) );
    $context['token_count']           = $token_count;
    $context['universal_penalty_sql'] = woo_search_opt_truncate_for_logging( $universal_penalty_sql );
    $context['outgoing_fields']       = woo_search_opt_truncate_for_logging( $fields );

    woo_search_opt_log( 'woo_search_opt posts_fields applied', $context );

    return $fields;
}
add_filter('posts_fields', 'woo_search_opt_relevance', 20, 2);

/**
 * Order results by exact phrase, token score, overall relevance, and post title.
 */
function woo_search_opt_orderby( $orderby, $wp_query ) {
    global $wpdb;

    $search_phrase    = woo_search_opt_resolve_search_phrase( $wp_query );
    $sort_token       = '';

    if ( $wp_query instanceof WP_Query ) {
        $sort_token = $wp_query->get( 'woo_search_opt_resolved_sort' );
        if ( ! is_string( $sort_token ) ) {
            $sort_token = '';
        }

        if ( '' === $sort_token ) {
            $sort_token = woo_search_opt_detect_sort( $wp_query );
        }
    }

    $sort_diagnostics = woo_search_opt_collect_sort_diagnostics( $wp_query );

    if ( '' === $sort_token ) {
        $sort_token = $sort_diagnostics['token'];
    }
    $context = array(
        'flags'             => woo_search_opt_extract_query_flags( $wp_query ),
        'search_phrase'     => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'incoming_orderby'  => woo_search_opt_truncate_for_logging( $orderby ),
        'resolved_search'   => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'decision'          => 'pending',
    );
    $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );
    if ( '' === $search_phrase ) {
        $context['decision'] = 'bail_empty_search';
        woo_search_opt_log( 'woo_search_opt posts_orderby bail', $context );
        return $orderby;
    }

    if ( ! woo_search_opt_is_product_query( $wp_query ) ) {
        $post_types = ( $wp_query instanceof WP_Query ) ? $wp_query->get( 'post_type' ) : null;
        $context['decision']   = 'bail_non_product_query';
        $context['post_types'] = woo_search_opt_normalize_context_value( $post_types );
        woo_search_opt_log( 'woo_search_opt posts_orderby bail', $context );
        return $orderby;
    }

    if ( '' !== $sort_token ) {
        $map = array(
            'price'       => "CAST( woo_pm_price.meta_value AS DECIMAL(10,2) ) ASC",
            'price_desc'  => "CAST( woo_pm_price.meta_value AS DECIMAL(10,2) ) DESC",
            'popularity'  => "CAST( woo_pm_total_sales.meta_value AS UNSIGNED ) DESC",
            'rating'      => "CAST( woo_pm_rating.meta_value AS DECIMAL(3,2) ) DESC",
            'date'        => "{$wpdb->posts}.post_date DESC",
            'menu_order'  => "{$wpdb->posts}.menu_order ASC, {$wpdb->posts}.post_title ASC",
            'rand'        => 'RAND()',
        );

        if ( isset( $map[ $sort_token ] ) ) {
            $orderby = $map[ $sort_token ];

            $tiebreakers = array( 'relevance DESC', "{$wpdb->posts}.post_title ASC" );
            foreach ( $tiebreakers as $tiebreaker ) {
                if ( false === stripos( $orderby, $tiebreaker ) ) {
                    $orderby .= ', ' . $tiebreaker;
                }
            }

            $context['decision']          = 'delegate_to_wc_order';
            $context['outgoing_orderby']  = woo_search_opt_truncate_for_logging( $orderby );
            $context['delegate_orderby']  = woo_search_opt_truncate_for_logging( $map[ $sort_token ] );
            woo_search_opt_log( 'woo_search_opt posts_orderby applied', $context );
            return $orderby;
        }
    }

    $orderby = "title_exact_phrase DESC, title_ordered_phrase DESC, title_all_tokens DESC, title_token_hits DESC, attr_all_tokens DESC, content_all_tokens DESC, overall_token_hits DESC, token_score DESC, relevance DESC, {$wpdb->posts}.post_title ASC";
    $context['decision']        = 'applied';
    $context['outgoing_orderby'] = woo_search_opt_truncate_for_logging( $orderby );
    woo_search_opt_log( 'woo_search_opt posts_orderby applied', $context );
    return $orderby;
}
add_filter('posts_orderby', 'woo_search_opt_orderby', 20, 2);

/*
 * Manual test checklist:
 * - Perform a product search without touching the sort dropdown and confirm the logs show an empty resolved_sort with no requested_orderby value while relevance ordering remains active.
 * - Exercise each sort dropdown option (popularity, rating, latest, price low→high, price high→low, random) and confirm the logs capture both requested_orderby and resolved_sort tokens alongside matching UI results and ORDER BY clauses.
 * - Repeat the sort dropdown without a search term to ensure default catalog ordering remains unchanged.
 * - Watch for SQL errors in the debug log and confirm relevance ordering is still applied when no explicit sort is chosen.
 */

/**
 * Group by post ID to ensure each product appears only once.
 */
function woo_search_opt_groupby( $groupby, $wp_query ) {
    global $wpdb;
    $search_phrase    = woo_search_opt_resolve_search_phrase( $wp_query );
    $sort_diagnostics = woo_search_opt_collect_sort_diagnostics( $wp_query );
    $context = array(
        'flags'            => woo_search_opt_extract_query_flags( $wp_query ),
        'search_phrase'    => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'incoming_groupby' => woo_search_opt_truncate_for_logging( $groupby ),
        'resolved_search'  => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'decision'         => 'pending',
    );
    $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );
    if ( '' === $search_phrase ) {
        $context['decision'] = 'bail_empty_search';
        woo_search_opt_log( 'woo_search_opt posts_groupby bail', $context );
        return $groupby;
    }

    if ( ! woo_search_opt_is_product_query( $wp_query ) ) {
        $post_types = ( $wp_query instanceof WP_Query ) ? $wp_query->get( 'post_type' ) : null;
        $context['decision']   = 'bail_non_product_query';
        $context['post_types'] = woo_search_opt_normalize_context_value( $post_types );
        woo_search_opt_log( 'woo_search_opt posts_groupby bail', $context );
        return $groupby;
    }

    $groupby = "{$wpdb->posts}.ID";
    $context['decision']         = 'applied';
    $context['outgoing_groupby'] = woo_search_opt_truncate_for_logging( $groupby );
    woo_search_opt_log( 'woo_search_opt posts_groupby applied', $context );
    return $groupby;
}
add_filter('posts_groupby', 'woo_search_opt_groupby', 20, 2);

/**
 * Capture the assembled SQL clauses for diagnostic purposes.
 *
 * @param array    $clauses Combined clauses array.
 * @param WP_Query $wp_query Query instance.
 *
 * @return array
 */
function woo_search_opt_posts_clauses_logger( $clauses, $wp_query ) {
    $search_phrase    = woo_search_opt_resolve_search_phrase( $wp_query );
    $sort_diagnostics = woo_search_opt_collect_sort_diagnostics( $wp_query );

    if ( '' === $search_phrase ) {
        $context = array(
            'reason'          => 'empty_search',
            'flags'           => woo_search_opt_extract_query_flags( $wp_query ),
            'resolved_search' => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        );
        $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );
        woo_search_opt_log( 'woo_search_opt posts_clauses bail', $context );
        return $clauses;
    }

    $clause_snapshot = array();

    foreach ( array( 'distinct', 'fields', 'join', 'where', 'orderby', 'groupby', 'limits' ) as $key ) {
        if ( isset( $clauses[ $key ] ) ) {
            $clause_snapshot[ $key ] = woo_search_opt_truncate_for_logging( $clauses[ $key ] );
        }
    }

    $context = array(
        'flags'           => woo_search_opt_extract_query_flags( $wp_query ),
        'clauses'         => $clause_snapshot,
        'resolved_search' => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
    );
    $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );

    woo_search_opt_log( 'woo_search_opt posts_clauses snapshot', $context );

    return $clauses;
}
add_filter( 'posts_clauses', 'woo_search_opt_posts_clauses_logger', 20, 2 );

/**
 * Log the resulting posts for diagnostics including relevance metrics.
 *
 * @param array    $posts    Array of WP_Post objects.
 * @param WP_Query $wp_query Query instance.
 *
 * @return array
 */
function woo_search_opt_posts_results_logger( $posts, $wp_query ) {
    $search_phrase    = woo_search_opt_resolve_search_phrase( $wp_query );
    $sort_diagnostics = woo_search_opt_collect_sort_diagnostics( $wp_query );

    if ( '' === $search_phrase ) {
        $context = array(
            'reason'          => 'empty_search',
            'flags'           => woo_search_opt_extract_query_flags( $wp_query ),
            'resolved_search' => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        );
        $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );
        woo_search_opt_log( 'woo_search_opt posts_results bail', $context );
        return $posts;
    }

    $summary      = array();
    $max_results  = 25;
    $meta_fields  = array( 'title_exact_phrase', 'exact_match', 'title_token_hits', 'attr_token_hits', 'content_token_hits', 'overall_token_hits', 'title_all_tokens', 'attr_all_tokens', 'content_all_tokens', 'title_ordered_phrase', 'token_score', 'universal_penalty', 'relevance' );

    foreach ( $posts as $index => $post ) {
        if ( ! $post instanceof WP_Post ) {
            continue;
        }

        $entry = array(
            'ID'    => $post->ID,
            'title' => woo_search_opt_sanitize_scalar_for_logging( $post->post_title ),
        );

        $sku = get_post_meta( $post->ID, '_sku', true );
        if ( ! empty( $sku ) ) {
            $entry['sku'] = woo_search_opt_sanitize_scalar_for_logging( $sku );
        }

        foreach ( $meta_fields as $field ) {
            if ( isset( $post->$field ) ) {
                $entry[ $field ] = $post->$field;
            }
        }

        $summary[] = $entry;

        if ( count( $summary ) >= $max_results ) {
            break;
        }
    }

    $context = array(
        'flags'           => woo_search_opt_extract_query_flags( $wp_query ),
        'total_results'   => count( $posts ),
        'logged_results'  => $summary,
        'truncated'       => count( $posts ) > $max_results,
        'resolved_search' => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
    );
    $context = woo_search_opt_append_sort_context( $context, $wp_query, $sort_diagnostics );

    woo_search_opt_log( 'woo_search_opt posts_results summary', $context );

    return $posts;
}
add_filter( 'posts_results', 'woo_search_opt_posts_results_logger', 20, 2 );

/**
 * Render pagination links for Elementor-powered product search loops.
 *
 * @param WP_Query $query Query instance provided by the loop_end action.
 *
 * @return void
 */
function woo_search_opt_render_loop_pagination( $query ) {
    if ( ! ( $query instanceof WP_Query ) ) {
        return;
    }

    // Only for main product search loops, and avoid double pagination with Elementor widgets.
    if ( ! $query->is_main_query() ) {
        return;
    }

    if ( woo_search_opt_is_elementor_context( $query ) ) {
        // Elementor Products widget will render its own pagination.
        return;
    }

    if ( $query->get( 'woo_search_opt_pagination_rendered' ) ) {
        return;
    }

    if ( ! $query->get( 'woo_search_opt_enable_pagination' ) ) {
        return;
    }

    $total_pages = (int) $query->max_num_pages;

    if ( $total_pages < 2 ) {
        return;
    }

    $current_page = $query->get( 'woo_search_opt_resolved_paged' );

    if ( ! is_numeric( $current_page ) || (int) $current_page < 1 ) {
        $current_page = absint( $query->get( 'paged' ) );
    }

    if ( $current_page < 1 ) {
        $current_page = max( 1, absint( get_query_var( 'paged' ) ) );
    }

    if ( $current_page < 1 ) {
        $current_page = 1;
    }

    $add_args = isset( $add_args ) && is_array( $add_args ) ? $add_args : array();

    $search_phrase = get_query_var( 's', '' );
    if ( '' === $search_phrase ) {
        $search_phrase = woo_search_opt_resolve_search_phrase( $query );
    }

    if ( '' !== $search_phrase ) {
        $sanitized_search          = sanitize_text_field( $search_phrase );
        $add_args['s']             = $sanitized_search;
        $add_args['gm2_search_term'] = $sanitized_search;
    }

    $orderby = get_query_var( 'orderby', '' );
    $order   = get_query_var( 'order', '' );

    if ( '' === $orderby && '' !== $search_phrase ) {
        $orderby = 'relevance';
    }

    if ( '' !== $orderby ) {
        $add_args['orderby'] = sanitize_text_field( $orderby );
    }

    if ( '' !== $order ) {
        $add_args['order'] = sanitize_text_field( $order );
    }

    $pt = get_query_var( 'post_type', '' );
    if ( $pt ) {
        if ( is_array( $pt ) ) {
            $sanitized_types = array_values( array_filter( array_map( 'sanitize_key', $pt ) ) );
            if ( in_array( 'product', $sanitized_types, true ) ) {
                $add_args['post_type'] = 'product';
            } elseif ( ! empty( $sanitized_types ) ) {
                $add_args['post_type'] = $sanitized_types[0];
            }
        } else {
            $sanitized_type = sanitize_key( $pt );
            if ( '' !== $sanitized_type ) {
                $add_args['post_type'] = $sanitized_type;
            }
        }
    }

    if ( get_query_var( 'wc_query', '' ) ) {
        $add_args['wc_query'] = 'product_query';
    }

    woo_search_opt_debug( 'paginate_links:add_args', $add_args );

    $add_args = array_filter( $add_args, 'strlen' );

    $pagination_var = $query->get( 'woo_search_opt_resolved_paged_var' );
    $pagination_var = is_string( $pagination_var ) ? trim( $pagination_var ) : '';
    $elementor_keys = woo_search_opt_collect_elementor_pagination_keys( $query );

    if ( '' === $pagination_var ) {
        if ( ! empty( $elementor_keys ) ) {
            $pagination_var = $elementor_keys[0];
        } elseif ( woo_search_opt_is_elementor_context( $query ) ) {
            $pagination_var = 'product-page';
        }
    }

    if ( '' === $pagination_var ) {
        $pagination_var = 'paged';
    }

    $pagination_var = preg_replace( '/[^a-z0-9_\-]/i', '', $pagination_var );

    if ( '' === $pagination_var ) {
        $pagination_var = 'paged';
    }

    $big    = 999999999;
    $format = '';
    $base_link = str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) );

    if ( ! in_array( $pagination_var, array( 'paged', 'page' ), true ) ) {
        $cleanup_keys = array_merge(
            array( 'paged', 'page', 'product-page', 'elementor_page', $pagination_var ),
            $elementor_keys
        );

        $cleanup_keys = array_values( array_unique( array_filter( array_map( 'strval', $cleanup_keys ) ) ) );

        $raw_base_url = get_pagenum_link( 1, false );

        if ( ! is_string( $raw_base_url ) || '' === $raw_base_url ) {
            $raw_base_url = get_pagenum_link( 1 );
        }

        if ( is_string( $raw_base_url ) && '' !== $raw_base_url ) {
            $raw_base_url = remove_query_arg( $cleanup_keys, $raw_base_url );
        } else {
            $raw_base_url = home_url( '/' );
        }

        $format_glue = ( false === strpos( $raw_base_url, '?' ) ) ? '?' : '&';

        $base_link = esc_url( $raw_base_url ) . '%_%';
        $format    = $format_glue . $pagination_var . '=%#%';
    }

    woo_search_opt_debug( 'paginate_links:before', array(
        'base'     => isset( $base_link ) ? $base_link : null,
        'format'   => isset( $format ) ? $format : null,
        'add_args' => empty( $add_args ) ? array() : $add_args,
    ) );

    $pagination = paginate_links(
        array(
            'base'      => $base_link,
            'format'    => $format,
            'current'   => max( 1, (int) $current_page ),
            'total'     => $total_pages,
            'type'      => 'list',
            'mid_size'  => 1,
            'add_args'  => empty( $add_args ) ? false : $add_args,
        )
    );

    if ( empty( $pagination ) ) {
        return;
    }

    echo '<nav class="woo-search-opt-pagination" aria-label="' . esc_attr__( 'Products navigation', 'woo-search-optimized' ) . '">';
    echo $pagination; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns safe HTML.
    echo '</nav>';

    $query->set( 'woo_search_opt_pagination_rendered', true );
}
add_action( 'loop_end', 'woo_search_opt_render_loop_pagination' );

/**
 * Inject a "Best match" option into WooCommerce's catalog ordering dropdown.
 *
 * @param array $sorts Existing sort options.
 *
 * @return array Modified sort options.
 */
function woo_search_opt_register_best_match_sort( $sorts ) {
    if ( ! is_array( $sorts ) ) {
        $sorts = array();
    }

    $label = __( 'Best match (default)', 'woo-search-optimized' );

    if ( ! isset( $sorts['relevance'] ) ) {
        $sorts = array( 'relevance' => $label ) + $sorts;
        woo_search_opt_debug( 'catalog_orderby:added_relevance_label' );
    } else {
        $sorts['relevance'] = $label;
    }

    return $sorts;
}
add_filter( 'woocommerce_catalog_orderby', 'woo_search_opt_register_best_match_sort', 10, 1 );

/**
 * Ensure WooCommerce defers to custom relevance ordering when selected.
 *
 * @param array  $args    Ordering arguments.
 * @param string $orderby Requested orderby token.
 * @param string $order   Requested order direction.
 *
 * @return array
 */
function woo_search_opt_filter_relevance_ordering_args( $args, $orderby, $order ) {
    if ( 'relevance' !== $orderby ) {
        return $args;
    }

    unset( $args['meta_key'] );

    $args['orderby'] = 'date';
    $args['order']   = ( is_string( $order ) && '' !== $order ) ? $order : 'DESC';

    woo_search_opt_debug( 'ordering_args:relevance_token', $args );

    return $args;
}
add_filter( 'woocommerce_get_catalog_ordering_args', 'woo_search_opt_filter_relevance_ordering_args', 10, 3 );

/**
 * Enqueue an inline helper script to persist the latest search phrase through AJAX sorters.
 */
function woo_search_opt_enqueue_frontend_script() {
    if ( is_admin() ) {
        return;
    }

    wp_enqueue_script( 'jquery' );

    $script = <<<'JS'
(function($){
    var lastQuery = '';

    function setLastQuery(value) {
        lastQuery = (value || '').toString();
        window.wooSearchOptLastQuery = lastQuery;
    }

    function getLastQuery() {
        var stored = window.wooSearchOptLastQuery;
        if (typeof stored === 'string') {
            return stored;
        }
        if (stored && stored.toString) {
            return stored.toString();
        }
        return lastQuery;
    }

    function ensureTermOnForm($form) {
        if (!$form || !$form.length) {
            return;
        }

        var term = getLastQuery();
        var $gm2Field = $form.find('input[name="gm2_search_term"]');

        if (!$gm2Field.length) {
            $gm2Field = $('<input>', { type: 'hidden', name: 'gm2_search_term' }).appendTo($form);
        }

        $gm2Field.val(term);

        var $sField = $form.find('input[name="s"]');

        if (!$sField.length) {
            $sField = $('<input>', { type: 'hidden', name: 's' }).appendTo($form);
        }

        $sField.val(term);
    }

    function bootstrapFromLocation() {
        if (getLastQuery()) {
            return;
        }

        if (typeof URLSearchParams === 'undefined') {
            return;
        }

        var params = new URLSearchParams(window.location.search || '');
        var fallback = params.get('s') || params.get('search') || params.get('gm2_search_term') || params.get('keyword') || '';

        if (fallback) {
            setLastQuery(fallback);
        }
    }

    $(function(){
        var $searchForms = $('form.woocommerce-product-search');

        if ($searchForms.length) {
            $searchForms.each(function(){
                var $form = $(this);
                var $input = $form.find('input[name="s"]');

                if (!$input.length) {
                    return;
                }

                setLastQuery($input.first().val());

                $input.on('input change', function(){
                    setLastQuery($(this).val());
                });

                $form.on('submit', function(){
                    setLastQuery($input.first().val());
                });
            });
        }

        bootstrapFromLocation();

        $(document).on('change', '.woocommerce-ordering select[name="orderby"]', function(){
            var $form = $(this).closest('form');
            ensureTermOnForm($form);
        });

        $(document).on('submit', '.woocommerce-ordering form, form.woocommerce-ordering, form.gm2-filter-products-form', function(){
            ensureTermOnForm($(this));
        });

        $(document).ajaxSend(function(event, jqXHR, settings){
            if (!settings || !settings.data) {
                return;
            }

            var term = getLastQuery();

            if (typeof term !== 'string') {
                term = term ? term.toString() : '';
            }

            if (!term) {
                return;
            }

            var handleStringData = function(dataString) {
                if (dataString.indexOf('action=gm2_filter_products') === -1) {
                    return dataString;
                }

                var encodedTerm = encodeURIComponent(term);
                var gm2Regex = /gm2_search_term=[^&]*/;
                var sRegex = /s=[^&]*/;

                if (gm2Regex.test(dataString)) {
                    dataString = dataString.replace(gm2Regex, 'gm2_search_term=' + encodedTerm);
                } else {
                    dataString += '&gm2_search_term=' + encodedTerm;
                }

                if (sRegex.test(dataString)) {
                    dataString = dataString.replace(sRegex, 's=' + encodedTerm);
                } else {
                    dataString += '&s=' + encodedTerm;
                }

                return dataString;
            };

            if (typeof settings.data === 'string') {
                settings.data = handleStringData(settings.data);
                return;
            }

            if (typeof URLSearchParams !== 'undefined' && settings.data instanceof URLSearchParams) {
                if (settings.data.get('action') !== 'gm2_filter_products') {
                    return;
                }

                settings.data.set('gm2_search_term', term);
                settings.data.set('s', term);
                return;
            }

            if (typeof FormData !== 'undefined' && settings.data instanceof FormData) {
                if (settings.data.get('action') !== 'gm2_filter_products') {
                    return;
                }

                settings.data.set('gm2_search_term', term);
                settings.data.set('s', term);
                return;
            }

            if ($.isPlainObject(settings.data)) {
                if (settings.data.action !== 'gm2_filter_products') {
                    return;
                }

                settings.data.gm2_search_term = term;
                settings.data.s = term;
            }
        });
    });
})(jQuery);
JS;

    $handle = 'woo-search-opt-frontend';

    if ( ! wp_script_is( $handle, 'registered' ) ) {
        wp_register_script( $handle, false, array( 'jquery' ), '1.0.0', true );
    }

    wp_add_inline_script( $handle, $script );
    wp_enqueue_script( $handle );

}
add_action( 'wp_enqueue_scripts', 'woo_search_opt_enqueue_frontend_script' );

// === Ensure qty plugin assets exist + add minimal re-init (no custom +/- UI) ===
add_action( 'wp_enqueue_scripts', function() {
    $is_product_search  = is_search() && ( get_query_var( 'post_type', '' ) === 'product' || 'product' === get_query_var( 'post_type', '' ) );
    $is_product_archive = ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'product' ) )
                       || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );

    wso_log( 'qty:enqueue_probe', array(
        'is_search'  => is_search() ? 1 : 0,
        'post_type'  => get_query_var( 'post_type', '' ),
        'is_archive' => $is_product_archive ? 1 : 0,
    ) );

    if ( ! ( $is_product_search || $is_product_archive ) ) {
        return;
    }

    $script = null;
    $style  = null;

    if ( wp_script_is( 'wqpmb-script', 'registered' ) && ! wp_script_is( 'wqpmb-script', 'enqueued' ) ) {
        wp_enqueue_script( 'wqpmb-script' );
        $script = 'wqpmb-script';
    } elseif ( wp_script_is( 'wqpmb-script', 'enqueued' ) ) {
        $script = 'wqpmb-script';
    }

    if ( wp_style_is( 'wqpmb-style', 'registered' ) && ! wp_style_is( 'wqpmb-style', 'enqueued' ) ) {
        wp_enqueue_style( 'wqpmb-style' );
        $style = 'wqpmb-style';
    } elseif ( wp_style_is( 'wqpmb-style', 'enqueued' ) ) {
        $style = 'wqpmb-style';
    }

    wso_log( 'qty:enqueue_result', array( 'script' => $script, 'style' => $style ) );

    $handle = 'wso-qty-reinit';
    if ( ! wp_script_is( $handle, 'enqueued' ) ) {
        wp_register_script( $handle, false, array( 'jquery' ), '1.0', true );
        wp_add_inline_script( $handle, <<<JS
(function($){
    var LOG='[woo-search][qty] ';
    function reinit(){ try{ $(document).trigger('ajaxComplete'); if(console&&console.debug) console.debug(LOG+'reinit'); }catch(e){} }

    if (document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', reinit); } else { reinit(); }

    $(document)
      .on('updated_wc_div wc_fragments_refreshed', reinit)
      .on('ajaxComplete', reinit)
      .on('gm2_filter_products_done', reinit);

    (function(){
        var root=document.querySelector('.products')||document.querySelector('.woocommerce');
        if(!root||!window.MutationObserver) return;
        var mo=new MutationObserver(function(muts){
            for(var i=0;i<muts.length;i++){
                var m=muts[i];
                if(m.addedNodes&&m.addedNodes.length){
                    for(var j=0;j<m.addedNodes.length;j++){
                        var n=m.addedNodes[j];
                        if(!(n instanceof HTMLElement)) continue;
                        if((n.matches&& (n.matches('.product')||n.matches('.quantity')||n.matches('input.qty')))
                           || (n.querySelector && (n.querySelector('.product')||n.querySelector('.quantity')||n.querySelector('input.qty')))){
                            setTimeout(reinit,0); return;
                        }
                    }
                }
            }
        });
        mo.observe(root,{childList:true,subtree:true});
    })();
})(jQuery);
JS
        );
        wp_enqueue_script( $handle );
        wso_log( 'qty:inline_reinit_added' );
    }
}, 999 );

// === Loop qty: inject a real Woo qty input ONLY if the button HTML has none ===
add_filter( 'woocommerce_loop_add_to_cart_link', function( $html, $product, $args ) {
    if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
        return $html;
    }

    if ( function_exists( 'is_product' ) && is_product() ) {
        return $html;
    }

    $in_catalog = is_search()
        || ( function_exists( 'is_shop' ) && is_shop() )
        || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );

    if ( ! $in_catalog ) {
        return $html;
    }

    if ( ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() || $product->is_sold_individually() ) {
        return $html;
    }

    $has_qty = ( stripos( $html, 'class="quantity' ) !== false )
            || ( stripos( $html, "class='quantity" ) !== false )
            || ( stripos( $html, 'class="qty' ) !== false )
            || ( stripos( $html, "class='qty" ) !== false )
            || ( stripos( $html, 'name="quantity' ) !== false )
            || ( stripos( $html, "name='quantity" ) !== false );

    wso_log( 'qty:inject_check', array(
        'product_id' => $product->get_id(),
        'has_qty'    => $has_qty ? 1 : 0,
        'ajax'       => ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? 1 : 0,
    ) );

    if ( $has_qty ) {
        return $html;
    }

    $qty_html = woocommerce_quantity_input( array(
        'input_value' => 1,
    ), $product, false );

    if ( empty( $qty_html ) ) {
        return $html;
    }

    $out = '<div class="wso-loop-quantity-wrapper">' . $qty_html . '</div>' . $html;

    wso_log( 'qty:injected_loop_quantity', array(
        'product_id' => $product->get_id(),
        'ajax'       => ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? 1 : 0,
    ) );

    return $out;
}, 20, 3 );

// Print stock HTML in loop items when Elementor/Woo redraw via AJAX omits it.
add_action( 'woocommerce_after_shop_loop_item_title', function() {
    if ( function_exists( 'is_product' ) && is_product() ) {
        return;
    }

    $is_catalog = is_search()
        || ( function_exists( 'is_shop' ) && is_shop() )
        || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );

    if ( ! $is_catalog ) {
        return;
    }

    $inject_on_ajax_only = apply_filters( 'woo_search_opt_inject_stock_on_ajax_only', true );
    $is_ajax             = defined( 'DOING_AJAX' ) && DOING_AJAX;

    if ( $inject_on_ajax_only && ! $is_ajax ) {
        return;
    }

    global $product;

    if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
        return;
    }

    $stock_html = wc_get_stock_html( $product );

    if ( empty( $stock_html ) ) {
        return;
    }

    echo '<div class="wso-loop-stock">' . $stock_html . '</div>';

    wso_log( 'stock:printed_in_loop', array(
        'product_id' => $product->get_id(),
        'ajax'       => $is_ajax ? 1 : 0,
        'context'    => is_search() ? 'search' : ( function_exists( 'is_shop' ) && is_shop() ? 'shop' : 'tax' ),
    ) );
}, 22 );

// Ensure add_to_cart anchors pick up the closest qty value
add_action( 'wp_enqueue_scripts', function() {
    $handle = 'wso-qty-sync';
    if ( wp_script_is( $handle, 'enqueued' ) ) {
        return;
    }
    wp_register_script( $handle, false, array( 'jquery' ), '1.0', true );
    wp_add_inline_script( $handle, <<<'JS'
jQuery(document).off('click.wsoQtySync','.add_to_cart_button').on('click.wsoQtySync','.add_to_cart_button',function(){
    var $btn=jQuery(this), $qty=$btn.closest('li.product, .product, .woocommerce').find('.quantity input.qty').first();
    if($qty.length){ var v=$qty.val(); $btn.attr('data-quantity',v).data('quantity',v); }
});
JS
    );
    wp_enqueue_script( $handle );
}, 30 );

// === Populate Woo loop props for product loops (helps result count on AJAX redraws) ===
add_action( 'loop_start', function( $query ) {
    if ( ! ( $query instanceof WP_Query ) ) {
        return;
    }

    $pt = $query->get( 'post_type', null );
    $is_product_loop = ( $pt === 'product' )
        || ( is_array( $pt ) && in_array( 'product', $pt, true ) )
        || ( $query->get( 'wc_query' ) === 'product_query' );

    if ( ! $is_product_loop ) {
        return;
    }

    if ( function_exists( 'wc_set_loop_prop' ) ) {
        $total        = (int) $query->found_posts;
        $per_page     = (int) $query->get( 'posts_per_page', 0 );
        $current_page = max( 1, (int) $query->get( 'paged', 1 ) );

        wc_set_loop_prop( 'total', $total );
        wc_set_loop_prop( 'per_page', $per_page );
        wc_set_loop_prop( 'current_page', $current_page );

        wso_log( 'loop_start:set_loop_props', array(
            'total'        => $total,
            'per_page'     => $per_page,
            'current_page' => $current_page,
        ) );
    }
}, 5 );

// === Replace result count using Woo loop props (fallback safe) ===
add_filter( 'woocommerce_result_count', function( $html ) {
    $total        = function_exists( 'wc_get_loop_prop' ) ? wc_get_loop_prop( 'total', null ) : null;
    $per_page     = function_exists( 'wc_get_loop_prop' ) ? wc_get_loop_prop( 'per_page', null ) : null;
    $current_page = function_exists( 'wc_get_loop_prop' ) ? wc_get_loop_prop( 'current_page', null ) : null;

    $used_loop_props = ( $total !== null && $per_page !== null && $current_page !== null );

    if ( ! $used_loop_props ) {
        global $wp_query;
        $total        = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
        $per_page     = (int) get_query_var( 'posts_per_page', 0 );
        $current_page = max( 1, (int) get_query_var( 'paged', 1 ) );
    }

    $first = 0;
    $last  = 0;
    if ( $total > 0 && $per_page > 0 ) {
        $first = ( $per_page * ( $current_page - 1 ) ) + 1;
        $last  = min( $total, $per_page * $current_page );
    }
    if ( $last < $first ) {
        $first = ( $total > 0 ) ? 1 : 0;
        $last  = ( $total > 0 ) ? min( $total, $per_page ?: $total ) : 0;
    }

    $text = ( $total <= $per_page )
        ? sprintf( __( 'Showing all %d results', 'woocommerce' ), $total )
        : sprintf( __( 'Showing %1$d–%2$d of %3$d results', 'woocommerce' ), $first, $last, $total );

    wso_log( 'result_count:computed', array(
        'used_loop_props' => $used_loop_props ? 1 : 0,
        'total'           => $total,
        'per_page'        => $per_page,
        'current_page'    => $current_page,
        'first'           => $first,
        'last'            => $last,
        'is_search'       => is_search() ? 1 : 0,
    ) );

    return '<p class="woocommerce-result-count">' . esc_html( $text ) . '</p>';
}, 10, 1 );
