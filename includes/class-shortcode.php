<?php
/**
 * Shortcode Class
 *
 * Registers and renders the [findly_ajax_search] shortcode.
 *
 * @package WC_Custom_AJAX_Search
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAS_Shortcode {

    /**
     * Flag: shortcode was used on the current page
     */
    public static $enqueue_assets = false;

    public function __construct() {
        add_shortcode('findly_ajax_search', array($this, 'render_search'));
    }
    
    /**
     * Render search shortcode
     * 
     * Usage: [findly_ajax_search]
     * Attributes:
     *   - placeholder: Custom placeholder text
     *   - class: Additional CSS class for wrapper
     */
    public function render_search($atts) {
        // Mark that assets are needed on this page
        self::$enqueue_assets = true;

        $atts = shortcode_atts(array(
            'placeholder' => __('Search for your favorite books...', 'findly-ajax-search'),
            'class' => '',
        ), $atts, 'findly_ajax_search');
        
        $settings = WCAS_Settings::get_settings();
        $mobile_icon_only = !empty($settings['mobile_icon_only']);

        $wrapper_class = 'wcas-wrapper';
        if ($mobile_icon_only) {
            $wrapper_class .= ' wcas-mobile-icon-mode';
        }
        if (!empty($atts['class'])) {
            $wrapper_class .= ' ' . esc_attr($atts['class']);
        }
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapper_class); ?>">
            <button type="button" class="wcas-mobile-trigger" aria-label="<?php esc_attr_e('Open search', 'findly-ajax-search'); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
            </button>
            <div class="wcas-mobile-overlay"></div>
            <div class="wcas-mobile-close" aria-label="<?php esc_attr_e('Close search', 'findly-ajax-search'); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6 6 18"></path>
                    <path d="m6 6 12 12"></path>
                </svg>
            </div>
            <div class="wcas-search-box">
                <span class="wcas-search-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                </span>
                <input 
                    type="text" 
                    class="wcas-search-input" 
                    placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                    autocomplete="off"
                    aria-label="<?php echo esc_attr($atts['placeholder']); ?>"
                >
                <span class="wcas-spinner">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                    </svg>
                </span>
                <span class="wcas-clear" title="<?php esc_attr_e('Clear', 'findly-ajax-search'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18"></path>
                        <path d="m6 6 12 12"></path>
                    </svg>
                </span>
            </div>
            
            <div class="wcas-results-wrapper">
                <div class="wcas-results-container">
                    <div class="wcas-results-list">
                        <!-- Results will be populated via JS -->
                    </div>
                    <div class="wcas-preview-panel">
                        <!-- Product preview will be shown here -->
                        <div class="wcas-preview-placeholder">
                            <span><?php esc_html_e('Hover over a product to see details', 'findly-ajax-search'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
