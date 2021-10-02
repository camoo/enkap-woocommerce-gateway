<?php

/**
 * Description of PluginAdmin
 *
 * @author Camoo
 */

namespace Camoo\Enkap\WooCommerce\Admin;

use Camoo\Enkap\WooCommerce\Plugin;
use Enkap\OAuth\Services\StatusService;
use WC_Order;
use WC_Order_Refund;

defined('ABSPATH') || exit;

if (!class_exists('Camoo\Enkap\WooCommerce\Admin\PluginAdmin')):

    class PluginAdmin
    {
        protected static $instance = null;
        protected $mainMenuId;
        protected $author;
        protected $isRegistered;


        public static function instance()
        {
            if (!isset(self::$instance)) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function __construct()
        {
            $this->mainMenuId = 'wc-e-nkap';
            $this->author = 'wordpress@camoo.sarl';

            $this->isRegistered = false;
        }

        public function register()
        {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');

            if ($this->isRegistered) {
                return;
            }

            $this->isRegistered = true;

            //add_action('admin_menu', array($this, 'onAdminMenu'), 1);
            add_filter('manage_edit-shop_order_columns', [__CLASS__, 'extend_order_view'], 10);
            add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'get_extended_order_value'], 2);
            add_filter('woocommerce_admin_order_actions', [__CLASS__, 'add_custom_order_status_actions_button'], 100, 2);
            add_action('wp_ajax_e_nkap_mark_order_status', [__CLASS__, 'checkRemotePaymentStatus']);
        }

        public static function checkRemotePaymentStatus()
        {
            if (current_user_can('edit_shop_orders') &&
                check_admin_referer('woocommerce-mark-order-status') &&
                isset($_GET['status'], $_GET['order_id'])) {
                $status = sanitize_text_field(wp_unslash($_GET['status']));
                /** @var bool|WC_Order|WC_Order_Refund $order */
                $order = wc_get_order(absint(wp_unslash($_GET['order_id'])));

                if ($status === 'check' && !empty($order) && $order->has_status(['pending', 'on-hold', 'processing'])) {
                    WC()->payment_gateways();
                    $settings = get_option('woocommerce_' . Plugin::WC_ENKAP_GATEWAY_ID . '_settings');
                    $consumerKey = $settings['enkap_key'];
                    $consumerSecret = $settings['enkap_secret'];
                    $statusService = new StatusService($consumerKey, $consumerSecret);

                    $paymentData = self::getPaymentByWcOrderId($order->get_id());
                    if ($paymentData) {
                        $status = $statusService->getByTransactionId($paymentData->order_transaction_id);

                        Plugin::processWebhookStatus($order, $status->getCurrent(), $paymentData->merchant_reference_id);
                    }
                }
            }

            wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('edit.php?post_type=shop_order'));
            exit();
        }

        public static function add_custom_order_status_actions_button($actions, $order)
        {
            // Display the button for all orders that have a 'processing' status
            if ($order->has_status(['pending', 'on-hold', 'processing'])) {

                // Get Order ID (compatibility all WC versions)
                $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
                // Set the action button
                $actions['check'] = array(
                    'url' => wp_nonce_url(admin_url('admin-ajax.php?action=e_nkap_mark_order_status&status=check&order_id=' . $order_id), 'woocommerce-mark-order-status'),
                    'name' => __('Check Status', Plugin::DOMAIN_TEXT),
                    'title' => __('Check remote order status', Plugin::DOMAIN_TEXT),
                    'action' => 'processing',
                );
            }
            return $actions;
        }

        public function onAdminMenu()
        {
            add_menu_page(
                'E-nkap',
                'E-nkap',
                'manage_options',
                $this->mainMenuId,
                array(&$this, 'display'),
                '',
                #plugins_url('inc/assets/images/multi-shipping.png', dirname(__FILE__, 2)),
                26
            );

            add_submenu_page($this->mainMenuId, 'About', 'About', 'manage_options', $this->mainMenuId);
        }

        public function display()
        {
            echo 'Bonjour';
        }

        public static function extend_order_view($columns): array
        {
            $new_columns = (is_array($columns)) ? $columns : array();
            unset($new_columns['wc_actions']);

            $new_columns['merchant_reference_id'] = __('E-nkap-Reference-ID', Plugin::DOMAIN_TEXT);
            $new_columns['order_transaction_id'] = __('E-nkap-Transaction-ID', Plugin::DOMAIN_TEXT);

            $new_columns['wc_actions'] = $columns['wc_actions'];
            return $new_columns;
        }

        public static function get_extended_order_value($column)
        {
            global $post;
            $orderId = $post->ID;

            if ($column == 'merchant_reference_id') {
                $paymentData = self::getPaymentByWcOrderId($orderId);
                echo esc_html($paymentData->merchant_reference_id ?? '');
            }

            if ($column == 'order_transaction_id') {
                $paymentData = self::getPaymentByWcOrderId($orderId);

                echo esc_html($paymentData->order_transaction_id ?? '');
            }
        }

        protected static function getPaymentByWcOrderId(int $wcOrderId)
        {
            global $wpdb;

            $db_prepare = $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_enkap_payments` WHERE `wc_order_id` = %s", $wcOrderId);
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return $payment;
        }
    }

endif;
