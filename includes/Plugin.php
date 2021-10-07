<?php
/**
 * Description of Plugin
 *
 * @author Camoo Sarl
 */

namespace Camoo\Enkap\WooCommerce;

use Camoo\Enkap\WooCommerce\Admin\PluginAdmin;
use Enkap\OAuth\Model\Status;
use WC_Geolocation;
use WC_Order;
use WC_Order_Refund;
use WP_REST_Server;

defined('ABSPATH') || exit;
if (!class_exists(Plugin::class)):

    class Plugin
    {
        public const WP_WC_ENKAP_DB_VERSION = '1.0.0';
        public const DOMAIN_TEXT = 'wc-wp-enkap';
        public const WC_ENKAP_GATEWAY_ID = 'e_nkap';
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
                'notifyForStatus' => [],
                'completeOrderForStatuses' => []
            );

            $this->mainMenuId = 'admin.php';
            $this->title = __('E-nkap - Payment Gateway for WooCommerce', self::DOMAIN_TEXT);
        }

        public function register()
        {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            require_once __DIR__ . '/Install.php';
            // do not register when WooCommerce is not enabled
            if (!is_plugin_active('woocommerce/woocommerce.php')) {
                return;
            }
            register_activation_hook($this->pluginPath, [Install::class, 'install']);

            add_filter('woocommerce_payment_gateways', [$this, 'onAddGatewayClass']);
            add_filter('plugin_action_links_' . plugin_basename($this->pluginPath),
                [$this, 'onPluginActionLinks'], 1, 1);
            add_action('plugins_loaded', [$this, 'onInit']);
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_block_enkap_css_scripts']);

            register_deactivation_hook($this->pluginPath, [$this, 'route_status_plugin_deactivate']);
            if (is_admin()) {
                PluginAdmin::instance()->register();
            }
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
            $gateways[] = WC_Enkap_Gateway::class;
            return $gateways;
        }

        public function onInit()
        {
            $this->loadGatewayClass();
            add_action('init', [__CLASS__, 'loadTextDomain']);
            add_action('rest_api_init', [$this, 'notification_route']);
            add_action('rest_api_init', [$this, 'return_route']);
        }

        public function onPluginActionLinks($links)
        {
            $link = sprintf(
                '<a href="%s">%s</a>',
                admin_url('admin.php?page=wc-settings&tab=checkout&section=e_nkap'),
                __('Settings', self::DOMAIN_TEXT)
            );
            array_unshift($links, $link);
            return $links;
        }

        public function loadGatewayClass()
        {
            if (class_exists('\\Camoo\\Enkap\\WooCommerce\\' . $this->adapterName)) {
                return;
            }
            include_once(dirname(__DIR__) . '/includes/Gateway.php');
            include_once(dirname(__DIR__) . '/vendor/autoload.php');
            require_once __DIR__ . '/Logger/Logger.php';
        }

        public static function get_webhook_url($endpoint): string
        {
            if (get_option('permalink_structure')) {
                return trailingslashit(get_home_url()) . 'wp-json/wc-e-nkap/' . sanitize_text_field($endpoint);
            }

            return add_query_arg('rest_route', '/wc-e-nkap/' . sanitize_text_field($endpoint),
                trailingslashit(get_home_url()));
        }

        public static function getWcOrderIdByMerchantReferenceId(string $merchantReferenceId): ?int
        {
            global $wpdb;
            if (!wp_is_uuid(sanitize_text_field($merchantReferenceId))) {
                return null;
            }

            $db_prepare = $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}wc_enkap_payments` WHERE `merchant_reference_id` = %s",
                $merchantReferenceId);
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return (int)$payment->wc_order_id;
        }

        public static function getLanguageKey(): string
        {
            $local = sanitize_text_field(get_locale());
            if (empty($local)) {
                return 'fr';
            }

            $localExploded = explode('_', $local);

            $lang = $localExploded[0];

            return in_array($lang, ['fr', 'en']) ? $lang : 'en';
        }

        public static function loadTextDomain(): void
        {
            load_plugin_textdomain(
                self::DOMAIN_TEXT,
                false,
                dirname(plugin_basename(__FILE__)) . '/languages'
            );
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

        public static function processWebhookStatus($order, string $status, string $merchantReferenceId)
        {
            switch (sanitize_text_field($status)) {
                case Status::IN_PROGRESS_STATUS:
                case Status::CREATED_STATUS:
                case Status::INITIALISED_STATUS:
                    self::processWebhookProgress($order, $merchantReferenceId, $status);
                    break;
                case Status::CONFIRMED_STATUS:
                    self::processWebhookConfirmed($order, $merchantReferenceId);
                    break;
                case Status::CANCELED_STATUS:
                    self::processWebhookCanceled($order, $merchantReferenceId);
                    break;
                case Status::FAILED_STATUS:
                    self::processWebhookFailed($order, $merchantReferenceId);
                    break;
                default:
                    break;
            }
        }

        /**
         * @param bool|WC_Order|WC_Order_Refund $order
         */
        private static function processWebhookConfirmed($order, string $merchantReferenceId)
        {
            $order->update_status('completed');
            wc_reduce_stock_levels($order->get_id());
            self::applyStatusChange(Status::CONFIRMED_STATUS, $merchantReferenceId);
            $order->add_order_note(__('E-nkap payment completed', Plugin::DOMAIN_TEXT), true);
        }

        /**
         * @param bool|WC_Order|WC_Order_Refund $order
         */
        private static function processWebhookProgress($order, string $merchantReferenceId, string $realStatus)
        {
            $order->update_status('pending');
            self::applyStatusChange($realStatus, $merchantReferenceId);
            do_action('woocommerce_order_edit_status', $order->get_id(), 'pending');
        }

        /**
         * @param bool|WC_Order|WC_Order_Refund $order
         */
        private static function processWebhookCanceled($order, string $merchantReferenceId)
        {
            $order->update_status('cancelled');
            self::applyStatusChange(Status::CANCELED_STATUS, $merchantReferenceId);
            $order->add_order_note(__('E-nkap payment cancelled', Plugin::DOMAIN_TEXT), true);
            do_action('woocommerce_order_edit_status', $order->get_id(), 'cancelled');
        }

        /**
         * @param bool|WC_Order|WC_Order_Refund $order
         */
        private static function processWebhookFailed($order, string $merchantReferenceId)
        {
            $order->update_status('failed');
            self::applyStatusChange(Status::FAILED_STATUS, $merchantReferenceId);
            $order->add_order_note(__('E-nkap payment failed', Plugin::DOMAIN_TEXT), true);
            do_action('woocommerce_order_edit_status', $order->get_id(), 'failed');
        }

        private static function applyStatusChange(string $status, string $merchantReferenceId)
        {
            global $wpdb;
            $remoteIp = WC_Geolocation::get_ip_address();
            $setData = [
                'status_date' => current_time('mysql'),
                'status' => sanitize_title($status)
            ];
            if ($remoteIp) {
                $setData['remote_ip'] = sanitize_text_field($remoteIp);
            }
            $wpdb->update(
                $wpdb->prefix . "wc_enkap_payments",
                $setData,
                [
                    'merchant_reference_id' => sanitize_text_field($merchantReferenceId)
                ]
            );

        }
    }

endif;
