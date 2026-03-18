<?php
/**
 * Shortcode Class
 * Handles shortcode registration and rendering
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAS_Shortcode {
    
    public function __construct() {
        add_shortcode('wc_ajax_search', array($this, 'render_search'));
    }
    
    /**
     * Render search shortcode
     * 
     * Usage: [wc_ajax_search]
     * Attributes:
     *   - placeholder: Custom placeholder text
     *   - class: Additional CSS class for wrapper
     */
    public function render_search($atts) {
        $atts = shortcode_atts(array(
            'placeholder' => __('Search for your favorite books...', 'wc-custom-ajax-search'),
            'class' => '',
        ), $atts, 'wc_ajax_search');
        
        $wrapper_class = 'wcas-wrapper';
        if (!empty($atts['class'])) {
            $wrapper_class .= ' ' . esc_attr($atts['class']);
        }
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapper_class); ?>">
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
                <span class="wcas-clear" title="<?php esc_attr_e('Clear', 'wc-custom-ajax-search'); ?>">
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
                            <span><?php esc_html_e('Hover over a product to see details', 'wc-custom-ajax-search'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
