# WC Custom AJAX Search

A lightweight WooCommerce AJAX search plugin with support for ACF custom fields, custom taxonomies, and full mobile optimization.

## Features

- **Live AJAX search** - Results appear as you type (2+ characters)
- **Searches everywhere**:
  - Product title, content, short description
  - Product SKU
  - ACF custom fields (configurable)
  - Custom taxonomies (configurable)
  - Default WooCommerce categories and tags
- **Grouped results** - Categories, Tags, Custom Taxonomies, Products
- **Product preview panel** - Hover to see product details with image, price, and add-to-cart
- **Keyboard navigation** - Arrow keys to navigate, Enter to select
- **Full mobile optimization**:
  - Full-screen overlay on mobile devices
  - Touch-optimized tap targets (44px minimum)
  - Swipe-to-close preview panel
  - Safe area support for notched devices
  - Back button integration
  - Body scroll lock when active
- **Responsive design** - Adapts to all screen sizes
- **Accessibility** - ARIA labels, reduced motion support, high contrast mode
- **Dark mode support** - Automatic detection via CSS
- **Security hardened** - SQL injection protection, XSS prevention, rate limiting
- **Minimal styling** - Easy to customize in your theme

## Installation

1. Upload the `wc-custom-ajax-search` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure your ACF fields and custom taxonomies in the main plugin file
4. Add the search bar using the shortcode: `[wc_ajax_search]`

## Configuration

Edit the configuration in `wc-custom-ajax-search.php`:

```php
function wcas_get_config() {
    return array(
        // ACF fields to search
        'acf_fields' => array(
            'book_title',
            'original_title',
            'translator',
            'compiler',
        ),
        
        // Custom taxonomies to search
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
```

## Shortcode Usage

### Basic Usage
```
[wc_ajax_search]
```

### With Custom Placeholder
```
[wc_ajax_search placeholder="Search for books..."]
```

### With Additional CSS Class
```
[wc_ajax_search class="my-custom-search"]
```

## Customizing Styles

The plugin includes comprehensive CSS with CSS custom properties for easy theming. Override in your theme's stylesheet:

```css
/* Override theme colors */
:root {
    --wcas-primary: #your-brand-color;
    --wcas-primary-hover: #your-brand-hover;
    --wcas-text: #333;
    --wcas-bg: #fff;
    --wcas-border: #ddd;
}

/* Custom search box styling */
.wcas-search-box {
    border: 2px solid var(--wcas-primary);
    border-radius: 25px;
}

/* Custom results styling */
.wcas-results-wrapper {
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
}

/* Custom product hover color */
.wcas-product-item:hover a {
    background-color: rgba(0, 115, 170, 0.1);
}

/* Custom button styling */
.wcas-add-to-cart {
    background: var(--wcas-primary);
    border-radius: 25px;
}
```

### Mobile-Specific Customization

```css
/* Customize mobile overlay */
@media (max-width: 768px) {
    .wcas-mobile-active .wcas-search-box {
        background: #f5f5f5;
    }
    
    /* Adjust preview panel height */
    .wcas-mobile-active .wcas-preview-panel.wcas-preview-visible {
        max-height: 60vh;
    }
}
```

### CSS Custom Properties Reference

| Property | Default | Description |
|----------|---------|-------------|
| `--wcas-primary` | #0073aa | Primary brand color |
| `--wcas-primary-hover` | #005a87 | Primary hover state |
| `--wcas-text` | #333 | Main text color |
| `--wcas-text-light` | #666 | Secondary text |
| `--wcas-text-muted` | #888 | Muted text |
| `--wcas-bg` | #fff | Background color |
| `--wcas-bg-secondary` | #fafafa | Secondary background |
| `--wcas-border` | #ddd | Border color |
| `--wcas-success` | #46b450 | Success state |
| `--wcas-error` | #dc3232 | Error state |
| `--wcas-touch-target` | 44px | Minimum touch target size |

## Adding More ACF Fields or Taxonomies

1. Open `wc-custom-ajax-search.php`
2. Find the `wcas_get_config()` function
3. Add your field names to `acf_fields` array
4. Add your taxonomy slugs to `custom_taxonomies` array

## Internationalization

The plugin supports i18n. You can add translations in the `wp_localize_script` call in the main plugin file:

```php
'i18n' => array(
    'noResults' => __('No results found', 'wc-custom-ajax-search'),
    'searching' => __('Searching...', 'wc-custom-ajax-search'),
    'seeAllResults' => __('See all results', 'wc-custom-ajax-search'),
    'addToCart' => __('Add to cart', 'wc-custom-ajax-search'),
    'categories' => __('Categories', 'wc-custom-ajax-search'),
    'tags' => __('Tags', 'wc-custom-ajax-search'),
    'products' => __('Products', 'wc-custom-ajax-search'),
),
```

## Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+
- ACF (Advanced Custom Fields) - if using ACF fields

## Changelog

### 1.1.0
- Added full mobile optimization with full-screen overlay
- Added touch gesture support (swipe to close)
- Added CSS custom properties for easy theming
- Added dark mode support
- Added reduced motion preference support
- Added high contrast mode support
- Added safe area support for notched devices
- Added back button integration for mobile
- Added lazy loading for product images
- Improved accessibility with ARIA labels
- Improved touch targets (44px minimum)
- Added rate limiting feedback in UI

### 1.0.0
- Initial release
