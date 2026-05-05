<?php

/**
 * Findly AJAX Search
 *
 * @package Findly_AJAX_Search
 * @license GPL-2.0-or-later
 *
 * Plugin Name:       Findly AJAX Search
 * Plugin URI:        https://github.com/hiransp/findly-ajax-search
 * Description:       Live AJAX product search for WooCommerce with ACF custom fields, custom taxonomies, product preview panel, and full mobile optimization.
 * Version:           1.0.0
 * Author:            Hiran
 * Author URI:        https://github.com/hiransp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       findly-ajax-search
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 6.0
 * WC tested up to:   9.8
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('FINDLY_VERSION', '1.0.0');
define('FINDLY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FINDLY_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Configuration - reads from admin settings, falls back to defaults
 */
function findly_get_config()
{
    // Load settings class if not already loaded
    if (!class_exists('Findly_Settings')) {
        require_once FINDLY_PLUGIN_DIR . 'includes/class-settings.php';
    }

    $s = Findly_Settings::get_settings();

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
        'mobile_icon_only'              => !empty($s['mobile_icon_only']),
        'mobile_icon_breakpoint'        => absint($s['mobile_icon_breakpoint']),
    );
}

/**
 * Initialize plugin
 */
function findly_init()
{
    // Load includes — always load so AJAX handlers are registered
    require_once FINDLY_PLUGIN_DIR . 'includes/class-settings.php';
    require_once FINDLY_PLUGIN_DIR . 'includes/class-search-handler.php';
    require_once FINDLY_PLUGIN_DIR . 'includes/class-shortcode.php';

    // Initialize search handler (registers AJAX hooks)
    new Findly_Search_Handler();

    // Initialize shortcode
    new Findly_Shortcode();

    // Initialize settings (admin only)
    if (is_admin()) {
        new Findly_Settings();
    }
}

function findly_check_woocommerce()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Findly AJAX Search requires WooCommerce to be installed and active.', 'findly-ajax-search') . '</p></div>';
        });
    }
}

/**
 * Declare WooCommerce HPOS compatibility
 */
function findly_declare_hpos_compatibility()
{
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}

add_action('plugins_loaded', 'findly_init');
add_action('admin_init', 'findly_check_woocommerce');
add_action('before_woocommerce_init', 'findly_declare_hpos_compatibility');

/**
 * Register scripts and styles (does not enqueue yet)
 */
function findly_register_assets()
{
    wp_register_style(
        'findly-styles',
        FINDLY_PLUGIN_URL . 'assets/css/ajax-search.css',
        array(),
        FINDLY_VERSION
    );

    wp_register_script(
        'findly-script',
        FINDLY_PLUGIN_URL . 'assets/js/ajax-search.js',
        array('jquery'),
        FINDLY_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'findly_register_assets');

/**
 * Enqueue assets only on pages where the shortcode is actually used.
 * Runs at wp_footer so the shortcode has already been parsed by then.
 */
function findly_maybe_enqueue_assets()
{
    if (!class_exists('Findly_Shortcode') || !Findly_Shortcode::$enqueue_assets) {
        return;
    }

    wp_enqueue_style('findly-styles');
    wp_enqueue_script('findly-script');

    $config = findly_get_config();
    if ($config['mobile_icon_only']) {
        $bp = absint($config['mobile_icon_breakpoint']);
        $inline_css = "@media (max-width: {$bp}px) {
    .findly-mobile-icon-mode .findly-mobile-trigger {
        display: flex;
        align-items: center;
        justify-content: center;
        width: var(--findly-touch-target);
        height: var(--findly-touch-target);
        background: var(--findly-bg);
        border: 1px solid var(--findly-border);
        border-radius: var(--findly-radius-md);
        color: var(--findly-text-light);
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
        transition: border-color var(--findly-transition-normal), color var(--findly-transition-normal);
    }
    .findly-mobile-icon-mode .findly-mobile-trigger:hover,
    .findly-mobile-icon-mode .findly-mobile-trigger:active {
        border-color: var(--findly-primary);
        color: var(--findly-primary);
    }
    .findly-mobile-icon-mode .findly-search-box,
    .findly-mobile-icon-mode .findly-results-wrapper { display: none; }
    .findly-mobile-icon-mode.findly-mobile-search-open .findly-mobile-overlay {
        display: block; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: var(--findly-overlay); z-index: var(--findly-z-overlay);
    }
    .findly-mobile-icon-mode.findly-mobile-search-open .findly-mobile-trigger { display: none; }
    .findly-mobile-icon-mode.findly-mobile-search-open .findly-mobile-close {
        display: flex; align-items: center; justify-content: center;
        position: fixed; top: 0; right: 0; width: 56px; height: 56px;
        padding-top: env(safe-area-inset-top, 0);
        z-index: calc(var(--findly-z-modal) + 1);
        background: none; border: none; color: var(--findly-text-light);
        cursor: pointer; -webkit-tap-highlight-color: transparent;
        transition: color var(--findly-transition-fast);
    }
    .findly-mobile-icon-mode.findly-mobile-search-open .findly-mobile-close:hover,
    .findly-mobile-icon-mode.findly-mobile-search-open .findly-mobile-close:active { color: var(--findly-text); }
    .findly-mobile-icon-mode.findly-mobile-search-open .findly-search-box {
        display: flex; position: fixed; top: 0; left: 0; right: 0;
        z-index: var(--findly-z-modal); border-radius: 0; border: none;
        border-bottom: 1px solid var(--findly-border); min-height: 56px;
        padding: 0 56px 0 var(--findly-spacing-md); background: var(--findly-bg);
        padding-top: env(safe-area-inset-top, 0);
    }
    .findly-mobile-icon-mode.findly-mobile-search-open .findly-results-wrapper {
        display: block; position: fixed;
        top: 56px; top: calc(56px + env(safe-area-inset-top, 0));
        left: 0; right: 0; bottom: 0; z-index: var(--findly-z-modal);
        margin-top: 0; border: none; border-radius: 0; box-shadow: none;
        transform: none; opacity: 1; overflow-y: auto;
    }
    .findly-mobile-icon-mode.findly-mobile-search-open .findly-results-wrapper .findly-results-container { max-height: none; }
    body.findly-body-overlay-open { overflow: hidden; }
}";
        wp_add_inline_style('findly-styles', $inline_css);
    }

    wp_localize_script('findly-script', 'findlyConfig', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('findly_search_nonce'),
        'minChars' => $config['min_chars'],
        'debounceDelay' => $config['debounce_delay'],
        'enableSearchHistory' => $config['enable_search_history'],
        'maxRecentSearches' => $config['max_recent_searches'],
        'enableNoResultsSuggestions' => $config['enable_no_results_suggestions'],
        'mobileIconOnly' => $config['mobile_icon_only'],
        'mobileIconBreakpoint' => $config['mobile_icon_breakpoint'],
        'i18n' => array(
            'noResults' => __('No results found', 'findly-ajax-search'),
            'searching' => __('Searching...', 'findly-ajax-search'),
            'seeAllResults' => __('See all results', 'findly-ajax-search'),
            'addToCart' => __('Add to cart', 'findly-ajax-search'),
            'added' => __('Added!', 'findly-ajax-search'),
            'rateLimited' => __('Please slow down and try again.', 'findly-ajax-search'),
            'hoverPreview' => __('Hover over a product to see details', 'findly-ajax-search'),
            'viewProduct' => __('View Product', 'findly-ajax-search'),
            'inStock' => __('In Stock', 'findly-ajax-search'),
            'outOfStock' => __('Out of Stock', 'findly-ajax-search'),
            'back' => __('Back', 'findly-ajax-search'),
            'close' => __('Close', 'findly-ajax-search'),
            'categories' => __('Categories', 'findly-ajax-search'),
            'tags' => __('Tags', 'findly-ajax-search'),
            'products' => __('Products', 'findly-ajax-search'),
            'recentSearches' => __('Recent Searches', 'findly-ajax-search'),
            'clearHistory' => __('Clear all', 'findly-ajax-search'),
            'noResultsTryAgain' => __('No results found. Try a different search term.', 'findly-ajax-search'),
            'popularProducts' => __('Popular Products', 'findly-ajax-search'),
            'topCategories' => __('Top Categories', 'findly-ajax-search'),
        ),
    ));
}
add_action('wp_footer', 'findly_maybe_enqueue_assets', 1);

/**
 * Plugin activation
 */
function findly_activate()
{
    // Activation tasks if needed
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'findly_activate');

/**
 * Plugin deactivation
 */
function findly_deactivate()
{
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'findly_deactivate');
