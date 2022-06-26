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

delete_option('wp_wc_enkap_db_version');

$wpdb->query("DELETE FROM $wpdb->options WHERE `option_name` LIKE 'woocommerce_e_nkap%';");

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_enkap_payments");
