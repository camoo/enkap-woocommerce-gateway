<?php

/**
 * Plugin Name: SmobilPay for e-commerce - Mobile Money Gateway for WooCommerce
 * Requires Plugins: woocommerce
 * Plugin URI: https://enkap.cm/
 * Description: Receive Mobile Money payments on your store using SmobilPay for e-commerce.
 * Version: 1.0.9
 * Tested up to: 6.9
 * WC requires at least: 3.2
 * WC tested up to: 8.9.1
 * Author: Camoo Sarl
 * Author URI: https://www.camoo.cm/
 * Developer: Camoo Sarl
 * Developer URI: http://www.camoo.cm/
 * Text Domain: wc-wp-enkap
 * Domain Path: /languages
 * Requires at least: 4.8
 * Requires PHP: 7.3
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

namespace Camoo\Enkap\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/Plugin.php';
require_once __DIR__ . '/includes/admin/PluginAdmin.php';

/**
 * Declare HPOS (High-Performance Order Storage) compatibility
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
        );
    }
});

(new Plugin(
    __FILE__,
    'WC_Enkap_Gateway',
    'Gateway',
    sprintf(
        '%s<br/><a href="%s" target="_blank">%s</a><br/><a href="%s" target="_blank">%s</a>',
        __('SmobilPay for e-commerce payment gateway', Plugin::DOMAIN_TEXT),
        'https://enkap.cm/#comptenkap',
        __('Do you have any questions or requests?', Plugin::DOMAIN_TEXT),
        'https://github.com/camoo/enkap-woocommerce-gateway',
        __('Do you like our plugin and can recommend to others.', Plugin::DOMAIN_TEXT)
    ),
    '1.0.9'
))->register();
