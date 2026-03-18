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
    const MOBILE_BREAKPOINT = 768;
    const SWIPE_THRESHOLD = 50;
    const DANGEROUS_PATTERNS = [
        /<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi,
        /<[^>]+>/g,
        /javascript:/gi,
        /on\w+\s*=/gi,
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
     * Check if device is mobile
     */
    function isMobile() {
        return window.innerWidth <= MOBILE_BREAKPOINT;
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
     * Sanitize search input
     */
    function sanitizeSearchInput(input) {
        if (typeof input !== 'string') {
            return false;
        }
        
        let sanitized = input.trim().replace(/\s+/g, ' ');
        
        if (sanitized.length > MAX_SEARCH_LENGTH) {
            sanitized = sanitized.substring(0, MAX_SEARCH_LENGTH);
        }
        
        for (const pattern of DANGEROUS_PATTERNS) {
            if (pattern.test(sanitized)) {
                console.warn('WCAS: Potentially dangerous input detected');
                return false;
            }
        }
        
        sanitized = sanitized.replace(/<[^>]*>/g, '');
        
        sanitized = sanitized
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#x27;');
        
        sanitized = $('<textarea/>').html(sanitized).text();
        
        return sanitized;
    }

    /**
     * Lock body scroll (for mobile overlay)
     */
    function lockBodyScroll() {
        const scrollY = window.scrollY;
        document.body.style.position = 'fixed';
        document.body.style.top = `-${scrollY}px`;
        document.body.style.width = '100%';
        document.body.classList.add('wcas-overlay-active');
    }

    /**
     * Unlock body scroll
     */
    function unlockBodyScroll() {
        const scrollY = document.body.style.top;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        document.body.classList.remove('wcas-overlay-active');
        window.scrollTo(0, parseInt(scrollY || '0') * -1);
    }

    // ==========================================
    // Touch Gesture Handler
    // ==========================================
    class TouchGesture {
        constructor(element, callbacks = {}) {
            this.element = element;
            this.callbacks = callbacks;
            this.startY = 0;
            this.startX = 0;
            this.currentY = 0;
            this.currentX = 0;
            this.isDragging = false;
            
            this.bindEvents();
        }

        bindEvents() {
            this.element.addEventListener('touchstart', this.onTouchStart.bind(this), { passive: true });
            this.element.addEventListener('touchmove', this.onTouchMove.bind(this), { passive: false });
            this.element.addEventListener('touchend', this.onTouchEnd.bind(this), { passive: true });
        }

        onTouchStart(e) {
            this.startY = e.touches[0].clientY;
            this.startX = e.touches[0].clientX;
            this.isDragging = true;
            
            if (this.callbacks.onStart) {
                this.callbacks.onStart(e);
            }
        }

        onTouchMove(e) {
            if (!this.isDragging) return;
            
            this.currentY = e.touches[0].clientY;
            this.currentX = e.touches[0].clientX;
            
            const deltaY = this.currentY - this.startY;
            const deltaX = this.currentX - this.startX;
            
            if (this.callbacks.onMove) {
                this.callbacks.onMove(e, deltaX, deltaY);
            }
        }

        onTouchEnd(e) {
            if (!this.isDragging) return;
            this.isDragging = false;
            
            const deltaY = this.currentY - this.startY;
            const deltaX = this.currentX - this.startX;
            
            // Detect swipe direction
            if (Math.abs(deltaY) > SWIPE_THRESHOLD) {
                if (deltaY > 0 && this.callbacks.onSwipeDown) {
                    this.callbacks.onSwipeDown(e, deltaY);
                } else if (deltaY < 0 && this.callbacks.onSwipeUp) {
                    this.callbacks.onSwipeUp(e, Math.abs(deltaY));
                }
            }
            
            if (Math.abs(deltaX) > SWIPE_THRESHOLD) {
                if (deltaX > 0 && this.callbacks.onSwipeRight) {
                    this.callbacks.onSwipeRight(e, deltaX);
                } else if (deltaX < 0 && this.callbacks.onSwipeLeft) {
                    this.callbacks.onSwipeLeft(e, Math.abs(deltaX));
                }
            }
            
            if (this.callbacks.onEnd) {
                this.callbacks.onEnd(e, deltaX, deltaY);
            }
        }

        destroy() {
            this.element.removeEventListener('touchstart', this.onTouchStart);
            this.element.removeEventListener('touchmove', this.onTouchMove);
            this.element.removeEventListener('touchend', this.onTouchEnd);
        }
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
                return data ? JSON.parse(data) : [];
            } catch (e) {
                return [];
            }
        }

        add(term) {
            if (!term || typeof term !== 'string') return;
            term = term.trim();
            if (term.length < 2) return;

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
            this.$searchBox = $wrapper.find('.wcas-search-box');
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
            this.isMobileMode = false;
            this.previewGesture = null;
            this.originalScrollPosition = 0;
            this.showingHistory = false;

            // Search history
            this.history = wcasConfig.enableSearchHistory
                ? new SearchHistory(wcasConfig.maxRecentSearches || 5)
                : null;

            this.init();
        }

        init() {
            // Add mobile UI elements
            this.addMobileElements();
            
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

            // Focus events - activate mobile mode and show history
            this.$input.on('focus', () => {
                if (isMobile()) {
                    this.activateMobileMode();
                }

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

            // Mobile back button
            this.$wrapper.on('click touchend', '.wcas-mobile-back', (e) => {
                e.preventDefault();
                this.deactivateMobileMode();
            });

            // Keyboard navigation
            this.$input.on('keydown', (e) => this.handleKeyboard(e));

            // Close on outside click (desktop only)
            $(document).on('click', (e) => {
                if (this.isMobileMode) return;
                
                if (!this.$wrapper.is(e.target) && this.$wrapper.has(e.target).length === 0) {
                    this.hideResults();
                }
            });

            // Close on escape
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape') {
                    if (this.isMobileMode) {
                        this.deactivateMobileMode();
                    } else {
                        this.hideResults();
                        this.$input.blur();
                    }
                }
            });

            // Handle resize
            const handleResize = throttle(() => {
                if (this.isMobileMode && !isMobile()) {
                    this.deactivateMobileMode();
                }
            }, 200);
            
            $(window).on('resize', handleResize);

            // Handle back button (mobile)
            window.addEventListener('popstate', () => {
                if (this.isMobileMode) {
                    this.deactivateMobileMode();
                }
            });
        }

        /**
         * Add mobile-specific UI elements
         */
        addMobileElements() {
            // Add back button to search box
            const $backBtn = $(`
                <span class="wcas-mobile-back" aria-label="${wcasConfig.i18n.back || 'Back'}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5"></path>
                        <path d="m12 19-7-7 7-7"></path>
                    </svg>
                </span>
            `);
            this.$searchBox.prepend($backBtn);
            
            // Add mobile header to preview panel
            const $previewHeader = $(`
                <div class="wcas-preview-mobile-header">
                    <div class="wcas-preview-drag-handle"></div>
                    <span class="wcas-preview-close" aria-label="${wcasConfig.i18n.close || 'Close'}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18"></path>
                            <path d="m6 6 12 12"></path>
                        </svg>
                    </span>
                </div>
            `);
            this.$previewPanel.prepend($previewHeader);
            
            // Preview close button
            this.$previewPanel.on('click touchend', '.wcas-preview-close', (e) => {
                e.preventDefault();
                this.hideMobilePreview();
            });
        }

        /**
         * Activate mobile full-screen mode
         */
        activateMobileMode() {
            if (this.isMobileMode) return;
            
            this.isMobileMode = true;
            this.originalScrollPosition = window.scrollY;
            
            // Add mobile class
            this.$wrapper.addClass('wcas-mobile-active');
            
            // Lock body scroll
            lockBodyScroll();
            
            // Push history state for back button support
            history.pushState({ wcasSearch: true }, '');
            
            // Focus input after transition
            setTimeout(() => {
                this.$input.focus();
            }, 100);
        }

        /**
         * Deactivate mobile mode
         */
        deactivateMobileMode() {
            if (!this.isMobileMode) return;
            
            this.isMobileMode = false;
            
            // Remove mobile class
            this.$wrapper.removeClass('wcas-mobile-active');
            
            // Hide preview if visible
            this.hideMobilePreview();
            
            // Unlock body scroll
            unlockBodyScroll();
            
            // Blur input
            this.$input.blur();
            
            // Restore scroll position
            window.scrollTo(0, this.originalScrollPosition);
        }

        /**
         * Show mobile preview panel
         */
        showMobilePreview(product) {
            this.showPreview(product);
            this.$previewPanel.addClass('wcas-preview-visible');
            
            // Setup swipe to close gesture
            if (isTouchDevice() && !this.previewGesture) {
                this.previewGesture = new TouchGesture(this.$previewPanel[0], {
                    onSwipeDown: (e, delta) => {
                        if (delta > 100) {
                            this.hideMobilePreview();
                        }
                    }
                });
            }
        }

        /**
         * Hide mobile preview panel
         */
        hideMobilePreview() {
            this.$previewPanel.removeClass('wcas-preview-visible');
            
            if (this.previewGesture) {
                this.previewGesture.destroy();
                this.previewGesture = null;
            }
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
                            <span class="wcas-product-expand">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m9 18 6-6-6-6"/>
                                </svg>
                            </span>
                        </a>
                    </li>
                `);

                // Desktop: hover to show preview
                if (!isTouchDevice()) {
                    $item.on('mouseenter', () => {
                        this.showPreview(product);
                        this.selectedIndex = index;
                        this.updateSelection();
                    });
                }

                // Mobile: tap expand button to show preview
                $item.find('.wcas-product-expand').on('click touchend', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.selectedIndex = index;
                    this.updateSelection();
                    
                    if (this.isMobileMode) {
                        this.showMobilePreview(product);
                    } else {
                        this.showPreview(product);
                    }
                });

                // Mobile: tap on product to navigate (unless tapping expand)
                $item.find('a').on('click', (e) => {
                    if (this.isMobileMode && $(e.target).closest('.wcas-product-expand').length) {
                        e.preventDefault();
                    }
                });

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

            // Keep the mobile header if it exists
            const $header = this.$previewPanel.find('.wcas-preview-mobile-header').detach();
            this.$previewPanel.html(previewHtml);
            if ($header.length) {
                this.$previewPanel.prepend($header);
            }

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
                    action: 'woocommerce_ajax_add_to_cart',
                    product_id: productId,
                    quantity: quantity
                },
                success: (response) => {
                    if (response.error && response.product_url) {
                        window.location = response.product_url;
                    } else {
                        $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $button]);
                        $button.removeClass('loading').addClass('added');
                        $button.text(wcasConfig.i18n.added || 'Added!');
                        
                        setTimeout(() => {
                            $button.removeClass('added');
                            $button.text(wcasConfig.i18n.addToCart);
                        }, 2000);
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
            // Keep the mobile header
            const $header = this.$previewPanel.find('.wcas-preview-mobile-header').detach();
            
            this.$previewPanel.html(`
                <div class="wcas-preview-placeholder">
                    <span>${wcasConfig.i18n.hoverPreview || 'Hover over a product to see details'}</span>
                </div>
            `);
            
            if ($header.length) {
                this.$previewPanel.prepend($header);
            }
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
            this.hideMobilePreview();
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

        handleKeyboard(e) {
            if (!this.$resultsWrapper.hasClass('active')) return;

            const $items = this.$resultsList.find('.wcas-product-item');
            const itemCount = $items.length;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.selectedIndex = Math.min(this.selectedIndex + 1, itemCount - 1);
                    this.updateSelection();
                    if (this.products[this.selectedIndex]) {
                        this.showPreview(this.products[this.selectedIndex]);
                    }
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    this.selectedIndex = Math.max(this.selectedIndex - 1, 0);
                    this.updateSelection();
                    if (this.products[this.selectedIndex]) {
                        this.showPreview(this.products[this.selectedIndex]);
                    }
                    break;

                case 'Enter':
                    e.preventDefault();
                    if (this.selectedIndex >= 0 && this.products[this.selectedIndex]) {
                        window.location.href = this.products[this.selectedIndex].url;
                    } else {
                        const $seeAll = this.$resultsList.find('.wcas-see-all a');
                        if ($seeAll.length) {
                            window.location.href = $seeAll.attr('href');
                        }
                    }
                    break;
            }
        }

        updateSelection() {
            const $items = this.$resultsList.find('.wcas-product-item');
            $items.removeClass('selected');
            
            if (this.selectedIndex >= 0) {
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
