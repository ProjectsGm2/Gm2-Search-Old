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
 * Determine whether a query targets WooCommerce products.
 *
 * @param WP_Query $query Query instance.
 *
 * @return bool
 */
function woo_search_opt_query_targets_products( $query ) {
    if ( ! $query instanceof WP_Query ) {
        return false;
    }

    $post_type = $query->get( 'post_type' );

    if ( is_array( $post_type ) ) {
        if ( in_array( 'product', $post_type, true ) ) {
            return true;
        }
    } elseif ( 'product' === $post_type ) {
        return true;
    }

    $tax_query = $query->get( 'tax_query' );

    if ( is_array( $tax_query ) ) {
        $stack = $tax_query;

        while ( ! empty( $stack ) ) {
            $item = array_pop( $stack );

            if ( ! is_array( $item ) ) {
                continue;
            }

            if ( isset( $item['taxonomy'] ) && 'product_visibility' === $item['taxonomy'] ) {
                return true;
            }

            foreach ( $item as $value ) {
                if ( is_array( $value ) ) {
                    $stack[] = $value;
                }
            }
        }
    }

    return false;
}

/**
 * Persist the most recent product search phrase for later requests.
 *
 * @param string $search_phrase Search phrase to store.
 *
 * @return void
 */
function woo_search_opt_store_last_search_phrase( $search_phrase ) {
    $sanitized = sanitize_text_field( wp_unslash( $search_phrase ) );

    if ( '' === $sanitized ) {
        woo_search_opt_clear_last_search_phrase();
        return;
    }

    if ( function_exists( 'WC' ) && WC()->session ) {
        WC()->session->set( 'woo_search_opt_last_search', $sanitized );
    }

    if ( function_exists( 'wc_setcookie' ) ) {
        wc_setcookie( 'woo_search_opt_last_search', rawurlencode( $sanitized ), time() + HOUR_IN_SECONDS, is_ssl(), true );
        return;
    }

    if ( headers_sent() ) {
        return;
    }

    $path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
    $domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

    setcookie( 'woo_search_opt_last_search', rawurlencode( $sanitized ), time() + HOUR_IN_SECONDS, $path, $domain, is_ssl(), true );
}

/**
 * Retrieve the persisted product search phrase.
 *
 * @return string
 */
function woo_search_opt_get_last_search_phrase() {
    if ( function_exists( 'WC' ) && WC()->session ) {
        $stored = WC()->session->get( 'woo_search_opt_last_search', '' );
        if ( is_string( $stored ) && '' !== trim( $stored ) ) {
            return sanitize_text_field( $stored );
        }
    }

    if ( isset( $_COOKIE['woo_search_opt_last_search'] ) ) {
        $cookie_value = wp_unslash( $_COOKIE['woo_search_opt_last_search'] );
        $decoded      = rawurldecode( $cookie_value );
        $decoded      = sanitize_text_field( $decoded );

        if ( '' !== $decoded ) {
            return $decoded;
        }
    }

    return '';
}

/**
 * Clear any persisted search phrase.
 *
 * @return void
 */
function woo_search_opt_clear_last_search_phrase() {
    if ( function_exists( 'WC' ) && WC()->session ) {
        WC()->session->set( 'woo_search_opt_last_search', '' );
    }

    if ( function_exists( 'wc_setcookie' ) ) {
        wc_setcookie( 'woo_search_opt_last_search', '', time() - HOUR_IN_SECONDS, is_ssl(), true );
        return;
    }

    if ( headers_sent() ) {
        return;
    }

    $path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
    $domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

    setcookie( 'woo_search_opt_last_search', '', time() - HOUR_IN_SECONDS, $path, $domain, is_ssl(), true );
}

/**
 * Extract a search phrase from the HTTP referer when available.
 *
 * @return string
 */
function woo_search_opt_extract_search_from_referer() {
    if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
        return '';
    }

    $referer = wp_unslash( $_SERVER['HTTP_REFERER'] );
    $parts   = wp_parse_url( $referer );

    if ( empty( $parts['query'] ) ) {
        return '';
    }

    parse_str( $parts['query'], $params );

    if ( empty( $params['s'] ) || ! is_string( $params['s'] ) ) {
        return '';
    }

    return sanitize_text_field( $params['s'] );
}

/**
 * Capture the most recent search phrase for later AJAX sorting requests.
 *
 * @param WP_Query $query Query instance.
 *
 * @return void
 */
function woo_search_opt_track_search_phrase( WP_Query $query ) {
    if ( is_admin() && ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) ) {
        return;
    }

    if ( ! $query->is_main_query() || ! $query->is_search() ) {
        return;
    }

    if ( ! woo_search_opt_query_targets_products( $query ) ) {
        return;
    }

    $search_term   = $query->get( 's' );
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';

    if ( '' === $search_phrase ) {
        return;
    }

    woo_search_opt_store_last_search_phrase( $search_phrase );
}
add_action( 'pre_get_posts', 'woo_search_opt_track_search_phrase', 5 );

/**
 * Restore the stored search phrase for AJAX sorting/filtering requests when missing.
 *
 * @param WP_Query $query Query instance.
 *
 * @return void
 */
function woo_search_opt_restore_search_phrase_for_ajax( WP_Query $query ) {
    if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
        return;
    }

    $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

    if ( 'gm2_filter_products' !== $action ) {
        return;
    }

    if ( ! woo_search_opt_query_targets_products( $query ) ) {
        return;
    }

    $search_term   = $query->get( 's' );
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';

    if ( '' !== $search_phrase ) {
        return;
    }

    $referer_phrase = woo_search_opt_extract_search_from_referer();

    if ( '' === $referer_phrase ) {
        $referer_phrase = woo_search_opt_get_last_search_phrase();
    }

    if ( '' === $referer_phrase ) {
        return;
    }

    $query->set( 's', $referer_phrase );

    $_REQUEST['s'] = $referer_phrase;
    $_GET['s']     = $referer_phrase;

    woo_search_opt_store_last_search_phrase( $referer_phrase );
}
add_action( 'pre_get_posts', 'woo_search_opt_restore_search_phrase_for_ajax', 6 );

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

    $whitelist = is_array( $keys ) ? $keys : array_keys( $request );
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

        $context = array(
            'flags'                => woo_search_opt_extract_query_flags( $query ),
            'request_vars'         => woo_search_opt_collect_request_vars( array( 's', 'post_type', 'orderby', 'elementor_ajax', 'action' ) ),
            'tax_query'            => woo_search_opt_normalize_context_value( $query->get( 'tax_query' ) ),
            'product_visibility'   => false,
            'decision'             => 'pending',
            'elementor_ajax_input' => $is_elementor_ajax,
            'wp_ajax'              => $is_standard_wp_ajax,
        );

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

        $context['decision'] = 'target_query';
        woo_search_opt_log( 'woo_search_opt pre_get_posts target', $context );
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
        woo_search_opt_log( 'woo_search_opt posts_join bail', array(
            'reason' => 'empty_search',
            'flags'  => woo_search_opt_extract_query_flags( $wp_query ),
        ) );
        return $join;
    }

    // Only modify queries for products.
    $post_types = $wp_query->get('post_type');
    if ( (is_array($post_types) && ! in_array('product', $post_types)) ||
         (!is_array($post_types) && 'product' !== $post_types) ) {
        woo_search_opt_log( 'woo_search_opt posts_join bail', array(
            'reason'     => 'non_product_query',
            'flags'      => woo_search_opt_extract_query_flags( $wp_query ),
            'post_types' => woo_search_opt_normalize_context_value( $post_types ),
        ) );
        return $join;
    }

    $initial_join = woo_search_opt_truncate_for_logging( $join );

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

    woo_search_opt_log( 'woo_search_opt posts_join applied', array(
        'flags'          => woo_search_opt_extract_query_flags( $wp_query ),
        'search_term'    => woo_search_opt_sanitize_scalar_for_logging( $search_term ),
        'incoming_join'  => $initial_join,
        'outgoing_join'  => woo_search_opt_truncate_for_logging( $join ),
        'attribute_join' => 'pa_% taxonomy aggregation',
    ) );

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
    $context = array(
        'flags'           => woo_search_opt_extract_query_flags( $wp_query ),
        'incoming_search' => woo_search_opt_truncate_for_logging( $search ),
        'search_phrase'   => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'decision'        => 'pending',
    );
    if ( '' === $search_phrase ) {
        $context['decision'] = 'bail_empty_search';
        woo_search_opt_log( 'woo_search_opt posts_search bail', $context );
        return $search;
    }

    // Only affect product queries.
    $post_types = $wp_query->get('post_type');
    if ( (is_array($post_types) && ! in_array('product', $post_types)) ||
         (!is_array($post_types) && 'product' !== $post_types) ) {
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

    $search_term = $wp_query->get('s');
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';
    $context = array(
        'flags'          => woo_search_opt_extract_query_flags( $wp_query ),
        'search_phrase'  => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'incoming_field' => woo_search_opt_truncate_for_logging( $fields ),
        'decision'       => 'pending',
    );
    if ( '' === $search_phrase ) {
        $context['decision'] = 'bail_empty_search';
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

    $search_term = $wp_query->get('s');
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';
    $context = array(
        'flags'             => woo_search_opt_extract_query_flags( $wp_query ),
        'search_phrase'     => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'incoming_orderby'  => woo_search_opt_truncate_for_logging( $orderby ),
        'decision'          => 'pending',
    );
    if ( '' === $search_phrase ) {
        $context['decision'] = 'bail_empty_search';
        woo_search_opt_log( 'woo_search_opt posts_orderby bail', $context );
        return $orderby;
    }

    $orderby = "title_exact_phrase DESC, title_ordered_phrase DESC, title_all_tokens DESC, title_token_hits DESC, attr_all_tokens DESC, content_all_tokens DESC, overall_token_hits DESC, token_score DESC, relevance DESC, {$wpdb->posts}.post_title ASC";
    $context['decision']        = 'applied';
    $context['outgoing_orderby'] = woo_search_opt_truncate_for_logging( $orderby );
    woo_search_opt_log( 'woo_search_opt posts_orderby applied', $context );
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
    $context = array(
        'flags'            => woo_search_opt_extract_query_flags( $wp_query ),
        'search_phrase'    => woo_search_opt_sanitize_scalar_for_logging( $search_phrase ),
        'incoming_groupby' => woo_search_opt_truncate_for_logging( $groupby ),
        'decision'         => 'pending',
    );
    if ( '' === $search_phrase ) {
        $context['decision'] = 'bail_empty_search';
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
    $search_term   = $wp_query->get( 's' );
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';

    if ( '' === $search_phrase ) {
        woo_search_opt_log( 'woo_search_opt posts_clauses bail', array(
            'reason' => 'empty_search',
            'flags'  => woo_search_opt_extract_query_flags( $wp_query ),
        ) );
        return $clauses;
    }

    $clause_snapshot = array();

    foreach ( array( 'distinct', 'fields', 'join', 'where', 'orderby', 'groupby', 'limits' ) as $key ) {
        if ( isset( $clauses[ $key ] ) ) {
            $clause_snapshot[ $key ] = woo_search_opt_truncate_for_logging( $clauses[ $key ] );
        }
    }

    woo_search_opt_log( 'woo_search_opt posts_clauses snapshot', array(
        'flags'   => woo_search_opt_extract_query_flags( $wp_query ),
        'clauses' => $clause_snapshot,
    ) );

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
    $search_term   = $wp_query->get( 's' );
    $search_phrase = is_string( $search_term ) ? trim( $search_term ) : '';

    if ( '' === $search_phrase ) {
        woo_search_opt_log( 'woo_search_opt posts_results bail', array(
            'reason' => 'empty_search',
            'flags'  => woo_search_opt_extract_query_flags( $wp_query ),
        ) );
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

    woo_search_opt_log( 'woo_search_opt posts_results summary', array(
        'flags'          => woo_search_opt_extract_query_flags( $wp_query ),
        'total_results'  => count( $posts ),
        'logged_results' => $summary,
        'truncated'      => count( $posts ) > $max_results,
    ) );

    return $posts;
}
add_filter( 'posts_results', 'woo_search_opt_posts_results_logger', 20, 2 );
