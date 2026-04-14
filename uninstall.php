<?php

/**
 * Uninstalling SmobilPay for e-commerce - Mobile Money Gateway for WooCommerce, deletes tables, and options.
 *
 * @version 1.0.3
 *
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

/**
 * Delete plugin data for a single site
 */
function enkap_delete_site_data(): void
{
    global $wpdb;

    delete_option('wp_wc_enkap_db_version');

    // Delete WooCommerce settings safely
    $options = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('woocommerce_e_nkap') . '%'
        )
    );

    foreach ($options as $option) {
        delete_option($option);
    }

    // Drop table
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_enkap_payments");
}

/**
 * Multisite support
 */
if (is_multisite()) {
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");

    foreach ($blog_ids as $blog_id) {
        switch_to_blog((int)$blog_id);
        enkap_delete_site_data();
        restore_current_blog();
    }
} else {
    enkap_delete_site_data();
}
