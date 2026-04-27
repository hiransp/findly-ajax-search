=== Findly AJAX Search for WooCommerce ===
Contributors: hiran
Tags: woocommerce, search, ajax, product search, live search
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live AJAX product search for WooCommerce with custom fields, taxonomies, product preview, and mobile optimization.

== Description ==

Findly adds a fast, live search box to your WooCommerce store. Results appear instantly as customers type, with grouped categories, tags, and products — plus a product preview panel on desktop.

= Key Features =

* **Live AJAX search** — Results appear as you type (configurable minimum characters)
* **Searches across multiple fields** — Product title, description, excerpt, SKU, ACF custom fields, and custom taxonomies
* **Grouped results** — Categories, tags, custom taxonomies, and products shown in organized sections
* **Product preview panel** — Hover (desktop) or tap (mobile) to see full product details with image, price, stock status, and add-to-cart
* **AJAX add to cart** — Add simple products directly from search results
* **Recent search history** — Remembers recent searches for quick access
* **No results suggestions** — Shows popular products and top categories when no results are found
* **Full mobile optimization** — Full-screen overlay, touch gestures, swipe-to-close, safe area support
* **Keyboard navigation** — Arrow keys, Enter, and Escape support across all result types
* **Admin settings page** — Configure all search fields, limits, and features from WooCommerce > AJAX Search
* **Performance optimized** — Result caching, conditional asset loading, debounced requests
* **Security hardened** — Prepared SQL statements, nonce verification, rate limiting, input sanitization, XSS prevention

= How It Works =

1. Add the `[findly_ajax_search]` shortcode to any page, post, or widget
2. Customers type in the search box and results appear instantly
3. Results are grouped by categories, tags, custom taxonomies, and products
4. Hovering over a product shows a detailed preview panel
5. Clicking a result navigates to that product or category page

= Customization =

All settings are configurable from the admin panel — no code editing required:

* Choose which fields to search (title, description, SKU, etc.)
* Set result limits and debounce delay
* Enable/disable search history and no-results suggestions
* Add ACF custom fields and custom taxonomies to search

The plugin uses CSS custom properties for easy theme integration. Override `--wcas-primary`, `--wcas-bg`, `--wcas-text`, and other variables in your theme stylesheet.

= Requirements =

* WooCommerce 6.0 or higher
* Advanced Custom Fields (optional — only needed if searching ACF fields)

== Installation ==

1. Upload the `findly-ajax-search` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **WooCommerce > AJAX Search** to configure your search settings.
4. Add the shortcode `[findly_ajax_search]` to any page or post where you want the search box to appear.

= Shortcode Options =

* `[findly_ajax_search]` — Default search box
* `[findly_ajax_search placeholder="Search products..."]` — Custom placeholder text
* `[findly_ajax_search class="my-custom-class"]` — Additional CSS class on the wrapper

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and activated. The plugin will show a notice if WooCommerce is not found.

= Does this replace the default WordPress search? =

No. This plugin provides a separate search shortcode that you place wherever you want. It does not modify the default WordPress or WooCommerce search.

= Can I search in ACF custom fields? =

Yes. Go to WooCommerce > AJAX Search, enable "ACF Custom Fields", and enter your field names (comma-separated). The Advanced Custom Fields plugin must be installed.

= Can I search in custom taxonomies? =

Yes. Enable "Custom Taxonomies" in the settings and enter your taxonomy slugs (comma-separated). The taxonomies must be registered and associated with the `product` post type.

= How do I customize the appearance? =

The plugin uses CSS custom properties. Add overrides in your theme's stylesheet:

`
:root {
    --wcas-primary: #your-color;
    --wcas-bg: #fff;
    --wcas-text: #333;
    --wcas-border: #ddd;
}
`

= Is the search secure? =

Yes. The plugin uses WordPress prepared statements for all database queries, nonce verification for CSRF protection, input sanitization, output escaping, and IP-based rate limiting.

= Does it work on mobile? =

Yes. On mobile devices, the search opens in a full-screen overlay with touch-optimized controls, swipe gestures, and safe area support for notched devices.

= Does it slow down my site? =

No. Assets (CSS/JS) are only loaded on pages that use the shortcode. Search results are cached for 60 seconds to minimize database queries. Requests are debounced on the frontend.

= How do I clear the search cache? =

The cache automatically invalidates when products are created, updated, or deleted. No manual action is needed.

== Screenshots ==

1. Search results with grouped categories, tags, and products
2. Product preview panel on desktop
3. Mobile full-screen overlay mode
4. Admin settings page with search field configuration
5. No results state with popular product suggestions

== Changelog ==

= 1.0.0 =
* Initial release
* Live AJAX search with debouncing
* Product title, description, excerpt, and SKU search
* ACF custom field search support
* Custom taxonomy search support
* Grouped results (categories, tags, taxonomies, products)
* Product preview panel with add-to-cart
* Full keyboard navigation (Arrow keys, Enter, Escape)
* Recent search history (localStorage)
* No results suggestions (popular products, top categories)
* Admin settings page under WooCommerce
* Full mobile optimization with overlay mode
* Touch gesture support (swipe to close)
* Responsive design with CSS custom properties
* Dark mode, reduced motion, and high contrast support
* Security: prepared statements, nonces, rate limiting, input sanitization
* Performance: result caching, conditional asset loading

== Upgrade Notice ==

= 1.0.0 =
Initial release.
