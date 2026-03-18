<?php
/**
 * Plugin Name: WC Custom AJAX Search
 * Description: Custom AJAX search for WooCommerce with ACF fields and custom taxonomies support
 * Version: 1.0.0
 * Author: Hiran
 * Text Domain: wc-custom-ajax-search
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WCAS_VERSION', '1.0.0');
define('WCAS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCAS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Configuration - Customize these values
 */
function wcas_get_config() {
    return array(
        // ACF fields to search
        'acf_fields' => array(
            'book_title',
            'original_title',
            'translator',
            'compiler',
        ),
        
        // Custom taxonomies to search (in addition to product_cat and product_tag)
        'custom_taxonomies' => array(
            'authors',
            'publisher',
        ),
        
        // Results limits
        'max_products' => 7,
        'max_terms_per_taxonomy' => 5,
        
        // Minimum characters to trigger search
        'min_chars' => 2,
        
        // Debounce delay in milliseconds
        'debounce_delay' => 300,
    );
}

/**
 * Initialize plugin
 */
function wcas_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . __('WC Custom AJAX Search requires WooCommerce to be installed and active.', 'wc-custom-ajax-search') . '</p></div>';
        });
        return;
    }
    
    // Load includes
    require_once WCAS_PLUGIN_DIR . 'includes/class-search-handler.php';
    require_once WCAS_PLUGIN_DIR . 'includes/class-shortcode.php';
    
    // Initialize shortcode
    new WCAS_Shortcode();
    
    // Initialize search handler
    new WCAS_Search_Handler();
}
add_action('plugins_loaded', 'wcas_init');

/**
 * Enqueue scripts and styles
 */
function wcas_enqueue_assets() {
    // Only load if shortcode is present or on all pages (you can optimize this later)
    wp_enqueue_style(
        'wcas-styles',
        WCAS_PLUGIN_URL . 'assets/css/ajax-search.css',
        array(),
        WCAS_VERSION
    );
    
    wp_enqueue_script(
        'wcas-script',
        WCAS_PLUGIN_URL . 'assets/js/ajax-search.js',
        array('jquery'),
        WCAS_VERSION,
        true
    );
    
    // Pass config to JavaScript
    $config = wcas_get_config();
    wp_localize_script('wcas-script', 'wcasConfig', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wcas_search_nonce'),
        'minChars' => $config['min_chars'],
        'debounceDelay' => $config['debounce_delay'],
        'i18n' => array(
            'noResults' => __('No results found', 'wc-custom-ajax-search'),
            'searching' => __('Searching...', 'wc-custom-ajax-search'),
            'seeAllResults' => __('See all results', 'wc-custom-ajax-search'),
            'addToCart' => __('Add to cart', 'wc-custom-ajax-search'),
            'added' => __('Added!', 'wc-custom-ajax-search'),
            'rateLimited' => __('Please slow down and try again.', 'wc-custom-ajax-search'),
            'hoverPreview' => __('Hover over a product to see details', 'wc-custom-ajax-search'),
            'viewProduct' => __('View Product', 'wc-custom-ajax-search'),
            'inStock' => __('In Stock', 'wc-custom-ajax-search'),
            'outOfStock' => __('Out of Stock', 'wc-custom-ajax-search'),
            'back' => __('Back', 'wc-custom-ajax-search'),
            'close' => __('Close', 'wc-custom-ajax-search'),
            'categories' => __('Categories', 'wc-custom-ajax-search'),
            'tags' => __('Tags', 'wc-custom-ajax-search'),
            'products' => __('Products', 'wc-custom-ajax-search'),
        ),
    ));
}
add_action('wp_enqueue_scripts', 'wcas_enqueue_assets');

/**
 * Plugin activation
 */
function wcas_activate() {
    // Activation tasks if needed
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wcas_activate');

/**
 * Plugin deactivation
 */
function wcas_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wcas_deactivate');
