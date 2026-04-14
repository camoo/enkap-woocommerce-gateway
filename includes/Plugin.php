<?php

/**
 * Description of Plugin
 *
 * @author Camoo Sarl
 */

namespace Camoo\Enkap\WooCommerce;

use Camoo\Enkap\WooCommerce\Admin\PluginAdmin;
use Camoo\Enkap\WooCommerce\Repository\EnkapPaymentRepository;
use Enkap\OAuth\Enum\PaymentStatus;
use WC_Geolocation;
use WC_Order;
use WC_Order_Refund;
use WP_REST_Server;

defined('ABSPATH') || exit;
if (!class_exists(Plugin::class)) {
    class Plugin
    {
        public const WP_WC_ENKAP_DB_VERSION = '1.1.1';

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

        private static EnkapPaymentRepository $paymentRepository;

        public function __construct($pluginPath, $adapterName, $adapterFile, $description = '', $version = null)
        {
            $this->id = basename($pluginPath, '.php');
            $this->pluginPath = $pluginPath;
            $this->adapterName = $adapterName;
            $this->adapterFile = $adapterFile;
            $this->description = $description;
            $this->version = $version;
            $this->optionKey = '';
            $this->settings = [
                'live' => '1',
                'accountId' => '',
                'apiKey' => '',
                'notifyForStatus' => [],
                'completeOrderForStatuses' => [],
            ];

            $this->mainMenuId = 'admin.php';
            $this->title = 'SmobilPay for e-commerce - Payment Gateway for WooCommerce';
            global $wpdb;

            self::$paymentRepository = new EnkapPaymentRepository($wpdb);
        }

        public function register(): void
        {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once __DIR__ . '/Install.php';
            // do not register when WooCommerce is not enabled
            if (!is_plugin_active('woocommerce/woocommerce.php')) {
                return;
            }
            register_activation_hook($this->pluginPath, [Install::class, 'install']);

            add_filter('woocommerce_payment_gateways', [$this, 'onAddGatewayClass']);
            add_filter(
                'plugin_action_links_' . plugin_basename($this->pluginPath),
                [$this, 'onPluginActionLinks'],
                1,
                1
            );
            add_action('plugins_loaded', [$this, 'onInit']);
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_block_enkap_css_scripts']);
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function ($registry) {
                    require_once __DIR__ . '/EnkapBlocksSupport.php';
                    $registry->register(new EnkapBlocksSupport());
                }
            );
            add_action(
                'woocommerce_rest_checkout_process_payment_with_context',
                function ($context, $result) {
                    if (($context->payment_method ?? '') !== Plugin::WC_ENKAP_GATEWAY_ID) {
                        return;
                    }

                    $gateway = new WC_Enkap_Gateway();

                    $response = $gateway->process_payment($context->order->get_id());

                    if (!empty($response['redirect'])) {
                        $result->set_redirect_url($response['redirect']);
                    }
                },
                10,
                2
            );

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

        public function onInit(): void
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

        public function loadGatewayClass(): void
        {
            if (class_exists('\\Camoo\\Enkap\\WooCommerce\\' . $this->adapterName)) {
                return;
            }
            include_once dirname(__DIR__) . '/includes/Gateway.php';
            include_once dirname(__DIR__) . '/vendor/autoload.php';
            require_once __DIR__ . '/Logger/Logger.php';
        }

        public static function get_webhook_url($endpoint): string
        {
            if (get_option('permalink_structure')) {
                return trailingslashit(get_home_url()) . 'wp-json/wc-e-nkap/' . sanitize_text_field($endpoint);
            }

            return add_query_arg(
                'rest_route',
                '/wc-e-nkap/' . sanitize_text_field($endpoint),
                trailingslashit(get_home_url())
            );
        }

        public static function getWcOrderIdByMerchantReferenceId(string $merchantReferenceId): ?int
        {
            if (!wp_is_uuid(sanitize_text_field($merchantReferenceId))) {
                return null;
            }

            return self::$paymentRepository->getWcOrderIdByMerchantReferenceId(
                sanitize_text_field($merchantReferenceId)
            );
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
                dirname(plugin_basename(__FILE__), 2) . '/languages'
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
                                return in_array($param, PaymentStatus::getAllStatuses());
                            },
                        ],
                    ],
                ]
            );
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
        }

        public static function processWebhookStatus($order, PaymentStatus $status, string $merchantReferenceId): void
        {
            match ($status) {
                PaymentStatus::IN_PROGRESS_STATUS,
                PaymentStatus::CREATED_STATUS,
                PaymentStatus::INITIALISED_STATUS => self::processWebhookProgress($order, $merchantReferenceId, $status),
                PaymentStatus::CONFIRMED_STATUS => self::processWebhookConfirmed($order, $merchantReferenceId),
                PaymentStatus::CANCELED_STATUS => self::processWebhookCanceled($order, $merchantReferenceId),
                PaymentStatus::FAILED_STATUS => self::processWebhookFailed($order, $merchantReferenceId),

                default => null,
            };
        }

        /** @param bool|WC_Order|WC_Order_Refund $order */
        private static function processWebhookConfirmed($order, string $merchantReferenceId): void
        {
            $order->update_status('completed');
            wc_reduce_stock_levels($order->get_id());
            self::applyStatusChange(PaymentStatus::CONFIRMED_STATUS, $merchantReferenceId);
            $order->add_order_note(__('SmobilPay payment completed', Plugin::DOMAIN_TEXT), true);
        }

        /** @param bool|WC_Order|WC_Order_Refund $order */
        private static function processWebhookProgress($order, string $merchantReferenceId, PaymentStatus $status): void
        {
            $currentStatus = $order->get_status();
            if ($currentStatus === 'completed') {
                return;
            }
            $order->update_status('pending');
            self::applyStatusChange($status, $merchantReferenceId);
            do_action('woocommerce_order_edit_status', $order->get_id(), 'pending');
        }

        /** @param bool|WC_Order|WC_Order_Refund $order */
        private static function processWebhookCanceled($order, string $merchantReferenceId): void
        {
            $order->update_status('cancelled');
            self::applyStatusChange(PaymentStatus::CANCELED_STATUS, $merchantReferenceId);
            $order->add_order_note(__('SmobilPay payment cancelled', Plugin::DOMAIN_TEXT), true);
            do_action('woocommerce_order_edit_status', $order->get_id(), 'cancelled');
        }

        /** @param bool|WC_Order|WC_Order_Refund $order */
        private static function processWebhookFailed($order, string $merchantReferenceId): void
        {
            $order->update_status('failed');
            self::applyStatusChange(PaymentStatus::FAILED_STATUS, $merchantReferenceId);
            $order->add_order_note(__('SmobilPay payment failed', Plugin::DOMAIN_TEXT), true);
            do_action('woocommerce_order_edit_status', $order->get_id(), 'failed');
        }

        private static function applyStatusChange(PaymentStatus $status, string $merchantReferenceId): void
        {
            $remoteIp = WC_Geolocation::get_ip_address();

            if ($remoteIp) {
                $remoteIp = sanitize_text_field($remoteIp);
            }

            $changed = self::$paymentRepository->updateStatusIfChanged(
                sanitize_text_field($merchantReferenceId),
                sanitize_text_field($status->value),
                $remoteIp
            );
            if (!$changed) {
                return;
            }

            /**
             * Executes the hook smobilpay_after_status_change where ever it's defined.
             *
             * Example usage:
             *
             *     // The action callback function.
             *     function example_callback( $id, $shopType ) {
             *         // (maybe) do something with the args.
             *     }
             *
             *     add_action( 'smobilpay_after_status_change', 'example_callback', 10, 2 );
             *
             *     /*
             *      * Trigger the actions by calling the 'example_callback()' function
             *      * that's hooked onto `smobilpay_after_status_change`.
             *
             *      * - $id is either the transaction ID or the merchant reference ID
             *      * - $shopType is the shop invoked actually the hook
             *
             * @since 1.0.3
             */
            do_action('smobilpay_after_status_change', sanitize_text_field($merchantReferenceId), 'wc');
        }
    }
}
