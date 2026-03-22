<?php
/**
 * Search Handler Class
 *
 * Handles AJAX search requests and queries with comprehensive security:
 * nonce verification, input sanitization, prepared SQL statements,
 * output escaping, rate limiting, and whitelist validation.
 *
 * @package WC_Custom_AJAX_Search
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAS_Search_Handler {
    
    private $config;
    
    /**
     * Maximum allowed search term length
     */
    const MAX_SEARCH_LENGTH = 100;
    
    /**
     * Rate limiting: max requests per minute per IP
     */
    const RATE_LIMIT_REQUESTS = 30;
    const RATE_LIMIT_WINDOW = 60; // seconds

    /**
     * Cache TTL for search results (seconds)
     */
    const CACHE_TTL = 60;
    
    public function __construct() {
        $this->config = wcas_get_config();

        // Register AJAX handlers
        add_action('wp_ajax_wcas_search', array($this, 'handle_search'));
        add_action('wp_ajax_nopriv_wcas_search', array($this, 'handle_search'));
        add_action('wp_ajax_wcas_add_to_cart', array($this, 'handle_add_to_cart'));
        add_action('wp_ajax_nopriv_wcas_add_to_cart', array($this, 'handle_add_to_cart'));

        // Invalidate search cache when products change
        add_action('woocommerce_update_product', array($this, 'flush_search_cache'));
        add_action('woocommerce_new_product', array($this, 'flush_search_cache'));
        add_action('before_delete_post', array($this, 'flush_search_cache'));
        add_action('edited_term', array($this, 'flush_search_cache'));
    }
    
    /**
     * Set security headers on AJAX responses
     */
    private function set_security_headers() {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }

    /**
     * Flush all cached search results.
     * Called when products, terms, or taxonomy data changes.
     */
    public function flush_search_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_wcas_r_%'
             OR option_name LIKE '_transient_timeout_wcas_r_%'"
        );
    }

    /**
     * Main AJAX search handler
     */
    public function handle_search() {
        // 0. Enforce POST method only
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(array('message' => 'Method not allowed'), 405);
            exit;
        }

        // Set security headers on AJAX response
        $this->set_security_headers();

        // 1. Verify nonce (CSRF protection)
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wcas_search_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'), 403);
            exit;
        }

        // 2. Rate limiting check
        if ($this->is_rate_limited()) {
            wp_send_json_error(array('message' => 'Too many requests. Please slow down.'), 429);
            exit;
        }
        
        // 3. Validate and sanitize search term
        $search_term = $this->sanitize_search_term(
            isset($_POST['search']) ? wp_unslash($_POST['search']) : ''
        );
        
        // 4. Validate search term length
        if ($search_term === false) {
            wp_send_json_error(array('message' => 'Invalid search term'), 400);
            exit;
        }
        
        if (mb_strlen($search_term, 'UTF-8') < $this->config['min_chars']) {
            wp_send_json_error(array('message' => 'Search term too short'), 400);
            exit;
        }

        if (mb_strlen($search_term, 'UTF-8') > self::MAX_SEARCH_LENGTH) {
            wp_send_json_error(array('message' => 'Search term too long'), 400);
            exit;
        }
        
        // 5. Check cache first
        $cache_key = 'wcas_r_' . md5($search_term . WCAS_VERSION);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            wp_send_json_success($cached);
            return;
        }

        // 6. Perform search with sanitized input
        $results = array(
            'categories' => $this->config['search_categories'] ? $this->search_taxonomy('product_cat', $search_term) : array(),
            'tags' => $this->config['search_tags'] ? $this->search_taxonomy('product_tag', $search_term) : array(),
            'custom_taxonomies' => $this->search_custom_taxonomies($search_term),
            'products' => $this->search_products($search_term),
            'total_count' => 0,
            'search_url' => esc_url(add_query_arg('s', rawurlencode($search_term), wc_get_page_permalink('shop'))),
            'suggestions' => null,
        );

        // Calculate total count
        $results['total_count'] = $this->get_total_products_count($search_term);

        // If no results and suggestions enabled, return popular products + top categories
        $has_results = !empty($results['categories']) || !empty($results['tags'])
            || !empty($results['custom_taxonomies']) || !empty($results['products']);

        if (!$has_results && !empty($this->config['enable_no_results_suggestions'])) {
            $results['suggestions'] = $this->get_suggestions();
        }

        // Cache results
        set_transient($cache_key, $results, self::CACHE_TTL);

        wp_send_json_success($results);
    }
    
    /**
     * Sanitize and validate search term
     *
     * Defense-in-depth: even though we use $wpdb->prepare() for all queries,
     * we still block obvious attack payloads at the input layer to:
     * 1. Reduce noise hitting the database
     * 2. Log/flag attackers early
     * 3. Protect against any future code that might bypass prepare()
     *
     * @param string $term Raw search term
     * @return string|false Sanitized term or false if invalid
     */
    private function sanitize_search_term($term) {
        // Reject non-string input
        if (!is_string($term)) {
            return false;
        }

        // Remove null bytes and all ASCII control characters (0x00-0x1F except tab/newline, 0x7F)
        $term = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $term);

        // Detect double-encoding attacks (%2527 = double-encoded single quote)
        $decoded_once = rawurldecode($term);
        if ($decoded_once !== rawurldecode($decoded_once)) {
            $this->log_suspicious_activity($term, 'Double-encoding attack attempt');
            return false;
        }

        // WordPress sanitization (strips tags, encodes special chars, etc.)
        $term = sanitize_text_field($term);

        // Trim and collapse whitespace
        $term = trim($term);
        $term = preg_replace('/\s+/', ' ', $term);

        // Enforce length limit using multibyte-safe strlen
        if (mb_strlen($term, 'UTF-8') > self::MAX_SEARCH_LENGTH) {
            $term = mb_substr($term, 0, self::MAX_SEARCH_LENGTH, 'UTF-8');
        }

        // Reject empty after sanitization
        if ($term === '') {
            return false;
        }

        // Block SQL injection patterns (defense-in-depth — queries use prepare())
        $dangerous_patterns = array(
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
            '/((\%3D)|(=))[^\n]*((\%27)|(\')|(\-\-)|(\%3B)|(;))/i',
            '/\w*((\%27)|(\'))((\%6F)|o|(\%4F))((\%72)|r|(\%52))/i',
            '/((\%27)|(\'))union/i',
            '/exec(\s|\+)+(s|x)p\w+/i',
            '/UNION[\s\/\*]+SELECT/i',
            '/INSERT[\s\/\*]+INTO/i',
            '/DELETE[\s\/\*]+FROM/i',
            '/DROP[\s\/\*]+TABLE/i',
            '/UPDATE[\s\/\*]+\w+[\s\/\*]+SET/i',
            '/LOAD_FILE\s*\(/i',
            '/INTO\s+(OUT|DUMP)FILE/i',
            '/BENCHMARK\s*\(/i',
            '/SLEEP\s*\(/i',
            '/0x[0-9a-f]{8,}/i',              // long hex literals (common in injection)
            '/CHAR\s*\(\s*\d+/i',             // CHAR() obfuscation
        );

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $term)) {
                $this->log_suspicious_activity($term, 'SQL injection attempt');
                return false;
            }
        }

        // Block XSS patterns
        $xss_patterns = array(
            '/<script/i',
            '/javascript\s*:/i',
            '/on\w+\s*=/i',                   // inline event handlers
            '/data\s*:\s*text\/html/i',        // data URI XSS
            '/expression\s*\(/i',              // CSS expression()
            '/vbscript\s*:/i',
        );

        foreach ($xss_patterns as $pattern) {
            if (preg_match($pattern, $term)) {
                $this->log_suspicious_activity($term, 'XSS attempt');
                return false;
            }
        }

        // Strip any remaining HTML tags (belt and suspenders)
        $term = wp_strip_all_tags($term);

        return $term;
    }
    
    /**
     * Check if current request is rate limited
     * Uses direct DB query for atomic increment to prevent race conditions
     * under burst traffic (get_transient + set_transient is not atomic)
     *
     * @return bool True if rate limited
     */
    private function is_rate_limited() {
        global $wpdb;

        $ip = $this->get_client_ip();
        $transient_key = '_transient_wcas_rate_' . md5($ip);
        $timeout_key = '_transient_timeout_wcas_rate_' . md5($ip);
        $now = time();

        // Atomic: try to increment if the transient exists and hasn't expired
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = option_value + 1
             WHERE option_name = %s
             AND EXISTS (
                 SELECT 1 FROM (SELECT option_value FROM {$wpdb->options} WHERE option_name = %s) AS t
                 WHERE t.option_value > %d
             )",
            $transient_key,
            $timeout_key,
            $now
        ));

        if ($updated) {
            // Row existed and was incremented — check current count
            $count = (int) get_option($transient_key, 0);
            if ($count > self::RATE_LIMIT_REQUESTS) {
                $this->log_suspicious_activity($ip, 'Rate limit exceeded');
                return true;
            }
            return false;
        }

        // No existing transient or it expired — create fresh via WordPress API
        // Delete stale entries first
        delete_transient('wcas_rate_' . md5($ip));
        set_transient('wcas_rate_' . md5($ip), 1, self::RATE_LIMIT_WINDOW);
        return false;
    }
    
    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Log suspicious activity
     * Always logs security events (not gated behind WP_DEBUG) so that
     * attacks are visible in production. Uses error_log() which writes
     * to the PHP error log configured by the server.
     *
     * Also stores a rolling count in a transient so admins can see
     * recent attack volume without digging through log files.
     *
     * @param string $data Data to log (truncated to 100 chars)
     * @param string $type Type of suspicious activity
     */
    private function log_suspicious_activity($data, $type) {
        // Always log security events to PHP error log
        error_log(sprintf(
            '[WC AJAX Search] SECURITY — %s | Data: %s | IP: %s | URI: %s | Time: %s',
            $type,
            substr($data, 0, 100),
            $this->get_client_ip(),
            isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : 'unknown',
            current_time('mysql')
        ));

        // Increment rolling counter for admin visibility
        $count = (int) get_transient('wcas_suspicious_count');
        set_transient('wcas_suspicious_count', $count + 1, DAY_IN_SECONDS);
    }
    
    /**
     * Validate taxonomy against whitelist
     * 
     * @param string $taxonomy Taxonomy slug
     * @return bool True if valid
     */
    private function is_valid_taxonomy($taxonomy) {
        $allowed = array_merge(
            array('product_cat', 'product_tag'),
            $this->config['custom_taxonomies']
        );
        return in_array($taxonomy, $allowed, true) && taxonomy_exists($taxonomy);
    }
    
    /**
     * Validate meta key against whitelist
     * 
     * @param string $meta_key Meta key
     * @return bool True if valid
     */
    private function is_valid_meta_key($meta_key) {
        $allowed = array_merge(
            array('_sku'),
            $this->config['acf_fields']
        );
        return in_array($meta_key, $allowed, true);
    }
    
    /**
     * Search in a taxonomy
     */
    private function search_taxonomy($taxonomy, $search_term) {
        // Validate taxonomy against whitelist
        if (!$this->is_valid_taxonomy($taxonomy)) {
            return array();
        }
        
        global $wpdb;
        
        // Use prepared statement with proper escaping
        $escaped_like = '%' . $wpdb->esc_like($search_term) . '%';
        $limit = absint($this->config['max_terms_per_taxonomy']);
        
        $terms = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, tt.count
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = %s
             AND t.name LIKE %s
             AND tt.count > 0
             ORDER BY tt.count DESC
             LIMIT %d",
            $taxonomy,
            $escaped_like,
            $limit
        ));
        
        $results = array();
        foreach ($terms as $term) {
            $term_link = get_term_link((int)$term->term_id, $taxonomy);
            
            // Skip if term link is an error
            if (is_wp_error($term_link)) {
                continue;
            }
            
            $results[] = array(
                'id' => absint($term->term_id),
                'name' => $this->highlight_term($term->name, $search_term),
                'name_raw' => esc_html($term->name),
                'slug' => esc_attr($term->slug),
                'count' => absint($term->count),
                'url' => esc_url($term_link),
            );
        }
        
        return $results;
    }
    
    /**
     * Search in custom taxonomies
     */
    private function search_custom_taxonomies($search_term) {
        $results = array();
        
        foreach ($this->config['custom_taxonomies'] as $taxonomy) {
            // Validate each taxonomy
            if (!$this->is_valid_taxonomy($taxonomy)) {
                continue;
            }
            
            $tax_obj = get_taxonomy($taxonomy);
            if (!$tax_obj) {
                continue;
            }
            
            $terms = $this->search_taxonomy($taxonomy, $search_term);
            
            if (!empty($terms)) {
                $results[] = array(
                    'taxonomy' => esc_attr($taxonomy),
                    'label' => esc_html($tax_obj->labels->name),
                    'terms' => $terms,
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Search products
     */
    private function search_products($search_term) {
        global $wpdb;

        $escaped_like = '%' . $wpdb->esc_like($search_term) . '%';
        $starts_with_like = $wpdb->esc_like($search_term) . '%';
        $limit = absint($this->config['max_products']);

        // Build WHERE conditions dynamically based on settings
        $or_conditions = array();
        $query_values = array();

        // Relevance scoring values (always based on title)
        $query_values[] = $starts_with_like;
        $query_values[] = $escaped_like;

        // Post type and status
        $query_values[] = 'product';
        $query_values[] = 'publish';

        // Title search
        if (!empty($this->config['search_title'])) {
            $or_conditions[] = "p.post_title LIKE %s";
            $query_values[] = $escaped_like;
        }

        // Content search
        if (!empty($this->config['search_content'])) {
            $or_conditions[] = "p.post_content LIKE %s";
            $query_values[] = $escaped_like;
        }

        // Excerpt search
        if (!empty($this->config['search_excerpt'])) {
            $or_conditions[] = "p.post_excerpt LIKE %s";
            $query_values[] = $escaped_like;
        }

        // Meta query (SKU + ACF fields)
        $meta_conditions = array();
        $meta_values = array();

        if (!empty($this->config['search_sku'])) {
            $meta_conditions[] = "(pm.meta_key = %s AND pm.meta_value LIKE %s)";
            $meta_values[] = '_sku';
            $meta_values[] = $escaped_like;
        }

        foreach ($this->config['acf_fields'] as $field) {
            if (!$this->is_valid_meta_key($field)) {
                continue;
            }
            $meta_conditions[] = "(pm.meta_key = %s AND pm.meta_value LIKE %s)";
            $meta_values[] = $field;
            $meta_values[] = $escaped_like;
        }

        if (!empty($meta_conditions)) {
            $meta_sql = implode(' OR ', $meta_conditions);
            $or_conditions[] = "p.ID IN (SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm WHERE {$meta_sql})";
            $query_values = array_merge($query_values, $meta_values);
        }

        // Taxonomy query
        $tax_conditions = array();
        $tax_values_arr = array();

        $all_taxonomies = array();
        if (!empty($this->config['search_categories'])) {
            $all_taxonomies[] = 'product_cat';
        }
        if (!empty($this->config['search_tags'])) {
            $all_taxonomies[] = 'product_tag';
        }
        $all_taxonomies = array_merge($all_taxonomies, $this->config['custom_taxonomies']);

        foreach ($all_taxonomies as $taxonomy) {
            if (!$this->is_valid_taxonomy($taxonomy)) {
                continue;
            }
            $tax_conditions[] = "(tt.taxonomy = %s AND t.name LIKE %s)";
            $tax_values_arr[] = $taxonomy;
            $tax_values_arr[] = $escaped_like;
        }

        if (!empty($tax_conditions)) {
            $tax_sql = implode(' OR ', $tax_conditions);
            $or_conditions[] = "p.ID IN (SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id WHERE {$tax_sql})";
            $query_values = array_merge($query_values, $tax_values_arr);
        }

        // If no search conditions are enabled, return empty
        if (empty($or_conditions)) {
            return array();
        }

        $where_or = implode("\n                OR ", $or_conditions);

        $query = "
            SELECT DISTINCT p.ID, p.post_title,
                   CASE
                       WHEN p.post_title LIKE %s THEN 1
                       WHEN p.post_title LIKE %s THEN 2
                       ELSE 3
                   END as relevance
            FROM {$wpdb->posts} p
            WHERE p.post_type = %s
            AND p.post_status = %s
            AND (
                {$where_or}
            )
            ORDER BY relevance ASC, p.post_title ASC
            LIMIT %d
        ";

        $query_values[] = $limit;

        $product_ids = $wpdb->get_results($wpdb->prepare($query, $query_values));

        $products = array();
        foreach ($product_ids as $row) {
            $product = wc_get_product(absint($row->ID));
            if (!$product || !$product->is_visible()) {
                continue;
            }

            $products[] = $this->format_product($product, $search_term);
        }

        return $products;
    }
    
    /**
     * Format product data for response
     */
    private function format_product($product, $search_term) {
        $image_id = $product->get_image_id();
        $placeholder = wc_placeholder_img_src('thumbnail');
        $placeholder_large = wc_placeholder_img_src('medium');

        // wp_get_attachment_image_url() can return false if attachment was deleted
        $image_url = $image_id ? (wp_get_attachment_image_url($image_id, 'thumbnail') ?: $placeholder) : $placeholder;
        $image_url_large = $image_id ? (wp_get_attachment_image_url($image_id, 'medium') ?: $placeholder_large) : $placeholder_large;
        
        // Get matched ACF field value if any
        $matched_field = $this->get_matched_acf_field($product->get_id(), $search_term);
        
        // Sanitize all output data
        return array(
            'id' => absint($product->get_id()),
            'name' => $this->highlight_term($product->get_name(), $search_term),
            'name_raw' => esc_html($product->get_name()),
            'url' => esc_url($product->get_permalink()),
            'image' => esc_url($image_url),
            'image_large' => esc_url($image_url_large),
            'price' => wp_kses_post($product->get_price_html()), // Allow limited HTML for price
            'price_raw' => floatval($product->get_price()),
            'sku' => esc_html($product->get_sku()),
            'description' => esc_html(wp_trim_words(wp_strip_all_tags($product->get_short_description()), 20, '...')),
            'description_full' => wp_kses_post($product->get_short_description()),
            'in_stock' => (bool) $product->is_in_stock(),
            'is_purchasable' => (bool) $product->is_purchasable(),
            'add_to_cart_url' => esc_url($product->add_to_cart_url()),
            'matched_field' => $matched_field,
            'type' => esc_attr($product->get_type()),
        );
    }
    
    /**
     * Get matched ACF field name and value
     */
    private function get_matched_acf_field($product_id, $search_term) {
        foreach ($this->config['acf_fields'] as $field) {
            // Validate field against whitelist
            if (!$this->is_valid_meta_key($field)) {
                continue;
            }
            
            $value = get_field($field, $product_id);
            if ($value && is_string($value) && stripos($value, $search_term) !== false) {
                // Get field label if ACF function exists
                $field_obj = function_exists('get_field_object') ? get_field_object($field, $product_id) : null;
                $label = $field_obj ? $field_obj['label'] : ucfirst(str_replace('_', ' ', $field));
                
                return array(
                    'label' => esc_html($label),
                    'value' => $this->highlight_term($value, $search_term),
                );
            }
        }
        return null;
    }
    
    /**
     * Get total count of matching products
     */
    private function get_total_products_count($search_term) {
        global $wpdb;

        $escaped_like = '%' . $wpdb->esc_like($search_term) . '%';

        // Build WHERE conditions dynamically (mirrors search_products logic)
        $or_conditions = array();
        $query_values = array('product', 'publish');

        if (!empty($this->config['search_title'])) {
            $or_conditions[] = "p.post_title LIKE %s";
            $query_values[] = $escaped_like;
        }

        if (!empty($this->config['search_content'])) {
            $or_conditions[] = "p.post_content LIKE %s";
            $query_values[] = $escaped_like;
        }

        if (!empty($this->config['search_excerpt'])) {
            $or_conditions[] = "p.post_excerpt LIKE %s";
            $query_values[] = $escaped_like;
        }

        // Meta conditions
        $meta_conditions = array();
        $meta_values = array();

        if (!empty($this->config['search_sku'])) {
            $meta_conditions[] = "(pm.meta_key = %s AND pm.meta_value LIKE %s)";
            $meta_values[] = '_sku';
            $meta_values[] = $escaped_like;
        }

        foreach ($this->config['acf_fields'] as $field) {
            if (!$this->is_valid_meta_key($field)) {
                continue;
            }
            $meta_conditions[] = "(pm.meta_key = %s AND pm.meta_value LIKE %s)";
            $meta_values[] = $field;
            $meta_values[] = $escaped_like;
        }

        if (!empty($meta_conditions)) {
            $meta_sql = implode(' OR ', $meta_conditions);
            $or_conditions[] = "p.ID IN (SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm WHERE {$meta_sql})";
            $query_values = array_merge($query_values, $meta_values);
        }

        // Taxonomy conditions
        $tax_conditions = array();
        $tax_values_arr = array();

        $all_taxonomies = array();
        if (!empty($this->config['search_categories'])) {
            $all_taxonomies[] = 'product_cat';
        }
        if (!empty($this->config['search_tags'])) {
            $all_taxonomies[] = 'product_tag';
        }
        $all_taxonomies = array_merge($all_taxonomies, $this->config['custom_taxonomies']);

        foreach ($all_taxonomies as $taxonomy) {
            if (!$this->is_valid_taxonomy($taxonomy)) {
                continue;
            }
            $tax_conditions[] = "(tt.taxonomy = %s AND t.name LIKE %s)";
            $tax_values_arr[] = $taxonomy;
            $tax_values_arr[] = $escaped_like;
        }

        if (!empty($tax_conditions)) {
            $tax_sql = implode(' OR ', $tax_conditions);
            $or_conditions[] = "p.ID IN (SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id WHERE {$tax_sql})";
            $query_values = array_merge($query_values, $tax_values_arr);
        }

        if (empty($or_conditions)) {
            return 0;
        }

        $where_or = implode("\n                OR ", $or_conditions);

        $query = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            WHERE p.post_type = %s
            AND p.post_status = %s
            AND (
                {$where_or}
            )
        ";

        return absint($wpdb->get_var($wpdb->prepare($query, $query_values)));
    }
    
    /**
     * Get suggestions for no-results state
     * Returns popular products and top categories
     */
    private function get_suggestions() {
        $suggestions = array(
            'popular_products' => array(),
            'top_categories'   => array(),
        );

        // Popular products — best-selling or most recent
        $popular_args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 4,
            'meta_key'       => 'total_sales',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
        );

        $popular_query = new WP_Query($popular_args);

        // Fallback to recent products if no sales data
        if (!$popular_query->have_posts()) {
            $popular_query = new WP_Query(array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 4,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ));
        }

        if ($popular_query->have_posts()) {
            while ($popular_query->have_posts()) {
                $popular_query->the_post();
                $product = wc_get_product(get_the_ID());
                if (!$product || !$product->is_visible()) {
                    continue;
                }
                $image_id = $product->get_image_id();
                $fallback = wc_placeholder_img_src('thumbnail');
                $image_url = $image_id ? (wp_get_attachment_image_url($image_id, 'thumbnail') ?: $fallback) : $fallback;

                $suggestions['popular_products'][] = array(
                    'id'    => absint($product->get_id()),
                    'name'  => esc_html($product->get_name()),
                    'url'   => esc_url($product->get_permalink()),
                    'image' => esc_url($image_url),
                    'price' => wp_kses_post($product->get_price_html()),
                );
            }
            wp_reset_postdata();
        }

        // Top categories by product count
        $top_cats = get_terms(array(
            'taxonomy'   => 'product_cat',
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => 5,
            'hide_empty' => true,
        ));

        if (!is_wp_error($top_cats)) {
            foreach ($top_cats as $cat) {
                $cat_link = get_term_link($cat);
                if (is_wp_error($cat_link)) {
                    continue;
                }
                $suggestions['top_categories'][] = array(
                    'id'    => absint($cat->term_id),
                    'name'  => esc_html($cat->name),
                    'url'   => esc_url($cat_link),
                    'count' => absint($cat->count),
                );
            }
        }

        return $suggestions;
    }

    /**
     * Highlight search term in text
     * Safe output with proper escaping
     */
    private function highlight_term($text, $search_term) {
        // First escape the text
        $escaped_text = esc_html($text);

        // Then escape the search term for use in regex
        $escaped_term = preg_quote(esc_html($search_term), '/');

        // Perform highlight with safe HTML
        return preg_replace(
            '/(' . $escaped_term . ')/i',
            '<strong>$1</strong>',
            $escaped_text
        );
    }

    /**
     * Handle AJAX add to cart
     */
    public function handle_add_to_cart() {
        check_ajax_referer('wcas_search_nonce', 'nonce');

        $product_id = absint($_POST['product_id'] ?? 0);
        $quantity   = max(1, absint($_POST['quantity'] ?? 1));

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'wc-custom-ajax-search')));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => __('Product not found.', 'wc-custom-ajax-search')));
        }

        if (!$product->is_type('simple')) {
            wp_send_json_error(array(
                'message'     => __('Please select options on the product page.', 'wc-custom-ajax-search'),
                'product_url' => $product->get_permalink(),
            ));
        }

        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            wp_send_json_error(array('message' => __('This product cannot be added to cart.', 'wc-custom-ajax-search')));
        }

        // Ensure cart session is initialized
        if (is_null(WC()->cart)) {
            wc_load_cart();
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);

        if (!$cart_item_key) {
            wp_send_json_error(array('message' => __('Could not add to cart.', 'wc-custom-ajax-search')));
        }

        do_action('woocommerce_ajax_added_to_cart', $product_id);

        wp_send_json(array(
            'success'   => true,
            'fragments' => apply_filters('woocommerce_add_to_cart_fragments', array()),
            'cart_hash' => WC()->cart->get_cart_hash(),
        ));
    }
}
