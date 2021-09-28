<?php
/**
 * Description of Plugin
 *
 * @author Camoo Sarl
 */

namespace Camoo\Enkap\WooCommerce;

if (!class_exists('\\Camoo\\Enkap\\WooCommerce\\Plugin')):

    class Plugin
    {
        protected $id;
        protected $mainMenuId;
        protected $adapterName;
        protected $title;
        protected $description;
        protected $optionKey;
        protected $settings;
        protected $adapterFile;
        protected $pluginPath;
        protected $version;
        protected $image_format = 'full';

        public function __construct($pluginPath, $adapterName, $adapterFile, $description = '', $version = null)
        {
            $this->id = basename($pluginPath, '.php');
            $this->pluginPath = $pluginPath;
            $this->adapterName = $adapterName;
            $this->adapterFile = $adapterFile;
            $this->description = $description;
            $this->version = $version;
            $this->optionKey = '';
            $this->settings = array(
                'live' => '1',
                'accountId' => '',
                'apiKey' => '',
                'notifyForStatus' => array(),
                'completeOrderForStatuses' => array()
            );

            $this->mainMenuId = 'admin.php';
            $this->title = sprintf(__('%s Payment Gateway', $this->id), 'E-nkap');
        }

        private function test()
        {

        }

        public function register()
        {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');

            // do not register when WooCommerce is not enabled
            if (!is_plugin_active('woocommerce/woocommerce.php')) {
                return;
            }

            if (0 && is_admin()) {
                add_action('admin_menu', array($this, 'onAdminMenu'));
                add_action('admin_init', array($this, 'dropdaySettingsInit'));
            }

            add_filter('woocommerce_payment_gateways', array($this, 'onAddGatewayClass'));
            add_filter('plugin_action_links_' . plugin_basename($this->pluginPath), array($this, 'onPluginActionLinks'), 1, 1);
            add_action('plugins_loaded', array($this, 'onInit'));
        }

        public function onAddGatewayClass($gateways)
        {
            $gateways[] = '\\Camoo\\Enkap\\WooCommerce\\WC_Enkap_Gateway';
            return $gateways;
        }

        public function onInit()
        {
            $this->loadGatewayClass();
        }

        public function onPluginActionLinks($links)
        {
            $link = sprintf('<a href="%s">%s</a>', admin_url('admin.php?page=wc-settings&tab=checkout&section=e_nkap'), __('Settings', $this->id));
            array_unshift($links, $link);
            return $links;
        }

        public function loadGatewayClass()
        {
            if (class_exists('\\Camoo\\Enkap\\WooCommerce\\' . $this->adapterName)) {
                return;
            }
            include_once(dirname(__DIR__) . '/inc/Gateway.php');
            include_once(dirname(__DIR__) . '/vendor/autoload.php');
        }

        public static function get_webhook_url($endpoint)
        {
            return add_query_arg('wc-api', $endpoint, trailingslashit(get_home_url()));
        }

        public static function encode_ID($id)
        {
            return base64_encode(uniqid('WC', true) . '_' . $id);
        }

        public static function decode_ID($id_code)
        {
            $id_code = base64_decode($id_code, true);
            if ($id_code) {
                $id_code = explode('_', $id_code);
                if (isset($id_code[1])) {
                    return (int)$id_code[1];
                }
            }
            return false;
        }
    }

endif;
