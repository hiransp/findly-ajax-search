<?php
/**
 * Search Handler Class
 * Handles AJAX search requests and queries
 * 
 * Security measures implemented:
 * - Nonce verification
 * - Input sanitization and validation
 * - Prepared statements for all SQL queries
 * - Output escaping
 * - Rate limiting
 * - Search term length limits
 * - Whitelist validation for taxonomies and meta keys
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
    
    public function __construct() {
        $this->config = wcas_get_config();
        
        // Register AJAX handlers
        add_action('wp_ajax_wcas_search', array($this, 'handle_search'));
        add_action('wp_ajax_nopriv_wcas_search', array($this, 'handle_search'));
    }
    
    /**
     * Main AJAX search handler
     */
    public function handle_search() {
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
        
        if (strlen($search_term) < $this->config['min_chars']) {
            wp_send_json_error(array('message' => 'Search term too short'), 400);
            exit;
        }
        
        if (strlen($search_term) > self::MAX_SEARCH_LENGTH) {
            wp_send_json_error(array('message' => 'Search term too long'), 400);
            exit;
        }
        
        // 5. Perform search with sanitized input
        $results = array(
            'categories' => $this->search_taxonomy('product_cat', $search_term),
            'tags' => $this->search_taxonomy('product_tag', $search_term),
            'custom_taxonomies' => $this->search_custom_taxonomies($search_term),
            'products' => $this->search_products($search_term),
            'total_count' => 0,
            'search_url' => esc_url(add_query_arg('s', rawurlencode($search_term), wc_get_page_permalink('shop'))),
        );
        
        // Calculate total count
        $results['total_count'] = $this->get_total_products_count($search_term);
        
        wp_send_json_success($results);
    }
    
    /**
     * Sanitize and validate search term
     * 
     * @param string $term Raw search term
     * @return string|false Sanitized term or false if invalid
     */
    private function sanitize_search_term($term) {
        // Remove null bytes and other dangerous characters
        $term = str_replace(chr(0), '', $term);
        
        // Basic sanitization
        $term = sanitize_text_field($term);
        
        // Trim whitespace
        $term = trim($term);
        
        // Remove multiple consecutive spaces
        $term = preg_replace('/\s+/', ' ', $term);
        
        // Check for potentially malicious patterns
        // Block SQL injection attempts
        $dangerous_patterns = array(
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',  // SQL meta characters
            '/((\%3D)|(=))[^\n]*((\%27)|(\')|(\-\-)|(\%3B)|(;))/i', // SQL injection
            '/\w*((\%27)|(\'))((\%6F)|o|(\%4F))((\%72)|r|(\%52))/i', // SQL OR
            '/((\%27)|(\'))union/i', // SQL UNION
            '/exec(\s|\+)+(s|x)p\w+/i', // SQL stored procedure
            '/UNION(\s+)SELECT/i',
            '/INSERT(\s+)INTO/i',
            '/DELETE(\s+)FROM/i',
            '/DROP(\s+)TABLE/i',
            '/UPDATE(\s+)\w+(\s+)SET/i',
        );
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $term)) {
                // Log suspicious activity
                $this->log_suspicious_activity($term, 'SQL injection attempt');
                return false;
            }
        }
        
        // Additional XSS protection - remove any HTML/script tags
        $term = wp_strip_all_tags($term);
        
        return $term;
    }
    
    /**
     * Check if current request is rate limited
     * 
     * @return bool True if rate limited
     */
    private function is_rate_limited() {
        $ip = $this->get_client_ip();
        $transient_key = 'wcas_rate_' . md5($ip);
        
        $requests = get_transient($transient_key);
        
        if ($requests === false) {
            // First request
            set_transient($transient_key, 1, self::RATE_LIMIT_WINDOW);
            return false;
        }
        
        if ($requests >= self::RATE_LIMIT_REQUESTS) {
            $this->log_suspicious_activity($ip, 'Rate limit exceeded');
            return true;
        }
        
        // Increment counter
        set_transient($transient_key, $requests + 1, self::RATE_LIMIT_WINDOW);
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
     * 
     * @param string $data Data to log
     * @param string $type Type of suspicious activity
     */
    private function log_suspicious_activity($data, $type) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WC Custom AJAX Search] Suspicious activity detected - Type: %s, Data: %s, IP: %s, Time: %s',
                $type,
                substr($data, 0, 100), // Truncate for safety
                $this->get_client_ip(),
                current_time('mysql')
            ));
        }
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
        
        // Build meta query for ACF fields - only use whitelisted fields
        $meta_conditions = array();
        $meta_values = array();
        $escaped_like = '%' . $wpdb->esc_like($search_term) . '%';
        
        foreach ($this->config['acf_fields'] as $field) {
            // Validate field against whitelist
            if (!$this->is_valid_meta_key($field)) {
                continue;
            }
            $meta_conditions[] = "(pm.meta_key = %s AND pm.meta_value LIKE %s)";
            $meta_values[] = $field;
            $meta_values[] = $escaped_like;
        }
        
        // Also search in SKU (always whitelisted)
        $meta_conditions[] = "(pm.meta_key = %s AND pm.meta_value LIKE %s)";
        $meta_values[] = '_sku';
        $meta_values[] = $escaped_like;
        
        $meta_sql = implode(' OR ', $meta_conditions);
        
        // Build taxonomy query for custom taxonomies - only use whitelisted taxonomies
        $tax_conditions = array();
        $tax_values = array();
        
        $all_taxonomies = array_merge(
            array('product_cat', 'product_tag'),
            $this->config['custom_taxonomies']
        );
        
        foreach ($all_taxonomies as $taxonomy) {
            // Validate taxonomy against whitelist
            if (!$this->is_valid_taxonomy($taxonomy)) {
                continue;
            }
            $tax_conditions[] = "(tt.taxonomy = %s AND t.name LIKE %s)";
            $tax_values[] = $taxonomy;
            $tax_values[] = $escaped_like;
        }
        
        $tax_sql = implode(' OR ', $tax_conditions);
        
        // Combined query to get product IDs matching any criteria
        $starts_with_like = $wpdb->esc_like($search_term) . '%';
        $limit = absint($this->config['max_products']);
        
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
                p.post_title LIKE %s
                OR p.post_content LIKE %s
                OR p.post_excerpt LIKE %s
                OR p.ID IN (
                    SELECT DISTINCT pm.post_id 
                    FROM {$wpdb->postmeta} pm 
                    WHERE {$meta_sql}
                )
                OR p.ID IN (
                    SELECT DISTINCT tr.object_id
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                    WHERE {$tax_sql}
                )
            )
            ORDER BY relevance ASC, p.post_title ASC
            LIMIT %d
        ";
        
        // Prepare query with all values - using named placeholders for clarity
        $query_values = array_merge(
            array($starts_with_like, $escaped_like), // For relevance scoring
            array('product', 'publish'), // Post type and status (parameterized for safety)
            array($escaped_like, $escaped_like, $escaped_like), // For title, content, excerpt
            $meta_values, // For meta query
            $tax_values, // For taxonomy query
            array($limit) // Limit
        );
        
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
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
        $image_url_large = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : wc_placeholder_img_src('medium');
        
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
        
        // Build meta query for ACF fields
        $meta_conditions = array();
        $meta_values = array();
        
        foreach ($this->config['acf_fields'] as $field) {
            if (!$this->is_valid_meta_key($field)) {
                continue;
            }
            $meta_conditions[] = "(pm.meta_key = %s AND pm.meta_value LIKE %s)";
            $meta_values[] = $field;
            $meta_values[] = $escaped_like;
        }
        
        $meta_conditions[] = "(pm.meta_key = %s AND pm.meta_value LIKE %s)";
        $meta_values[] = '_sku';
        $meta_values[] = $escaped_like;
        
        $meta_sql = implode(' OR ', $meta_conditions);
        
        // Build taxonomy query
        $tax_conditions = array();
        $tax_values = array();
        
        $all_taxonomies = array_merge(
            array('product_cat', 'product_tag'),
            $this->config['custom_taxonomies']
        );
        
        foreach ($all_taxonomies as $taxonomy) {
            if (!$this->is_valid_taxonomy($taxonomy)) {
                continue;
            }
            $tax_conditions[] = "(tt.taxonomy = %s AND t.name LIKE %s)";
            $tax_values[] = $taxonomy;
            $tax_values[] = $escaped_like;
        }
        
        $tax_sql = implode(' OR ', $tax_conditions);
        
        $query = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            WHERE p.post_type = %s
            AND p.post_status = %s
            AND (
                p.post_title LIKE %s
                OR p.post_content LIKE %s
                OR p.post_excerpt LIKE %s
                OR p.ID IN (
                    SELECT DISTINCT pm.post_id 
                    FROM {$wpdb->postmeta} pm 
                    WHERE {$meta_sql}
                )
                OR p.ID IN (
                    SELECT DISTINCT tr.object_id
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                    WHERE {$tax_sql}
                )
            )
        ";
        
        $query_values = array_merge(
            array('product', 'publish'),
            array($escaped_like, $escaped_like, $escaped_like),
            $meta_values,
            $tax_values
        );
        
        return absint($wpdb->get_var($wpdb->prepare($query, $query_values)));
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
}
