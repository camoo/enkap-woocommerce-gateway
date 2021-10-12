<?php

namespace Camoo\Enkap\WooCommerce;

use Enkap\OAuth\Lib\Helper;
use Enkap\OAuth\Model\Order;
use Enkap\OAuth\Model\Status;
use Enkap\OAuth\Services\OrderService;
use Enkap\OAuth\Services\CallbackUrlService;
use Enkap\OAuth\Model\CallbackUrl;
use Throwable;
use WC_HTTPS;
use WC_Payment_Gateway;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;


class WC_Enkap_Gateway extends WC_Payment_Gateway
{
    private $_key;
    private $_secret;
    private $instructions;
    private $testMode;
    /** @var Logger\Logger $logger */
    private $logger;

    public function __construct()
    {
        $this->id = Plugin::WC_ENKAP_GATEWAY_ID;
        $this->icon = null;
        $this->has_fields = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->title = esc_html($this->get_option('title'));
        $this->method_title = esc_html($this->get_option('method_title'));
        $this->method_description = esc_html($this->get_option('description'));
        $this->enabled = sanitize_text_field($this->get_option('enabled'));
        $this->testMode = 'yes' === sanitize_text_field($this->get_option('test_mode'));
        $this->description = esc_html($this->get_option('description'));
        $this->instructions = esc_html($this->get_option('instructions'));

        $this->_key = sanitize_text_field($this->get_option('enkap_key'));
        $this->_secret = sanitize_text_field($this->get_option('enkap_secret'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        $this->logger = new Logger\Logger($this->id, WP_DEBUG || $this->testMode);
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => [
                'title' => __('Enable/Disable', Plugin::DOMAIN_TEXT),
                'label' => __('Enable E-nkap Payment', Plugin::DOMAIN_TEXT),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ],
            'title' => [
                'title' => __('Title', 'woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                'default' => __('E-nkap Payment. Smobilpay for e-commerce', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                'default' => __('Pay with your mobile phone via E-nkap payment gateway.', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
            ],
            'instructions' => [
                'title' => __('Instructions', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page.', 'woocommerce'),
                'default' => __('Secured Payment with Enkap. Smobilpay for e-commerce', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
            ],
            'test_mode' => [
                'title' => __('Test mode', Plugin::DOMAIN_TEXT),
                'label' => __('Enable Test Mode', Plugin::DOMAIN_TEXT),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', Plugin::DOMAIN_TEXT),
                'default' => 'no',
                'desc_tip' => true,
            ],
            'enkap_currency' => [
                'title' => __('Currency', 'woocommerce'),
                'label' => __('E-nkap Currency', Plugin::DOMAIN_TEXT),
                'type' => 'select',
                'description' => __('Define the currency to place your payments', Plugin::DOMAIN_TEXT),
                'default' => 'XAF',
                'options' => ['XAF' => __('CFA-Franc BEAC', Plugin::DOMAIN_TEXT)],
                'desc_tip' => true,
            ],
            'api_details' => [
                'title' => __('API credentials', 'woocommerce'),
                'type' => 'title',
                'description' => sprintf(__('Enter your E-nkap API credentials to process Payments via E-nkap. Learn how to access your <a href="%s">E-nkap API Credentials</a>.', Plugin::DOMAIN_TEXT), 'https://enkap.cm/faq/'),
            ],
            'enkap_key' => [
                'title' => __('Consumer Key', Plugin::DOMAIN_TEXT),
                'type' => 'text',
                'description' => __('Get your API Consumer Key from E-nkap.', Plugin::DOMAIN_TEXT),
                'default' => '',
                'desc_tip' => true,
            ],
            'enkap_secret' => [
                'title' => __('Consumer Secret', Plugin::DOMAIN_TEXT),
                'type' => 'password',
                'description' => __('Get your API Consumer Secret from E-nkap.', Plugin::DOMAIN_TEXT),
                'default' => '',
                'desc_tip' => true,
            ],
        );
    }

    public function validate_fields()
    {
        if (empty($_POST['billing_first_name'])) {
            wc_add_notice('First name is required!', 'error');
            return false;
        }
        return true;
    }

    public function process_admin_options()
    {
        parent::process_admin_options();

        $this->_key = sanitize_text_field($this->get_option('enkap_key'));
        $this->_secret = sanitize_text_field($this->get_option('enkap_secret'));
        $setup = new CallbackUrlService($this->_key, $this->_secret, [], $this->testMode);
        /** @var CallbackUrl $callBack */
        $callBack = $setup->loadModel(CallbackUrl::class);
        $callBack->return_url = Plugin::get_webhook_url('return');
        $callBack->notification_url = Plugin::get_webhook_url('notification');
        if ($setup->set($callBack)) {
            $this->logger->info(
                __FILE__,
                __LINE__,
                __('Return and Notification Urls setup successfully', Plugin::DOMAIN_TEXT));
        } else {
            $this->logger->error(
                __FILE__,
                __LINE__,
                __('Return and Notification Urls could not be setup', Plugin::DOMAIN_TEXT));
        }
    }

    public function process_payment($order_id)
    {
        $wc_order = wc_get_order(absint(wp_unslash($order_id)));

        $orderService = new OrderService($this->_key, $this->_secret, [], $this->testMode);

        $order = $orderService->loadModel(Order::class);

        $order_data = $wc_order->get_data();

        $merchantReferenceId = wp_generate_uuid4();
        $orderData = [
            'merchantReference' => $merchantReferenceId,
            'email' => $order_data['billing']['email'],
            'customerName' => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
            'totalAmount' => (float)$order_data['total'],
            'description' => __('Payment from', Plugin::DOMAIN_TEXT) . ' ' . get_bloginfo('name'),
            'currency' => sanitize_text_field($this->get_option('enkap_currency')),
            'langKey' => Plugin::getLanguageKey(),
            'items' => []
        ];

        foreach ($wc_order->get_items() as $item) {
            $product = $item->get_product();

            $orderData['items'][] = [
                'itemId' => $item->get_id(),
                'particulars' => $item->get_name(),
                'unitCost' => (float)$product->get_price(),
                'subTotal' => (float)$item->get_subtotal(),
                'quantity' => $item->get_quantity()
            ];
        }

        try {
            $order->fromStringArray($orderData);
            $response = $orderService->place($order);

            $wc_order->update_status('on-hold',
                __('Awaiting E-nkap payment confirmation', Plugin::DOMAIN_TEXT));

            // Empty cart
            WC()->cart->empty_cart();

            $this->logEnkapPayment(
                $order_id,
                $merchantReferenceId,
                sanitize_text_field($response->getOrderTransactionId())
            );
            $wc_order->add_order_note(
                __('Your order is under process. Thank you!', Plugin::DOMAIN_TEXT),
                true);
            return array(
                'result' => 'success',
                'redirect' => $response->getRedirectUrl()
            );
        } catch (Throwable $exception) {
            $this->logger->error(__FILE__, __LINE__, $exception->getMessage());
            wc_add_notice($exception->getMessage(), 'error');
        }
        return null;
    }

    public function onReturn()
    {
        $merchantReferenceId = sanitize_text_field(Helper::getOderMerchantIdFromUrl());

        $order_id = Plugin::getWcOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($order_id)) {
            $this->logger->error(__FILE__, __LINE__, 'OnReturn:: Order Id not found');
            wp_redirect(get_permalink(wc_get_page_id('shop')));
            Helper::exitOrDie();
        }
        $status = filter_input(INPUT_GET, 'status');

        if ($status && ($order = wc_get_order($order_id))) {
            Plugin::processWebhookStatus($order, sanitize_text_field($status), $merchantReferenceId);
        }

        $shop_page_url = isset($order) ? $order->get_checkout_order_received_url() :
            get_permalink(wc_get_page_id('shop'));

        if (wp_redirect($shop_page_url)) {
            Helper::exitOrDie();
        }
    }

    public function onNotification(): WP_REST_Response
    {
        $merchantReferenceId = sanitize_text_field(Helper::getOderMerchantIdFromUrl());

        $orderId = Plugin::getWcOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($orderId)) {
            $this->logger->error(__FILE__, __LINE__, 'onNotification:: Order Id not found');
            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Bad Request'
            ], 400);
        }

        $requestBody = WP_REST_Server::get_raw_data();
        $bodyData = json_decode($requestBody, true);

        $status = $bodyData['status'];

        if (empty($status) || !in_array(sanitize_text_field($status), Status::getAllowedStatus())) {
            $this->logger->error(__FILE__, __LINE__, 'onNotification:: Invalide status ' . $status);
            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Bad Request'
            ], 400);
        }

        $order = wc_get_order($orderId);
        $oldStatus = '';
        if ($order) {
            $oldStatus = $order->get_status();
            Plugin::processWebhookStatus($order, sanitize_text_field($status), $merchantReferenceId);
        }

        $this->logger->info(__FILE__, __LINE__,
            'onNotification:: status ' . $status . ' updates successfully');
        return new WP_REST_Response([
            'status' => 'OK',
            'message' => sprintf('Status Updated From %s To %s', $oldStatus, $order->get_status())
        ], 200);
    }

    protected function logEnkapPayment(int $orderId, string $merchantReferenceId, string $orderTransactionId)
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . "wc_enkap_payments",
            [
                'wc_order_id' => absint(wp_unslash($orderId)),
                'order_transaction_id' => sanitize_text_field($orderTransactionId),
                'merchant_reference_id' => sanitize_text_field($merchantReferenceId),
            ]
        );
    }

    public function get_icon()
    {
        $icon_html = '';
        $icon = WC_HTTPS::force_https_url(plugin_dir_url(__FILE__) . 'assets/images/e-nkap.png');
        $icon_html .= '<img src="' . esc_attr($icon) . '" alt="' .
            esc_attr__('E-nkap acceptance mark', Plugin::DOMAIN_TEXT) . '" />';
        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }
}
