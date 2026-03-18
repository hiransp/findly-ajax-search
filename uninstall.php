<?php
/**
 * WC Custom AJAX Search - Uninstall
 *
 * Cleans up all plugin data when the plugin is deleted via the WordPress admin.
 * This file is called automatically by WordPress — it is NOT called on deactivation.
 *
 * @package WC_Custom_AJAX_Search
 * @license GPL-2.0-or-later
 */

// Abort if not called by WordPress uninstall process
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete plugin settings
delete_option('wcas_settings');

// Delete all search result cache transients
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wcas_%'
     OR option_name LIKE '_transient_timeout_wcas_%'"
);
