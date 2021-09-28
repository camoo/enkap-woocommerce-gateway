<?php

/**
 * Plugin Name: E-nkap for WooCommerce
 * Plugin URI: https://camoo.hosting
 * Description: Take credit mobile payments on your store using E-nkap.
 * Version: 1.0.0
 * Tested up to: 5.8
 * WC requires at least: 3.2
 * WC tested up to: 4.8
 * Author: Camoo Sarl ecommerce@camoo.sarl
 * Author URI: https://www.camoo.cm/
 * Developer: Camoo Sarl
 * Developer URI: http://www.camoo.cm/
 * Text Domain: wp_enkap
 * Domain Path: /languages
 *
 * Copyright: © 2021 Camoo Sarl, CM.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Camoo\Enkap\WooCommerce;

defined('ABSPATH') || exit;
require_once(__DIR__ . '/inc/Plugin.php');
	
(new Plugin(
        __FILE__, 
        'WC_Enkap_Gateway', 
        'Gateway',
        sprintf('%s<br/><a href="%s" target="_blank">%s</a><br/><a href="%s" target="_blank">%s</a>', 
            __('E-nkap payment gateway', 'wp_enkap'),
            'https://camoo.hosting/contact-us/',
            __('Do you have any questions or requests?', 'wp_enkap'),
            'https://github.com/camoo/enkap-oauth',
            __('Do you like our plugin and can recommend to others?', 'wp_enkap')),
        '1.0.1'
    )
)->register();
