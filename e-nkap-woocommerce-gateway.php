<?php

/**
 * Plugin Name: SmobilPay for e-commerce - Mobile Money Gateway for WooCommerce
 * Plugin URI: https://enkap.cm/
 * Description: Receive Mobile Money payments on your store using SmobilPay for e-commerce.
 * Version: 1.0.2
 * Tested up to: 5.8.1
 * WC requires at least: 3.2
 * WC tested up to: 5.7.1
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

defined('ABSPATH') || exit;
require_once(__DIR__ . '/includes/Plugin.php');
require_once(__DIR__ . '/includes/admin/PluginAdmin.php');

(new Plugin(
    __FILE__,
    'WC_Enkap_Gateway',
    'Gateway',
    sprintf('%s<br/><a href="%s" target="_blank">%s</a><br/><a href="%s" target="_blank">%s</a>',
        __('SmobilPay for e-commerce payment gateway', Plugin::DOMAIN_TEXT),
        'https://enkap.cm/#comptenkap',
        __('Do you have any questions or requests?', Plugin::DOMAIN_TEXT),
        'https://github.com/camoo/enkap-woocommerce-gateway',
        __('Do you like our plugin and can recommend to others.', Plugin::DOMAIN_TEXT)),
    '1.0.2'
)
)->register();
