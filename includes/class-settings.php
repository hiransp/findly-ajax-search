<?php
/**
 * Settings Class
 * Admin settings page under WooCommerce menu
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAS_Settings {

    private $option_key = 'wcas_settings';

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
        );
    }

    /**
     * Get saved settings merged with defaults
     */
    public static function get_settings() {
        $defaults = self::get_defaults();
        $saved = get_option('wcas_settings', array());
        return wp_parse_args($saved, $defaults);
    }

    /**
     * Add submenu page under WooCommerce
     */
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('AJAX Search Settings', 'wc-custom-ajax-search'),
            __('AJAX Search', 'wc-custom-ajax-search'),
            'manage_woocommerce',
            'wcas-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'wcas_settings_group',
            $this->option_key,
            array($this, 'sanitize_settings')
        );

        // --- Section: Search Fields ---
        add_settings_section(
            'wcas_search_fields',
            __('Search Fields', 'wc-custom-ajax-search'),
            array($this, 'render_search_fields_description'),
            'wcas-settings'
        );

        add_settings_field('search_title', __('Product Title', 'wc-custom-ajax-search'), array($this, 'render_checkbox'), 'wcas-settings', 'wcas_search_fields', array('field' => 'search_title', 'desc' => __('Search in product titles', 'wc-custom-ajax-search')));

        add_settings_field('search_content', __('Product Description', 'wc-custom-ajax-search'), array($this, 'render_checkbox'), 'wcas-settings', 'wcas_search_fields', array('field' => 'search_content', 'desc' => __('Search in full product descriptions', 'wc-custom-ajax-search')));

        add_settings_field('search_excerpt', __('Short Description', 'wc-custom-ajax-search'), array($this, 'render_checkbox'), 'wcas-settings', 'wcas_search_fields', array('field' => 'search_excerpt', 'desc' => __('Search in product short descriptions', 'wc-custom-ajax-search')));

        add_settings_field('search_sku', __('SKU', 'wc-custom-ajax-search'), array($this, 'render_checkbox'), 'wcas-settings', 'wcas_search_fields', array('field' => 'search_sku', 'desc' => __('Search in product SKU codes', 'wc-custom-ajax-search')));

        add_settings_field('search_categories', __('Categories', 'wc-custom-ajax-search'), array($this, 'render_checkbox'), 'wcas-settings', 'wcas_search_fields', array('field' => 'search_categories', 'desc' => __('Search and show matching product categories', 'wc-custom-ajax-search')));

        add_settings_field('search_tags', __('Tags', 'wc-custom-ajax-search'), array($this, 'render_checkbox'), 'wcas-settings', 'wcas_search_fields', array('field' => 'search_tags', 'desc' => __('Search and show matching product tags', 'wc-custom-ajax-search')));

        add_settings_field('search_acf', __('ACF Custom Fields', 'wc-custom-ajax-search'), array($this, 'render_acf_fields'), 'wcas-settings', 'wcas_search_fields');

        add_settings_field('search_custom_tax', __('Custom Taxonomies', 'wc-custom-ajax-search'), array($this, 'render_custom_taxonomies'), 'wcas-settings', 'wcas_search_fields');

        // --- Section: General ---
        add_settings_section(
            'wcas_general',
            __('General Settings', 'wc-custom-ajax-search'),
            null,
            'wcas-settings'
        );

        add_settings_field('max_products', __('Max Products', 'wc-custom-ajax-search'), array($this, 'render_number'), 'wcas-settings', 'wcas_general', array('field' => 'max_products', 'desc' => __('Maximum number of products to show in results', 'wc-custom-ajax-search'), 'min' => 1, 'max' => 30));

        add_settings_field('max_terms_per_taxonomy', __('Max Terms per Taxonomy', 'wc-custom-ajax-search'), array($this, 'render_number'), 'wcas-settings', 'wcas_general', array('field' => 'max_terms_per_taxonomy', 'desc' => __('Maximum category/tag/taxonomy results to show', 'wc-custom-ajax-search'), 'min' => 1, 'max' => 20));

        add_settings_field('min_chars', __('Minimum Characters', 'wc-custom-ajax-search'), array($this, 'render_number'), 'wcas-settings', 'wcas_general', array('field' => 'min_chars', 'desc' => __('Minimum characters before search triggers', 'wc-custom-ajax-search'), 'min' => 1, 'max' => 10));

        add_settings_field('debounce_delay', __('Debounce Delay (ms)', 'wc-custom-ajax-search'), array($this, 'render_number'), 'wcas-settings', 'wcas_general', array('field' => 'debounce_delay', 'desc' => __('Delay in milliseconds after user stops typing before search fires', 'wc-custom-ajax-search'), 'min' => 100, 'max' => 1000));

        // --- Section: Features ---
        add_settings_section(
            'wcas_features',
            __('Feature Settings', 'wc-custom-ajax-search'),
            null,
            'wcas-settings'
        );

        add_settings_field('enable_search_history', __('Search History', 'wc-custom-ajax-search'), array($this, 'render_checkbox'), 'wcas-settings', 'wcas_features', array('field' => 'enable_search_history', 'desc' => __('Show recent searches when the search box is focused', 'wc-custom-ajax-search')));

        add_settings_field('max_recent_searches', __('Max Recent Searches', 'wc-custom-ajax-search'), array($this, 'render_number'), 'wcas-settings', 'wcas_features', array('field' => 'max_recent_searches', 'desc' => __('Number of recent searches to remember', 'wc-custom-ajax-search'), 'min' => 1, 'max' => 15));

        add_settings_field('enable_no_results_suggestions', __('No Results Suggestions', 'wc-custom-ajax-search'), array($this, 'render_checkbox'), 'wcas-settings', 'wcas_features', array('field' => 'enable_no_results_suggestions', 'desc' => __('Show popular products and categories when search returns no results', 'wc-custom-ajax-search')));
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
     * Enqueue admin CSS on our settings page only
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'woocommerce_page_wcas-settings') {
            return;
        }
        wp_enqueue_style(
            'wcas-admin',
            WCAS_PLUGIN_URL . 'assets/css/admin-settings.css',
            array(),
            WCAS_VERSION
        );
    }

    // =========================================
    // Render callbacks
    // =========================================

    public function render_search_fields_description() {
        echo '<p>' . esc_html__('Choose which fields are included when searching for products.', 'wc-custom-ajax-search') . '</p>';
    }

    public function render_checkbox($args) {
        $settings = self::get_settings();
        $field = $args['field'];
        $checked = !empty($settings[$field]) ? 'checked' : '';
        $desc = isset($args['desc']) ? $args['desc'] : '';

        printf(
            '<label><input type="checkbox" name="wcas_settings[%s]" value="1" %s /> %s</label>',
            esc_attr($field),
            $checked,
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
            '<input type="number" name="wcas_settings[%s]" value="%s" min="%d" max="%d" class="small-text" /> <span class="description">%s</span>',
            esc_attr($field),
            esc_attr($value),
            $min,
            $max,
            esc_html($desc)
        );
    }

    public function render_acf_fields() {
        $settings = self::get_settings();
        $checked = !empty($settings['search_acf']) ? 'checked' : '';
        $fields = isset($settings['acf_fields']) ? $settings['acf_fields'] : '';

        echo '<div class="wcas-field-group">';
        printf(
            '<label><input type="checkbox" name="wcas_settings[search_acf]" value="1" %s class="wcas-toggle-parent" data-target="wcas-acf-fields" /> %s</label>',
            $checked,
            esc_html__('Search in ACF custom fields', 'wc-custom-ajax-search')
        );
        printf(
            '<div class="wcas-sub-field %s" id="wcas-acf-fields"><input type="text" name="wcas_settings[acf_fields]" value="%s" class="regular-text" placeholder="book_title, original_title, translator" /><p class="description">%s</p></div>',
            empty($settings['search_acf']) ? 'wcas-hidden' : '',
            esc_attr($fields),
            esc_html__('Comma-separated ACF field names. Requires Advanced Custom Fields plugin.', 'wc-custom-ajax-search')
        );
        echo '</div>';
    }

    public function render_custom_taxonomies() {
        $settings = self::get_settings();
        $checked = !empty($settings['search_custom_tax']) ? 'checked' : '';
        $taxonomies = isset($settings['custom_taxonomies']) ? $settings['custom_taxonomies'] : '';

        echo '<div class="wcas-field-group">';
        printf(
            '<label><input type="checkbox" name="wcas_settings[search_custom_tax]" value="1" %s class="wcas-toggle-parent" data-target="wcas-custom-tax" /> %s</label>',
            $checked,
            esc_html__('Search in custom taxonomies', 'wc-custom-ajax-search')
        );
        printf(
            '<div class="wcas-sub-field %s" id="wcas-custom-tax"><input type="text" name="wcas_settings[custom_taxonomies]" value="%s" class="regular-text" placeholder="authors, publisher" /><p class="description">%s</p></div>',
            empty($settings['search_custom_tax']) ? 'wcas-hidden' : '',
            esc_attr($taxonomies),
            esc_html__('Comma-separated taxonomy slugs registered for products.', 'wc-custom-ajax-search')
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
        <div class="wrap wcas-settings-wrap">
            <h1><?php esc_html_e('WC Custom AJAX Search Settings', 'wc-custom-ajax-search'); ?></h1>

            <?php settings_errors('wcas_settings_group'); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('wcas_settings_group');
                do_settings_sections('wcas-settings');
                submit_button(__('Save Settings', 'wc-custom-ajax-search'));
                ?>
            </form>
        </div>

        <script>
        (function() {
            document.querySelectorAll('.wcas-toggle-parent').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var target = document.getElementById(this.dataset.target);
                    if (target) {
                        target.classList.toggle('wcas-hidden', !this.checked);
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
