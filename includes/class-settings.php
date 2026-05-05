<?php
/**
 * Settings Class
 *
 * Admin settings page under WooCommerce menu.
 *
 * @package WC_Custom_AJAX_Search
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

class Findly_Settings {

    private $option_key = 'findly_settings';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Get default settings
     */
    public static function get_defaults() {
        return array(
            // Search fields
            'search_title'       => 1,
            'search_content'     => 1,
            'search_excerpt'     => 1,
            'search_sku'         => 1,
            'search_categories'  => 1,
            'search_tags'        => 1,
            'search_acf'         => 0,
            'acf_fields'         => '',
            'search_custom_tax'  => 0,
            'custom_taxonomies'  => '',

            // General
            'max_products'           => 7,
            'max_terms_per_taxonomy' => 5,
            'min_chars'              => 2,
            'debounce_delay'         => 300,

            // Features
            'enable_search_history'     => 1,
            'max_recent_searches'       => 5,
            'enable_no_results_suggestions' => 1,
            'mobile_icon_only'              => 0,
            'mobile_icon_breakpoint'        => 768,
        );
    }

    /**
     * Get saved settings merged with defaults
     */
    public static function get_settings() {
        $defaults = self::get_defaults();
        $saved = get_option('findly_settings', array());
        return wp_parse_args($saved, $defaults);
    }

    /**
     * Add submenu page under WooCommerce
     */
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('AJAX Search Settings', 'findly-ajax-search'),
            __('AJAX Search', 'findly-ajax-search'),
            'manage_woocommerce',
            'findly-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'findly_settings_group',
            $this->option_key,
            array($this, 'sanitize_settings')
        );

        // --- Section: Search Fields ---
        add_settings_section(
            'findly_search_fields',
            __('Search Fields', 'findly-ajax-search'),
            array($this, 'render_search_fields_description'),
            'findly-settings'
        );

        add_settings_field('search_title', __('Product Title', 'findly-ajax-search'), array($this, 'render_checkbox'), 'findly-settings', 'findly_search_fields', array('field' => 'search_title', 'desc' => __('Search in product titles', 'findly-ajax-search')));

        add_settings_field('search_content', __('Product Description', 'findly-ajax-search'), array($this, 'render_checkbox'), 'findly-settings', 'findly_search_fields', array('field' => 'search_content', 'desc' => __('Search in full product descriptions', 'findly-ajax-search')));

        add_settings_field('search_excerpt', __('Short Description', 'findly-ajax-search'), array($this, 'render_checkbox'), 'findly-settings', 'findly_search_fields', array('field' => 'search_excerpt', 'desc' => __('Search in product short descriptions', 'findly-ajax-search')));

        add_settings_field('search_sku', __('SKU', 'findly-ajax-search'), array($this, 'render_checkbox'), 'findly-settings', 'findly_search_fields', array('field' => 'search_sku', 'desc' => __('Search in product SKU codes', 'findly-ajax-search')));

        add_settings_field('search_categories', __('Categories', 'findly-ajax-search'), array($this, 'render_checkbox'), 'findly-settings', 'findly_search_fields', array('field' => 'search_categories', 'desc' => __('Search and show matching product categories', 'findly-ajax-search')));

        add_settings_field('search_tags', __('Tags', 'findly-ajax-search'), array($this, 'render_checkbox'), 'findly-settings', 'findly_search_fields', array('field' => 'search_tags', 'desc' => __('Search and show matching product tags', 'findly-ajax-search')));

        add_settings_field('search_acf', __('ACF Custom Fields', 'findly-ajax-search'), array($this, 'render_acf_fields'), 'findly-settings', 'findly_search_fields');

        add_settings_field('search_custom_tax', __('Custom Taxonomies', 'findly-ajax-search'), array($this, 'render_custom_taxonomies'), 'findly-settings', 'findly_search_fields');

        // --- Section: General ---
        add_settings_section(
            'findly_general',
            __('General Settings', 'findly-ajax-search'),
            null,
            'findly-settings'
        );

        add_settings_field('max_products', __('Max Products', 'findly-ajax-search'), array($this, 'render_number'), 'findly-settings', 'findly_general', array('field' => 'max_products', 'desc' => __('Maximum number of products to show in results', 'findly-ajax-search'), 'min' => 1, 'max' => 30));

        add_settings_field('max_terms_per_taxonomy', __('Max Terms per Taxonomy', 'findly-ajax-search'), array($this, 'render_number'), 'findly-settings', 'findly_general', array('field' => 'max_terms_per_taxonomy', 'desc' => __('Maximum category/tag/taxonomy results to show', 'findly-ajax-search'), 'min' => 1, 'max' => 20));

        add_settings_field('min_chars', __('Minimum Characters', 'findly-ajax-search'), array($this, 'render_number'), 'findly-settings', 'findly_general', array('field' => 'min_chars', 'desc' => __('Minimum characters before search triggers', 'findly-ajax-search'), 'min' => 1, 'max' => 10));

        add_settings_field('debounce_delay', __('Debounce Delay (ms)', 'findly-ajax-search'), array($this, 'render_number'), 'findly-settings', 'findly_general', array('field' => 'debounce_delay', 'desc' => __('Delay in milliseconds after user stops typing before search fires', 'findly-ajax-search'), 'min' => 100, 'max' => 1000));

        // --- Section: Features ---
        add_settings_section(
            'findly_features',
            __('Feature Settings', 'findly-ajax-search'),
            null,
            'findly-settings'
        );

        add_settings_field('enable_search_history', __('Search History', 'findly-ajax-search'), array($this, 'render_checkbox'), 'findly-settings', 'findly_features', array('field' => 'enable_search_history', 'desc' => __('Show recent searches when the search box is focused', 'findly-ajax-search')));

        add_settings_field('max_recent_searches', __('Max Recent Searches', 'findly-ajax-search'), array($this, 'render_number'), 'findly-settings', 'findly_features', array('field' => 'max_recent_searches', 'desc' => __('Number of recent searches to remember', 'findly-ajax-search'), 'min' => 1, 'max' => 15));

        add_settings_field('enable_no_results_suggestions', __('No Results Suggestions', 'findly-ajax-search'), array($this, 'render_checkbox'), 'findly-settings', 'findly_features', array('field' => 'enable_no_results_suggestions', 'desc' => __('Show popular products and categories when search returns no results', 'findly-ajax-search')));

        add_settings_field('mobile_icon_only', __('Mobile Icon Only', 'findly-ajax-search'), array($this, 'render_mobile_icon_fields'), 'findly-settings', 'findly_features');
    }

    /**
     * Sanitize settings on save
     */
    public function sanitize_settings($input) {
        $defaults = self::get_defaults();
        $sanitized = array();

        // Checkboxes - unchecked fields won't be in $input
        $checkboxes = array(
            'search_title', 'search_content', 'search_excerpt', 'search_sku',
            'search_categories', 'search_tags', 'search_acf', 'search_custom_tax',
            'enable_search_history', 'enable_no_results_suggestions',
            'mobile_icon_only',
        );
        foreach ($checkboxes as $cb) {
            $sanitized[$cb] = !empty($input[$cb]) ? 1 : 0;
        }

        // Number fields
        $numbers = array(
            'max_products'           => array(1, 30),
            'max_terms_per_taxonomy' => array(1, 20),
            'min_chars'              => array(1, 10),
            'debounce_delay'         => array(100, 1000),
            'max_recent_searches'    => array(1, 15),
            'mobile_icon_breakpoint' => array(320, 1440),
        );
        foreach ($numbers as $key => $range) {
            $val = isset($input[$key]) ? absint($input[$key]) : $defaults[$key];
            $sanitized[$key] = max($range[0], min($range[1], $val));
        }

        // ACF fields - comma-separated, sanitize each
        $acf_raw = isset($input['acf_fields']) ? sanitize_text_field($input['acf_fields']) : '';
        $acf_fields = array_filter(array_map('trim', explode(',', $acf_raw)));
        $acf_fields = array_map('sanitize_key', $acf_fields);
        $sanitized['acf_fields'] = implode(', ', $acf_fields);

        // Custom taxonomies - comma-separated, sanitize each
        $tax_raw = isset($input['custom_taxonomies']) ? sanitize_text_field($input['custom_taxonomies']) : '';
        $tax_fields = array_filter(array_map('trim', explode(',', $tax_raw)));
        $tax_fields = array_map('sanitize_key', $tax_fields);
        $sanitized['custom_taxonomies'] = implode(', ', $tax_fields);

        return $sanitized;
    }

    /**
     * Enqueue admin assets on our settings page only
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'woocommerce_page_findly-settings') {
            return;
        }
        wp_enqueue_style(
            'findly-admin',
            FINDLY_PLUGIN_URL . 'assets/css/admin-settings.css',
            array(),
            FINDLY_VERSION
        );

        // Register a dummy script handle so we can attach inline JS (CSP-safe)
        wp_register_script('findly-admin-js', false, array(), FINDLY_VERSION, true);
        wp_enqueue_script('findly-admin-js');
        wp_add_inline_script('findly-admin-js', "
            document.querySelectorAll('.findly-toggle-parent').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var target = document.getElementById(this.dataset.target);
                    if (target) {
                        target.classList.toggle('findly-hidden', !this.checked);
                    }
                });
            });

            document.querySelectorAll('.findly-copy-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var text = this.getAttribute('data-copy');
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function() {
                            var msg = btn.nextElementSibling;
                            if (msg) {
                                msg.classList.add('visible');
                                setTimeout(function() { msg.classList.remove('visible'); }, 1500);
                            }
                        });
                    }
                });
            });
        ");
    }

    // =========================================
    // Render callbacks
    // =========================================

    public function render_search_fields_description() {
        echo '<p>' . esc_html__('Choose which fields are included when searching for products.', 'findly-ajax-search') . '</p>';
    }

    public function render_checkbox($args) {
        $settings = self::get_settings();
        $field = $args['field'];
        $checked = !empty($settings[$field]) ? 'checked' : '';
        $desc = isset($args['desc']) ? $args['desc'] : '';

        printf(
            '<label><input type="checkbox" name="findly_settings[%s]" value="1" %s /> %s</label>',
            esc_attr($field),
            esc_attr($checked),
            esc_html($desc)
        );
    }

    public function render_number($args) {
        $settings = self::get_settings();
        $field = $args['field'];
        $value = isset($settings[$field]) ? $settings[$field] : '';
        $min = isset($args['min']) ? $args['min'] : 0;
        $max = isset($args['max']) ? $args['max'] : 9999;
        $desc = isset($args['desc']) ? $args['desc'] : '';

        printf(
            '<input type="number" name="findly_settings[%s]" value="%s" min="%d" max="%d" class="small-text" /> <span class="description">%s</span>',
            esc_attr($field),
            esc_attr($value),
            absint($min),
            absint($max),
            esc_html($desc)
        );
    }

    public function render_acf_fields() {
        $settings = self::get_settings();
        $checked = !empty($settings['search_acf']) ? 'checked' : '';
        $fields = isset($settings['acf_fields']) ? $settings['acf_fields'] : '';

        echo '<div class="findly-field-group">';
        printf(
            '<label><input type="checkbox" name="findly_settings[search_acf]" value="1" %s class="findly-toggle-parent" data-target="findly-acf-fields" /> %s</label>',
            esc_attr($checked),
            esc_html__('Search in ACF custom fields', 'findly-ajax-search')
        );
        printf(
            '<div class="findly-sub-field %s" id="findly-acf-fields"><input type="text" name="findly_settings[acf_fields]" value="%s" class="regular-text" placeholder="book_title, original_title, translator" /><p class="description">%s</p></div>',
            empty($settings['search_acf']) ? 'findly-hidden' : '',
            esc_attr($fields),
            esc_html__('Comma-separated ACF field names. Requires Advanced Custom Fields plugin.', 'findly-ajax-search')
        );
        echo '</div>';
    }

    public function render_custom_taxonomies() {
        $settings = self::get_settings();
        $checked = !empty($settings['search_custom_tax']) ? 'checked' : '';
        $taxonomies = isset($settings['custom_taxonomies']) ? $settings['custom_taxonomies'] : '';

        echo '<div class="findly-field-group">';
        printf(
            '<label><input type="checkbox" name="findly_settings[search_custom_tax]" value="1" %s class="findly-toggle-parent" data-target="findly-custom-tax" /> %s</label>',
            esc_attr($checked),
            esc_html__('Search in custom taxonomies', 'findly-ajax-search')
        );
        printf(
            '<div class="findly-sub-field %s" id="findly-custom-tax"><input type="text" name="findly_settings[custom_taxonomies]" value="%s" class="regular-text" placeholder="authors, publisher" /><p class="description">%s</p></div>',
            empty($settings['search_custom_tax']) ? 'findly-hidden' : '',
            esc_attr($taxonomies),
            esc_html__('Comma-separated taxonomy slugs registered for products.', 'findly-ajax-search')
        );
        echo '</div>';
    }

    public function render_mobile_icon_fields() {
        $settings = self::get_settings();
        $checked = !empty($settings['mobile_icon_only']) ? 'checked' : '';
        $breakpoint = isset($settings['mobile_icon_breakpoint']) ? $settings['mobile_icon_breakpoint'] : 768;

        echo '<div class="findly-field-group">';
        printf(
            '<label><input type="checkbox" name="findly_settings[mobile_icon_only]" value="1" %s class="findly-toggle-parent" data-target="findly-mobile-breakpoint" /> %s</label>',
            esc_attr($checked),
            esc_html__('Show only a search icon on mobile devices instead of the full search box. Tapping the icon opens the search overlay.', 'findly-ajax-search')
        );
        printf(
            '<div class="findly-sub-field %s" id="findly-mobile-breakpoint"><label>%s <input type="number" name="findly_settings[mobile_icon_breakpoint]" value="%s" min="320" max="1440" class="small-text" /> px</label><p class="description">%s</p></div>',
            empty($settings['mobile_icon_only']) ? 'findly-hidden' : '',
            esc_html__('Breakpoint:', 'findly-ajax-search'),
            esc_attr($breakpoint),
            esc_html__('Screen width (in pixels) below which the search box switches to icon-only mode. Default: 768.', 'findly-ajax-search')
        );
        echo '</div>';
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        ?>
        <div class="wrap findly-settings-wrap">
            <h1><?php esc_html_e('Findly AJAX Search Settings', 'findly-ajax-search'); ?></h1>

            <div class="findly-shortcode-box">
                <span class="findly-shortcode-label"><?php esc_html_e('Shortcode:', 'findly-ajax-search'); ?></span>
                <code class="findly-shortcode-value" id="findly-shortcode">[findly_ajax_search]</code>
                <button type="button" class="button button-small findly-copy-btn" data-copy="[findly_ajax_search]">
                    <?php esc_html_e('Copy', 'findly-ajax-search'); ?>
                </button>
                <span class="findly-copy-success"><?php esc_html_e('Copied!', 'findly-ajax-search'); ?></span>
            </div>
            <p class="description" style="margin-top: 6px;">
                <?php esc_html_e('Place this shortcode on any page or post to display the search box. You can also use:', 'findly-ajax-search'); ?>
                <code>[findly_ajax_search placeholder="Search products..."]</code>
            </p>

            <?php settings_errors('findly_settings_group'); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('findly_settings_group');
                do_settings_sections('findly-settings');
                submit_button(__('Save Settings', 'findly-ajax-search'));
                ?>
            </form>
        </div>
        <?php
    }
}
