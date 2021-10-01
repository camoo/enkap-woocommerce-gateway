<?php
/**
 * Description of Plugin
 *
 * @author Camoo Sarl
 */

namespace Camoo\Enkap\WooCommerce;

use Enkap\OAuth\Model\Status;
use WP_REST_Server;

defined('ABSPATH') || exit;
if (!class_exists('\\Camoo\\Enkap\\WooCommerce\\Plugin')):

    class Plugin
    {
        public const DOMAIN_TEXT = 'wc-wp-enkap';
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
            $this->title = __('E-nkap - Payment Gateway for WooCommerce', self::DOMAIN_TEXT);
        }

        private function test()
        {

        }

        public function register()
        {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            require_once __DIR__ . '/InstallEnkap.php';
            // do not register when WooCommerce is not enabled
            if (!is_plugin_active('woocommerce/woocommerce.php')) {
                return;
            }
            register_activation_hook($this->pluginPath, [InstallEnkap::class, 'install']);

            /*if (is_admin()) {
                add_action('admin_menu', array($this, 'onAdminMenu'));
                add_action('admin_init', array($this, 'dropdaySettingsInit'));
            }*/

            add_filter('woocommerce_payment_gateways', [$this, 'onAddGatewayClass']);
            add_filter('plugin_action_links_' . plugin_basename($this->pluginPath), [$this, 'onPluginActionLinks'], 1, 1);
            add_action('plugins_loaded', [$this, 'onInit']);
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_block_enkap_css_scripts']);

            register_deactivation_hook($this->pluginPath, [$this, 'route_status_plugin_deactivate']);

        }

        public function route_status_plugin_deactivate()
        {
            flush_rewrite_rules();
        }

        public static function enqueue_block_enkap_css_scripts(): void
        {
            wp_enqueue_style(
                'enkap_style',
                plugins_url('/assets/css/style.css', __FILE__)
            );
        }

        public function onAddGatewayClass($gateways)
        {
            $gateways[] = '\\Camoo\\Enkap\\WooCommerce\\WC_Enkap_Gateway';
            return $gateways;
        }

        public function onInit()
        {
            $this->loadGatewayClass();
            add_action('init', [__CLASS__, 'loadTextDomain']);
            add_action('rest_api_init', [$this, 'notification_route']);
        }

        public function onPluginActionLinks($links)
        {
            $link = sprintf('<a href="%s">%s</a>',
                admin_url('admin.php?page=wc-settings&tab=checkout&section=e_nkap'),
                __('Settings', self::DOMAIN_TEXT));
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
            return trailingslashit(get_home_url()) . 'wc-e-nkap/' . $endpoint;
        }

        public static function getWcOrderIdByMerchantReferenceId($id_code)
        {

            global $wpdb;
            if (!wp_is_uuid($id_code)) {
                return null;
            }

            $db_prepare = $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_enkap_payments` WHERE `merchant_reference_id` = %s", $id_code);
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return (int)$payment->wc_order_id;
        }

        public static function getLanguageKey(): string
        {
            $local = get_locale();
            if (empty($local)) {
                return 'fr';
            }

            $localExploded = explode('_', $local);

            $lang = $localExploded[0];

            return in_array($lang, ['fr', 'en']) ? $lang : 'en';
        }

        public static function loadTextDomain(): void
        {
            load_plugin_textdomain(self::DOMAIN_TEXT, false,
                dirname(plugin_basename(__FILE__)) . '/languages');
        }

        public function return_route()
        {
            register_rest_route(
                'wc-e-nkap/return',
                '/(.*?)',
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [new WC_Enkap_Gateway(), 'onReturn'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'status' => [
                            'required' => true,
                            'validate_callback' => function ($param) {
                                return in_array($param, Status::getAllowedStatus());
                            }
                        ],

                    ],

                ]
            );
            flush_rewrite_rules();
        }

        public function notification_route()
        {
            register_rest_route(
                'wc-e-nkap/notification',
                '/(.*?)',
                [
                    'methods' => 'PUT',
                    'callback' => [new WC_Enkap_Gateway(), 'onNotification'],
                    'permission_callback' => '__return_true',
                ]
            );
            flush_rewrite_rules();
        }
    }

endif;
