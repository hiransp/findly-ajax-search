/**
 * WC Custom AJAX Search - Frontend JavaScript
 * 
 * Features:
 * - Full mobile optimization with overlay mode
 * - Touch gesture support (swipe to close)
 * - Responsive behavior detection
 * - Security: input validation, XSS prevention, throttling
 * - Keyboard navigation
 * - AJAX add to cart
 * - Lazy loading ready
 */
(function($) {
    'use strict';

    // ==========================================
    // Constants
    // ==========================================
    const MAX_SEARCH_LENGTH = 100;
    // IMPORTANT: No global (g) flag — using g with test() causes lastIndex
    // to advance, making every second call return false (a known JS bug pattern).
    const DANGEROUS_PATTERNS = [
        /<script\b/i,
        /javascript\s*:/i,
        /on\w+\s*=/i,
        /data\s*:\s*text\/html/i,
        /vbscript\s*:/i,
        /expression\s*\(/i,
    ];

    // ==========================================
    // Utility Functions
    // ==========================================
    
    /**
     * Debounce function
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Throttle function
     */
    function throttle(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    /**
     * Check if touch device
     */
    function isTouchDevice() {
        return ('ontouchstart' in window) || 
               (navigator.maxTouchPoints > 0) || 
               (navigator.msMaxTouchPoints > 0);
    }

    /**
     * Escape HTML entities to prevent XSS when inserting into DOM
     */
    function escapeHtml(str) {
        if (typeof str !== 'string') return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#x27;');
    }

    /**
     * Sanitize search input — validates and cleans user input before
     * sending to the server. The server does its own sanitization too
     * (defense-in-depth), but this catches obvious attacks early and
     * provides a better UX (no round-trip for clearly invalid input).
     */
    function sanitizeSearchInput(input) {
        if (typeof input !== 'string') {
            return false;
        }

        // Strip control characters (keeps printable + whitespace)
        let sanitized = input.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');

        sanitized = sanitized.trim().replace(/\s+/g, ' ');

        if (sanitized.length > MAX_SEARCH_LENGTH) {
            sanitized = sanitized.substring(0, MAX_SEARCH_LENGTH);
        }

        // Check for dangerous patterns
        for (const pattern of DANGEROUS_PATTERNS) {
            if (pattern.test(sanitized)) {
                return false;
            }
        }

        // Strip all HTML tags
        sanitized = sanitized.replace(/<[^>]*>/g, '');

        // Decode any HTML entities back to plain text (so the server
        // receives the actual search term, not encoded entities)
        sanitized = $('<textarea/>').html(sanitized).text();

        return sanitized;
    }

    // ==========================================
    // Search History Manager
    // ==========================================
    class SearchHistory {
        constructor(maxItems) {
            this.storageKey = 'wcas_recent_searches';
            this.maxItems = maxItems || 5;
        }

        getAll() {
            try {
                const data = localStorage.getItem(this.storageKey);
                if (!data) return [];
                const parsed = JSON.parse(data);
                // Validate: must be array of strings, sanitize each
                if (!Array.isArray(parsed)) return [];
                return parsed
                    .filter(s => typeof s === 'string' && s.length > 0 && s.length <= MAX_SEARCH_LENGTH)
                    .map(s => s.replace(/<[^>]*>/g, '').trim())
                    .filter(Boolean)
                    .slice(0, this.maxItems);
            } catch (e) {
                // Corrupted data — clear it
                this.clear();
                return [];
            }
        }

        add(term) {
            if (!term || typeof term !== 'string') return;
            // Strip tags before storing (defense against poisoned data)
            term = term.replace(/<[^>]*>/g, '').trim();
            if (term.length < 2 || term.length > MAX_SEARCH_LENGTH) return;

            let searches = this.getAll();
            // Remove duplicate if exists
            searches = searches.filter(s => s.toLowerCase() !== term.toLowerCase());
            // Add to front
            searches.unshift(term);
            // Trim to max
            searches = searches.slice(0, this.maxItems);

            try {
                localStorage.setItem(this.storageKey, JSON.stringify(searches));
            } catch (e) {
                // localStorage full or unavailable
            }
        }

        remove(term) {
            let searches = this.getAll();
            searches = searches.filter(s => s.toLowerCase() !== term.toLowerCase());
            try {
                localStorage.setItem(this.storageKey, JSON.stringify(searches));
            } catch (e) {
                // ignore
            }
        }

        clear() {
            try {
                localStorage.removeItem(this.storageKey);
            } catch (e) {
                // ignore
            }
        }
    }

    // ==========================================
    // Main Search Class
    // ==========================================
    class WCASSearch {
        constructor($wrapper) {
            this.$wrapper = $wrapper;
            this.$input = $wrapper.find('.wcas-search-input');
            this.$resultsWrapper = $wrapper.find('.wcas-results-wrapper');
            this.$resultsList = $wrapper.find('.wcas-results-list');
            this.$previewPanel = $wrapper.find('.wcas-preview-panel');
            this.$spinner = $wrapper.find('.wcas-spinner');
            this.$clear = $wrapper.find('.wcas-clear');

            this.currentRequest = null;
            this.selectedIndex = -1;
            this.products = [];
            this.lastSearchTime = 0;
            this.minRequestInterval = 100;
            this.showingHistory = false;

            // Search history
            this.history = wcasConfig.enableSearchHistory
                ? new SearchHistory(wcasConfig.maxRecentSearches || 5)
                : null;

            this.init();
        }

        init() {
            // Set max length
            this.$input.attr('maxlength', MAX_SEARCH_LENGTH);
            
            // Debounced search
            const debouncedSearch = debounce(
                (term) => this.performSearch(term),
                wcasConfig.debounceDelay
            );

            // Input events
            this.$input.on('input', (e) => {
                const rawTerm = e.target.value;
                const term = sanitizeSearchInput(rawTerm);
                
                if (term === false) {
                    this.hideResults();
                    this.$clear.removeClass('active');
                    return;
                }
                
                if (term !== rawTerm.trim()) {
                    this.$input.val(term);
                }
                
                if (term.length >= wcasConfig.minChars) {
                    this.$spinner.addClass('active');
                    this.$clear.removeClass('active');
                    debouncedSearch(term);
                } else {
                    this.hideResults();
                    this.$clear.removeClass('active');
                }
                
                if (term.length > 0) {
                    this.$clear.addClass('active');
                }
            });
            
            // Paste handler
            this.$input.on('paste', (e) => {
                setTimeout(() => {
                    const val = this.$input.val();
                    if (val.length > MAX_SEARCH_LENGTH) {
                        this.$input.val(val.substring(0, MAX_SEARCH_LENGTH));
                    }
                }, 0);
            });

            // Focus events - show history
            this.$input.on('focus', () => {
                const val = this.$input.val().trim();
                if (val.length >= wcasConfig.minChars && this.$resultsList.children().length > 0 && !this.showingHistory) {
                    this.showResults();
                } else if (val.length === 0 && this.history) {
                    this.showSearchHistory();
                }
            });

            // Clear button
            this.$clear.on('click touchend', (e) => {
                e.preventDefault();
                this.$input.val('').focus();
                this.hideResults();
                this.$clear.removeClass('active');
                // Show search history after clearing
                if (this.history) {
                    this.showSearchHistory();
                }
            });

            // Keyboard navigation
            this.$input.on('keydown', (e) => this.handleKeyboard(e));

            // Close on outside click
            $(document).on('click', (e) => {
                if (!this.$wrapper.is(e.target) && this.$wrapper.has(e.target).length === 0) {
                    this.hideResults();
                }
            });

            // Close on escape
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape') {
                    this.hideResults();
                    this.$input.blur();
                }
            });
        }

        performSearch(term) {
            const now = Date.now();
            if (now - this.lastSearchTime < this.minRequestInterval) {
                return;
            }
            this.lastSearchTime = now;
            
            const sanitizedTerm = sanitizeSearchInput(term);
            if (!sanitizedTerm || sanitizedTerm.length < wcasConfig.minChars) {
                this.$spinner.removeClass('active');
                return;
            }
            
            if (this.currentRequest) {
                this.currentRequest.abort();
            }

            this.currentRequest = $.ajax({
                url: wcasConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wcas_search',
                    nonce: wcasConfig.nonce,
                    search: sanitizedTerm
                },
                success: (response) => {
                    this.$spinner.removeClass('active');
                    
                    if (response.success) {
                        this.renderResults(response.data);
                        this.showResults();
                    } else {
                        this.showNoResults();
                    }
                },
                error: (xhr, status) => {
                    if (status !== 'abort') {
                        this.$spinner.removeClass('active');
                        
                        if (xhr.status === 429) {
                            this.showRateLimited();
                        } else {
                            this.showNoResults();
                        }
                    }
                }
            });
        }

        renderResults(data) {
            this.$resultsList.empty();
            this.products = data.products || [];
            this.selectedIndex = -1;
            this.showingHistory = false;

            let hasResults = false;

            // Render categories
            if (data.categories && data.categories.length > 0) {
                hasResults = true;
                this.renderSection('categories', wcasConfig.i18n.categories || 'Categories', data.categories);
            }

            // Render tags
            if (data.tags && data.tags.length > 0) {
                hasResults = true;
                this.renderSection('tags', wcasConfig.i18n.tags || 'Tags', data.tags);
            }

            // Render custom taxonomies
            if (data.custom_taxonomies && data.custom_taxonomies.length > 0) {
                data.custom_taxonomies.forEach((taxData) => {
                    if (taxData.terms && taxData.terms.length > 0) {
                        hasResults = true;
                        this.renderSection('taxonomy-' + taxData.taxonomy, taxData.label, taxData.terms);
                    }
                });
            }

            // Render products
            if (data.products && data.products.length > 0) {
                hasResults = true;
                this.renderProducts(data.products);
            }

            // Render "See all results" link
            if (data.total_count > 0) {
                const $seeAll = $(`
                    <div class="wcas-see-all">
                        <a href="${data.search_url}">
                            ${wcasConfig.i18n.seeAllResults} (${data.total_count})
                        </a>
                    </div>
                `);

                // Clear keyboard selection on mouse hover
                if (!isTouchDevice()) {
                    $seeAll.on('mouseenter', () => {
                        this.selectedIndex = -1;
                        this.getNavigableItems().removeClass('selected');
                    });
                }

                this.$resultsList.append($seeAll);
            }

            if (hasResults) {
                // Save successful search to history
                if (this.history) {
                    const currentTerm = this.$input.val().trim();
                    if (currentTerm.length >= wcasConfig.minChars) {
                        this.history.add(currentTerm);
                    }
                }
            } else {
                // Show no results with optional suggestions
                this.showNoResults(data.suggestions);
            }

            this.resetPreview();
        }

        renderSection(type, label, items) {
            const $section = $(`
                <div class="wcas-section wcas-section-${type}">
                    <div class="wcas-section-header">${label}</div>
                    <ul class="wcas-section-items"></ul>
                </div>
            `);

            const $list = $section.find('.wcas-section-items');

            items.forEach((item) => {
                const $item = $(`
                    <li class="wcas-term-item">
                        <a href="${item.url}">
                            <span class="wcas-term-name">${item.name}</span>
                            ${item.count ? `<span class="wcas-term-count">(${item.count})</span>` : ''}
                        </a>
                    </li>
                `);

                // Clear keyboard selection on mouse hover
                if (!isTouchDevice()) {
                    $item.on('mouseenter', () => {
                        this.selectedIndex = -1;
                        this.getNavigableItems().removeClass('selected');
                    });
                }

                $list.append($item);
            });

            this.$resultsList.append($section);
        }

        renderProducts(products) {
            const $section = $(`
                <div class="wcas-section wcas-section-products">
                    <div class="wcas-section-header">${wcasConfig.i18n.products || 'Products'}</div>
                    <ul class="wcas-section-items wcas-products-list"></ul>
                </div>
            `);

            const $list = $section.find('.wcas-section-items');

            products.forEach((product, index) => {
                const matchedField = product.matched_field 
                    ? `<span class="wcas-matched-field">${product.matched_field.label}: ${product.matched_field.value}</span>` 
                    : '';
                
                const $item = $(`
                    <li class="wcas-product-item" data-index="${index}">
                        <a href="${product.url}">
                            <span class="wcas-product-thumb">
                                <img src="${product.image}" alt="${product.name_raw}" loading="lazy">
                            </span>
                            <span class="wcas-product-info">
                                <span class="wcas-product-name">${product.name}</span>
                                ${product.sku ? `<span class="wcas-product-sku">(SKU: ${product.sku})</span>` : ''}
                                ${matchedField}
                                <span class="wcas-product-desc">${product.description}</span>
                            </span>
                            <span class="wcas-product-price">${product.price}</span>
                        </a>
                    </li>
                `);

                // Hover to show preview (desktop only)
                if (!isTouchDevice()) {
                    $item.on('mouseenter', () => {
                        this.showPreview(product);
                        this.selectedIndex = -1;
                        this.getNavigableItems().removeClass('selected');
                    });
                }

                $list.append($item);
            });

            this.$resultsList.append($section);
        }

        showPreview(product) {
            const stockStatus = product.in_stock 
                ? `<span class="wcas-in-stock">${wcasConfig.i18n.inStock || 'In Stock'}</span>` 
                : `<span class="wcas-out-of-stock">${wcasConfig.i18n.outOfStock || 'Out of Stock'}</span>`;
            
            let addToCartBtn = '';
            if (product.is_purchasable && product.in_stock) {
                if (product.type === 'simple') {
                    addToCartBtn = `
                        <div class="wcas-preview-cart">
                            <div class="wcas-qty-wrapper">
                                <button type="button" class="wcas-qty-btn wcas-qty-minus" aria-label="Decrease quantity">−</button>
                                <input type="number" class="wcas-qty-input" value="1" min="1" max="99" aria-label="Quantity">
                                <button type="button" class="wcas-qty-btn wcas-qty-plus" aria-label="Increase quantity">+</button>
                            </div>
                            <a href="${product.add_to_cart_url}" class="wcas-add-to-cart button" data-product-id="${product.id}">
                                ${wcasConfig.i18n.addToCart}
                            </a>
                        </div>
                    `;
                } else {
                    addToCartBtn = `
                        <div class="wcas-preview-cart">
                            <a href="${product.url}" class="wcas-view-product button">
                                ${wcasConfig.i18n.viewProduct || 'View Product'}
                            </a>
                        </div>
                    `;
                }
            }

            const previewHtml = `
                <div class="wcas-preview-content">
                    <div class="wcas-preview-image">
                        <img src="${product.image_large}" alt="${product.name_raw}" loading="lazy">
                    </div>
                    <div class="wcas-preview-details">
                        <h4 class="wcas-preview-title">${product.name_raw}</h4>
                        ${product.sku ? `<div class="wcas-preview-sku">${product.sku}</div>` : ''}
                        <div class="wcas-preview-price">${product.price}</div>
                        <div class="wcas-preview-description">${product.description_full || product.description}</div>
                        <div class="wcas-preview-stock">${stockStatus}</div>
                        ${addToCartBtn}
                    </div>
                </div>
            `;

            this.$previewPanel.html(previewHtml);

            // Quantity buttons
            this.$previewPanel.find('.wcas-qty-minus').on('click touchend', function(e) {
                e.preventDefault();
                const $input = $(this).siblings('.wcas-qty-input');
                const val = parseInt($input.val()) || 1;
                if (val > 1) $input.val(val - 1);
            });

            this.$previewPanel.find('.wcas-qty-plus').on('click touchend', function(e) {
                e.preventDefault();
                const $input = $(this).siblings('.wcas-qty-input');
                const val = parseInt($input.val()) || 1;
                if (val < 99) $input.val(val + 1);
            });

            // AJAX add to cart
            this.$previewPanel.find('.wcas-add-to-cart').on('click touchend', (e) => {
                e.preventDefault();
                const qty = parseInt(this.$previewPanel.find('.wcas-qty-input').val()) || 1;
                this.addToCart(product.id, qty, $(e.currentTarget));
            });
        }

        addToCart(productId, quantity, $button) {
            $button.addClass('loading').prop('disabled', true);

            $.ajax({
                url: wcasConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wcas_add_to_cart',
                    nonce: wcasConfig.nonce,
                    product_id: productId,
                    quantity: quantity
                },
                success: (response) => {
                    if (response.success) {
                        // Trigger WooCommerce event so theme cart widgets update
                        $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $button]);
                        $button.removeClass('loading').addClass('added');
                        $button.text(wcasConfig.i18n.added || 'Added!');

                        setTimeout(() => {
                            $button.removeClass('added');
                            $button.text(wcasConfig.i18n.addToCart);
                        }, 2000);
                    } else {
                        // Error — redirect to product page if provided
                        if (response.data && response.data.product_url) {
                            window.location = response.data.product_url;
                        } else {
                            $button.removeClass('loading');
                        }
                    }
                },
                error: () => {
                    $button.removeClass('loading');
                },
                complete: () => {
                    $button.prop('disabled', false);
                }
            });
        }

        resetPreview() {
            this.$previewPanel.html(`
                <div class="wcas-preview-placeholder">
                    <span>${wcasConfig.i18n.hoverPreview || 'Hover over a product to see details'}</span>
                </div>
            `);
        }

        showNoResults(suggestions) {
            const i18n = wcasConfig.i18n;

            let html = `<div class="wcas-no-results">${i18n.noResultsTryAgain || i18n.noResults}</div>`;

            // Render suggestions if available
            if (suggestions && wcasConfig.enableNoResultsSuggestions) {
                let suggestionsHtml = '';

                // Popular products
                if (suggestions.popular_products && suggestions.popular_products.length > 0) {
                    suggestionsHtml += `
                        <div class="wcas-section wcas-section-suggestions">
                            <div class="wcas-section-header">${i18n.popularProducts || 'Popular Products'}</div>
                            <ul class="wcas-section-items wcas-suggestions-products">
                    `;
                    suggestions.popular_products.forEach((product) => {
                        suggestionsHtml += `
                            <li class="wcas-product-item wcas-suggestion-item">
                                <a href="${product.url}">
                                    <span class="wcas-product-thumb">
                                        <img src="${product.image}" alt="${product.name}" loading="lazy">
                                    </span>
                                    <span class="wcas-product-info">
                                        <span class="wcas-product-name">${product.name}</span>
                                    </span>
                                    <span class="wcas-product-price">${product.price}</span>
                                </a>
                            </li>
                        `;
                    });
                    suggestionsHtml += '</ul></div>';
                }

                // Top categories
                if (suggestions.top_categories && suggestions.top_categories.length > 0) {
                    suggestionsHtml += `
                        <div class="wcas-section wcas-section-suggestions">
                            <div class="wcas-section-header">${i18n.topCategories || 'Top Categories'}</div>
                            <ul class="wcas-section-items">
                    `;
                    suggestions.top_categories.forEach((cat) => {
                        suggestionsHtml += `
                            <li class="wcas-term-item">
                                <a href="${cat.url}">
                                    <span class="wcas-term-name">${cat.name}</span>
                                    <span class="wcas-term-count">(${cat.count})</span>
                                </a>
                            </li>
                        `;
                    });
                    suggestionsHtml += '</ul></div>';
                }

                if (suggestionsHtml) {
                    html += suggestionsHtml;
                }
            }

            this.$resultsList.html(html);
            this.resetPreview();
            this.showResults();
        }

        showRateLimited() {
            this.$resultsList.html(`
                <div class="wcas-no-results wcas-rate-limited">
                    ${wcasConfig.i18n.rateLimited || 'Please slow down and try again.'}
                </div>
            `);
            this.resetPreview();
            this.showResults();
        }

        showResults() {
            this.$resultsWrapper.addClass('active');
        }

        hideResults() {
            this.$resultsWrapper.removeClass('active');
            this.selectedIndex = -1;
            this.showingHistory = false;
        }

        /**
         * Show recent search history dropdown
         */
        showSearchHistory() {
            if (!this.history) return;

            const searches = this.history.getAll();
            if (searches.length === 0) return;

            this.$resultsList.empty();
            this.showingHistory = true;
            this.selectedIndex = -1;
            this.resetPreview();

            const i18n = wcasConfig.i18n;

            const $section = $(`
                <div class="wcas-section wcas-section-history">
                    <div class="wcas-section-header wcas-history-header">
                        <span>${i18n.recentSearches || 'Recent Searches'}</span>
                        <button type="button" class="wcas-history-clear">${i18n.clearHistory || 'Clear all'}</button>
                    </div>
                    <ul class="wcas-section-items wcas-history-list"></ul>
                </div>
            `);

            const $list = $section.find('.wcas-history-list');

            searches.forEach((term) => {
                const escapedTerm = $('<span>').text(term).html();
                const $item = $(`
                    <li class="wcas-history-item">
                        <span class="wcas-history-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                                <path d="M3 3v5h5"/>
                                <path d="M12 7v5l4 2"/>
                            </svg>
                        </span>
                        <span class="wcas-history-term">${escapedTerm}</span>
                        <button type="button" class="wcas-history-remove" data-term="${escapedTerm}" title="Remove">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
                            </svg>
                        </button>
                    </li>
                `);

                // Click on term to search
                $item.find('.wcas-history-term, .wcas-history-icon').on('click', () => {
                    this.$input.val(term);
                    this.showingHistory = false;
                    this.$clear.addClass('active');
                    this.$spinner.addClass('active');
                    this.performSearch(term);
                });

                // Remove single item
                $item.find('.wcas-history-remove').on('click', (e) => {
                    e.stopPropagation();
                    this.history.remove(term);
                    $item.slideUp(150, () => {
                        $item.remove();
                        // If no more items, hide
                        if ($list.children().length === 0) {
                            this.hideResults();
                        }
                    });
                });

                $list.append($item);
            });

            // Clear all
            $section.find('.wcas-history-clear').on('click', () => {
                this.history.clear();
                this.hideResults();
            });

            this.$resultsList.append($section);
            this.showResults();
        }

        /**
         * Get all navigable items in the results list.
         * Includes: history items, taxonomy term items, product items, and "see all" link.
         */
        getNavigableItems() {
            return this.$resultsList.find('.wcas-history-item, .wcas-term-item, .wcas-product-item, .wcas-see-all');
        }

        handleKeyboard(e) {
            if (!this.$resultsWrapper.hasClass('active')) return;

            const $items = this.getNavigableItems();
            const itemCount = $items.length;
            if (itemCount === 0) return;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.selectedIndex = Math.min(this.selectedIndex + 1, itemCount - 1);
                    this.updateSelection();
                    this.onSelectionChanged($items);
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    // Allow going back to -1 (deselect all, focus stays on input)
                    this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                    this.updateSelection();
                    this.onSelectionChanged($items);
                    break;

                case 'Enter':
                    e.preventDefault();
                    this.activateSelectedItem($items);
                    break;
            }
        }

        /**
         * Handle side effects when selection changes (e.g. show preview for products)
         */
        onSelectionChanged($items) {
            if (this.selectedIndex < 0) return;

            const $selected = $items.eq(this.selectedIndex);

            // If it's a product item, show preview
            if ($selected.hasClass('wcas-product-item')) {
                const productIndex = $selected.data('index');
                if (this.products[productIndex]) {
                    this.showPreview(this.products[productIndex]);
                }
            }
        }

        /**
         * Activate (click/navigate to) the currently selected item
         */
        activateSelectedItem($items) {
            if (this.selectedIndex >= 0 && this.selectedIndex < $items.length) {
                const $selected = $items.eq(this.selectedIndex);

                // History item — fill input and search
                if ($selected.hasClass('wcas-history-item')) {
                    const term = $selected.find('.wcas-history-term').text();
                    this.$input.val(term);
                    this.showingHistory = false;
                    this.$clear.addClass('active');
                    this.$spinner.addClass('active');
                    this.performSearch(term);
                    return;
                }

                // See-all link
                if ($selected.hasClass('wcas-see-all')) {
                    const href = $selected.find('a').attr('href');
                    if (href) {
                        window.location.href = href;
                    }
                    return;
                }

                // Product or term item — follow the link
                const $link = $selected.find('a');
                if ($link.length && $link.attr('href')) {
                    window.location.href = $link.attr('href');
                }
            } else {
                // No selection — go to "See all results" if available
                const $seeAll = this.$resultsList.find('.wcas-see-all a');
                if ($seeAll.length) {
                    window.location.href = $seeAll.attr('href');
                }
            }
        }

        updateSelection() {
            const $items = this.getNavigableItems();
            $items.removeClass('selected');

            if (this.selectedIndex >= 0 && this.selectedIndex < $items.length) {
                const $selected = $items.eq(this.selectedIndex);
                $selected.addClass('selected');

                // Scroll into view
                const container = this.$resultsList[0];
                const item = $selected[0];

                if (container && item) {
                    const containerRect = container.getBoundingClientRect();
                    const itemRect = item.getBoundingClientRect();

                    if (itemRect.bottom > containerRect.bottom) {
                        container.scrollTop += itemRect.bottom - containerRect.bottom + 10;
                    } else if (itemRect.top < containerRect.top) {
                        container.scrollTop -= containerRect.top - itemRect.top + 10;
                    }
                }
            }
        }
    }

    // ==========================================
    // Initialize
    // ==========================================
    $(document).ready(function() {
        $('.wcas-wrapper').each(function() {
            new WCASSearch($(this));
        });
    });

})(jQuery);
