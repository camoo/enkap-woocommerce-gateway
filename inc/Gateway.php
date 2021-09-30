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
use WC_Order;
use WC_Order_Refund;
use WC_Payment_Gateway;
use WP_Error;

defined('ABSPATH') || exit;


class WC_Enkap_Gateway extends WC_Payment_Gateway
{
    private $_key;
    private $_secret;
    private $instructions;
    private $testmode;

    function __construct()
    {
        $this->id = "e_nkap";
        $this->icon = null;
        $this->has_fields = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->method_title = $this->get_option('method_title');
        $this->method_description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions');

        $this->_key = $this->get_option('enkap_key');
        $this->_secret = $this->get_option('enkap_secret');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_return_' . $this->id, array($this, 'onReturn'));
        add_action('woocommerce_api_notification_' . $this->id, array($this, 'onNotification'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', Plugin::DOMAIN_TEXT),
                'label' => __('Enable E-nkap Payment', Plugin::DOMAIN_TEXT),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                'default' => __('E-nkap Payment. Smobilpay for e-commerce', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                'default' => __('Pay with your mobile phone via E-nkap payment gateway.', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
            ),
            'instructions' => array(
                'title' => __('Instructions', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page.', 'woocommerce'),
                'default' => __('Secured Payment with Enkap. Smobilpay for e-commerce', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
            ),
            'testmode' => array(
                'title' => __('Test mode', Plugin::DOMAIN_TEXT),
                'label' => __('Enable Test Mode', Plugin::DOMAIN_TEXT),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', Plugin::DOMAIN_TEXT),
                'default' => 'yes',
                'desc_tip' => true,
            ),
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

        $this->_key = $this->get_option('enkap_key');
        $this->_secret = $this->get_option('enkap_secret');
        $setup = new CallbackUrlService($this->_key, $this->_secret, [], $this->testmode);
        /** @var CallbackUrl $callBack */
        $callBack = $setup->loadModel(CallbackUrl::class);
        $callBack->return_url = Plugin::get_webhook_url('return');
        $callBack->notification_url = Plugin::get_webhook_url('notification');
        $setup->set($callBack);
    }

    public function process_payment($order_id)
    {
        $wc_order = wc_get_order($order_id);

        $orderService = new OrderService($this->_key, $this->_secret, [], $this->testmode);

        $order = $orderService->loadModel(Order::class);

        $order_data = $wc_order->get_data();

        $merchantReferenceId = wp_generate_uuid4();
        $dataData = [
            'merchantReference' => $merchantReferenceId,
            'email' => $order_data['billing']['email'],
            'customerName' => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
            'totalAmount' => (float)$order_data['total'],
            'description' => __('Payment from', Plugin::DOMAIN_TEXT) . ' ' . get_bloginfo('name'),
            'currency' => $this->get_option('enkap_currency'),
            'langKey' => Plugin::getLanguageKey(),
            'items' => []
        ];

        foreach ($wc_order->get_items() as $item) {
            $product = $item->get_product();

            $dataData['items'][] = [
                'itemId' => $item->get_id(),
                'particulars' => $item->get_name(),
                'unitCost' => (float)$product->get_price(),
                'subTotal' => (float)$item->get_subtotal(),
                'quantity' => $item->get_quantity()
            ];
        }

        try {
            $order->fromStringArray($dataData);
            $response = $orderService->place($order);

            $wc_order->update_status('on-hold', __('Awaiting E-nkap payment confirmation', Plugin::DOMAIN_TEXT));

            // Empty cart
            WC()->cart->empty_cart();

            $this->logEnkapPayment($order_id, $merchantReferenceId, $response->getOrderTransactionId());
            $wc_order->add_order_note(__('Your order is under process! Thank you!', Plugin::DOMAIN_TEXT), true);
            return array(
                'result' => 'success',
                'redirect' => $response->getRedirectUrl()
            );

        } catch (Throwable $e) {
            wc_add_notice($e->getMessage(), 'error');
        }
        return null;
    }

    public function thankyou_page()
    {
        if ($this->instructions) {
            echo wpautop(wptexturize($this->instructions));
        }
    }

    public function email_instructions($order, $sent_to_admin, $plain_text = false)
    {
        if ($this->instructions && !$sent_to_admin && 'offline' === $order->payment_method && $order->has_status('on-hold')) {
            echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
        }
    }

    public function onReturn()
    {

        $merchantReferenceId = Helper::getOderMerchantIdFromUrl();

        $order_id = Plugin::getWcOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($order_id)) {
            Plugin::displayNotFoundPage();
            exit();
        }
        $status = filter_input(INPUT_GET, 'status');

        if ($status && ($order = wc_get_order($order_id))) {
            $this->processWebhook($order, sanitize_text_field($status));
        }
        $shop_page_url = get_permalink(wc_get_page_id('shop'));
        if (wp_redirect($shop_page_url)) {
            exit;
        }
    }

    public function onNotification()
    {

        $merchantReferenceId = Helper::getOderMerchantIdFromUrl();

        $orderId = Plugin::getWcOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($orderId)) {
            return new WP_error('invalid_request_id', 'Bad Request', ['status' => 400]);
        }

        $requestBody = file_get_contents('php://input');
        $bodyData = json_decode($requestBody, true);

        $status = $bodyData['status'];

        if (empty($status)) {
            return new WP_error('invalid_request_status', 'Bad Request', ['status' => 400]);
        }

        $order = wc_get_order($orderId);
        if ($order) {
            $this->processWebhook($order, sanitize_text_field($status));
        }
        return "Status Updated To " . $order->get_status();
    }

    public function processWebhook($order, $status)
    {
        switch ($status) {
            case Status::IN_PROGRESS_STATUS :
            case Status::CREATED_STATUS :
                $this->processWebhookProgress($order);
                break;
            case Status::CONFIRMED_STATUS :
                $this->processWebhookConfirmed($order);
                break;
            case Status::CANCELED_STATUS :
                $this->processWebhookCanceled($order);
                break;
            case Status::FAILED_STATUS :
                $this->processWebhookFailed($order);
                break;
            default :
        }

    }

    /**
     * @param bool|WC_Order|WC_Order_Refund $order
     */
    private function processWebhookConfirmed($order)
    {

        $order->payment_complete();
        wc_reduce_stock_levels($order->get_id());
    }

    /**
     * @param bool|WC_Order|WC_Order_Refund $order
     */
    private function processWebhookProgress($order)
    {
        $order->update_status('pending');
    }

    /**
     * @param bool|WC_Order|WC_Order_Refund $order
     */
    private function processWebhookCanceled($order)
    {
        $order->update_status('cancelled');
    }

    /**
     * @param bool|WC_Order|WC_Order_Refund $order
     */
    private function processWebhookFailed($order)
    {
        $order->update_status('failed');
    }

    protected function logEnkapPayment(int $orderId, string $merchantReferenceId, string $orderTransactionId)
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . "wc_enkap_payments",
            [
                'wc_order_id' => $orderId,
                'order_transaction_id' => $orderTransactionId,
                'merchant_reference_id' => $merchantReferenceId,
            ]
        );
    }

    public function get_icon()
    {
        $icon_url = 'https://enkap.cm/';
        $icon_html = '';
        $icon = WC_HTTPS::force_https_url(plugin_dir_url(__FILE__) . '/assets/images/e-nkap.png');
        $icon_html .= '<img src="' . esc_attr($icon) . '" alt="' . esc_attr__('E-nkap acceptance mark', Plugin::DOMAIN_TEXT) . '" />';

        $icon_html .= sprintf('<a href="%1$s" class="about_e_nkap" onclick="javascript:window.open(\'%1$s\',\'WIEnkap\',\'toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=1060, height=700\'); return false;">' . esc_attr__('What is E-nkap?', Plugin::DOMAIN_TEXT) . '</a>', esc_url($icon_url));

        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }
}
