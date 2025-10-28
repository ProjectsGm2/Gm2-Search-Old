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
 * Recursively search a payload for the first non-empty search phrase.
 *
 * @param mixed $payload Payload data to inspect.
 * @param array $keys    Search term keys to inspect.
 * @param int   $depth   Current recursion depth.
 *
 * @return string
 */
function woo_search_opt_find_search_term_in_payload( $payload, array $keys, $depth = 0 ) {
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

        $nested = woo_search_opt_find_search_term_in_payload( $value, $keys, $depth + 1 );

        if ( '' !== $nested ) {
            return $nested;
        }
    }

    return '';
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
    if ( ! isset( $_REQUEST['orderby'] ) ) {
        return '';
    }

    $requested = sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) );

    if ( ! is_string( $requested ) ) {
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
            'request_vars'         => woo_search_opt_collect_request_vars( array( 's', 'gm2_search_term', 'post_type', 'orderby', 'order', 'elementor_ajax', 'action' ) ),
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

        $pagination_adjusted = false;

        if ( '' !== $resolved_search && woo_search_opt_is_product_query( $query ) ) {
            $no_found_rows = $query->get( 'no_found_rows' );

            if ( $no_found_rows ) {
                $query->set( 'no_found_rows', false );
                $pagination_adjusted                = true;
                $context['no_found_rows_overridden'] = array(
                    'previous' => woo_search_opt_normalize_context_value( $no_found_rows ),
                    'current'  => false,
                );
            }
        }

        $context['decision'] = 'target_query';

        if ( $pagination_adjusted ) {
            $context['pagination_adjusted'] = true;
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

    wp_add_inline_script( 'jquery', $script );
}
add_action( 'wp_enqueue_scripts', 'woo_search_opt_enqueue_frontend_script' );
