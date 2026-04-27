# WC Custom AJAX Search

A fast, live AJAX product search plugin for WooCommerce with ACF custom fields, custom taxonomies, product preview panel, and full mobile optimization.

## Features

- **Live AJAX search** — Results appear as you type
- **Multi-field search** — Title, description, excerpt, SKU, ACF fields, custom taxonomies
- **Grouped results** — Categories, tags, custom taxonomies, products
- **Product preview panel** — Image, price, stock status, add-to-cart
- **Recent search history** — Quick access to previous searches
- **No results suggestions** — Popular products and top categories as fallback
- **Keyboard navigation** — Arrow keys, Enter, Escape across all items
- **Full mobile optimization** — Overlay mode, touch gestures, safe area support
- **Admin settings page** — Configure everything from WooCommerce > AJAX Search
- **Performance** — Result caching, conditional asset loading, debounced requests
- **Security** — Prepared statements, nonce verification, rate limiting, input sanitization

## Installation

1. Upload the `wc-custom-ajax-search` folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress
3. Go to **WooCommerce > AJAX Search** to configure settings
4. Add `[findly_ajax_search]` to any page or post

## Shortcode

```
[findly_ajax_search]
[findly_ajax_search placeholder="Search products..."]
[findly_ajax_search class="my-custom-class"]
```

## Customizing Styles

Override CSS custom properties in your theme:

```css
:root {
    --wcas-primary: #your-brand-color;
    --wcas-bg: #fff;
    --wcas-text: #333;
    --wcas-border: #ddd;
}
```

See the full list of CSS custom properties in the [plugin stylesheet](assets/css/ajax-search.css).

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- ACF (optional — only if searching custom fields)

## License

GPL v2 or later. See [LICENSE](LICENSE).
