<?php

declare(strict_types=1);

namespace Camoo\Enkap\WooCommerce;

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class EnkapBlocksSupport extends AbstractPaymentMethodType
{
    protected $name = 'e_nkap';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_e_nkap_settings', []);
    }

    public function is_active()
    {
        return !empty($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles()
    {

        $plugin_url = plugin_dir_url(dirname(__DIR__) . '/e-nkap-woocommerce-gateway.php');

        wp_register_script(
            'enkap-blocks',
            $plugin_url . 'includes/assets/js/index.js',
            ['wc-blocks-registry', 'wp-element', 'wp-i18n'],
            Plugin::WP_WC_ENKAP_DB_VERSION,
            true
        );

        return ['enkap-blocks'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->settings['title'] ?? 'SmobilPay for e-commerce Payment.',
            'description' => $this->settings['description'] ?? '',
        ];
    }
}
