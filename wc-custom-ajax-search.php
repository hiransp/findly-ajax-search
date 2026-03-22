<?php

/**
 * WC Custom AJAX Search
 *
 * @package WC_Custom_AJAX_Search
 * @license GPL-2.0-or-later
 *
 * Plugin Name:       WC Custom AJAX Search
 * Plugin URI:        https://github.com/hiran/wc-custom-ajax-search
 * Description:       Live AJAX product search for WooCommerce with ACF custom fields, custom taxonomies, product preview panel, and full mobile optimization.
 * Version:           1.0.0
 * Author:            Hiran
 * Author URI:        https://github.com/hiransp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-custom-ajax-search
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WCAS_VERSION', '1.0.0');
define('WCAS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCAS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Configuration - reads from admin settings, falls back to defaults
 */
function wcas_get_config()
{
    // Load settings class if not already loaded
    if (!class_exists('WCAS_Settings')) {
        require_once WCAS_PLUGIN_DIR . 'includes/class-settings.php';
    }

    $s = WCAS_Settings::get_settings();

    // Parse comma-separated fields into arrays
    $acf_fields = array_filter(array_map('trim', explode(',', $s['acf_fields'])));
    $custom_taxonomies = array_filter(array_map('trim', explode(',', $s['custom_taxonomies'])));

    return array(
        // Which fields to search
        'search_title'       => !empty($s['search_title']),
        'search_content'     => !empty($s['search_content']),
        'search_excerpt'     => !empty($s['search_excerpt']),
        'search_sku'         => !empty($s['search_sku']),
        'search_categories'  => !empty($s['search_categories']),
        'search_tags'        => !empty($s['search_tags']),

        // ACF fields to search (empty array if disabled)
        'acf_fields' => !empty($s['search_acf']) ? $acf_fields : array(),

        // Custom taxonomies to search
        'custom_taxonomies' => !empty($s['search_custom_tax']) ? $custom_taxonomies : array(),

        // Results limits
        'max_products' => absint($s['max_products']),
        'max_terms_per_taxonomy' => absint($s['max_terms_per_taxonomy']),

        // Minimum characters to trigger search
        'min_chars' => absint($s['min_chars']),

        // Debounce delay in milliseconds
        'debounce_delay' => absint($s['debounce_delay']),

        // Features
        'enable_search_history'         => !empty($s['enable_search_history']),
        'max_recent_searches'           => absint($s['max_recent_searches']),
        'enable_no_results_suggestions' => !empty($s['enable_no_results_suggestions']),
    );
}

/**
 * Initialize plugin
 */
function wcas_init()
{
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>' . __('WC Custom AJAX Search requires WooCommerce to be installed and active.', 'wc-custom-ajax-search') . '</p></div>';
        });
        return;
    }

    // Load includes
    require_once WCAS_PLUGIN_DIR . 'includes/class-settings.php';
    require_once WCAS_PLUGIN_DIR . 'includes/class-search-handler.php';
    require_once WCAS_PLUGIN_DIR . 'includes/class-shortcode.php';

    // Initialize settings (admin only)
    if (is_admin()) {
        new WCAS_Settings();
    }

    // Initialize shortcode
    new WCAS_Shortcode();

    // Initialize search handler
    new WCAS_Search_Handler();
}
add_action('plugins_loaded', 'wcas_init');

/**
 * Register scripts and styles (does not enqueue yet)
 */
function wcas_register_assets()
{
    wp_register_style(
        'wcas-styles',
        WCAS_PLUGIN_URL . 'assets/css/ajax-search.css',
        array(),
        WCAS_VERSION
    );

    wp_register_script(
        'wcas-script',
        WCAS_PLUGIN_URL . 'assets/js/ajax-search.js',
        array('jquery'),
        WCAS_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'wcas_register_assets');

/**
 * Enqueue assets only on pages where the shortcode is actually used.
 * Runs at wp_footer so the shortcode has already been parsed by then.
 */
function wcas_maybe_enqueue_assets()
{
    if (!class_exists('WCAS_Shortcode') || !WCAS_Shortcode::$enqueue_assets) {
        return;
    }

    wp_enqueue_style('wcas-styles');
    wp_enqueue_script('wcas-script');

    $config = wcas_get_config();
    wp_localize_script('wcas-script', 'wcasConfig', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wcas_search_nonce'),
        'minChars' => $config['min_chars'],
        'debounceDelay' => $config['debounce_delay'],
        'enableSearchHistory' => $config['enable_search_history'],
        'maxRecentSearches' => $config['max_recent_searches'],
        'enableNoResultsSuggestions' => $config['enable_no_results_suggestions'],
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
            'recentSearches' => __('Recent Searches', 'wc-custom-ajax-search'),
            'clearHistory' => __('Clear all', 'wc-custom-ajax-search'),
            'noResultsTryAgain' => __('No results found. Try a different search term.', 'wc-custom-ajax-search'),
            'popularProducts' => __('Popular Products', 'wc-custom-ajax-search'),
            'topCategories' => __('Top Categories', 'wc-custom-ajax-search'),
        ),
    ));
}
add_action('wp_footer', 'wcas_maybe_enqueue_assets', 1);

/**
 * Plugin activation
 */
function wcas_activate()
{
    // Activation tasks if needed
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wcas_activate');

/**
 * Plugin deactivation
 */
function wcas_deactivate()
{
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wcas_deactivate');
