<?php

/**
 * Plugin Name: SmobilPay for e-commerce - Mobile Money Gateway for WooCommerce
 * Requires Plugins: woocommerce
 * Plugin URI: https://enkap.cm/
 * Description: Receive Mobile Money payments on your store using SmobilPay for e-commerce.
 * Version: 1.1.1
 * Tested up to: 6.9
 * WC requires at least: 3.2
 * WC tested up to: 8.9.1
 * Author: Camoo Sarl
 * Author URI: https://www.camoo.cm/
 * Developer: Camoo Sarl
 * Developer URI: http://www.camoo.cm/
 * Text Domain: wc-wp-enkap
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

namespace Camoo\Enkap\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/Repository/EnkapPaymentRepository.php';
require_once __DIR__ . '/includes/Repository/RepositoryException.php';
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
    'SmobilPay for e-commerce payment gateway',
    '1.1.1'
))->register();
