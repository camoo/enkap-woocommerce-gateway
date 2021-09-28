<?php

namespace Camoo\Enkap\WooCommerce;

use Enkap\OAuth\Model\Order;
use Enkap\OAuth\Model\Status;
use Enkap\OAuth\Services\OrderService;
use Enkap\OAuth\Services\CallbackUrlService;
use Enkap\OAuth\Model\CallbackUrl;
use Throwable;
use WC_Payment_Gateway;

defined('ABSPATH') || exit;


class WC_Enkap_Gateway extends WC_Payment_Gateway
{
    private $_key;
    private $_secret;
    private $instructions;
    private bool $testmode;

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
                'title' => 'Enable/Disable',
                'label' => 'Enable E-nkap Payment',
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default' => 'E-nkap Payment. Smobilpay for e-commerce',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default' => 'Pay with your mobile phone via E-nkap payment gateway.',
            ),
            'testmode' => array(
                'title' => 'Test mode',
                'label' => 'Enable Test Mode',
                'type' => 'checkbox',
                'description' => 'Place the payment gateway in test mode using test API keys.',
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'enkap_key' => array(
                'title' => 'Key',
                'type' => 'text'
            ),
            'enkap_secret' => array(
                'title' => 'Secret',
                'type' => 'password'
            )
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
        $setup = new CallbackUrlService($this->_key, $this->_secret);
        /** @var CallbackUrl $callBack */
        $callBack = $setup->loadModel(CallbackUrl::class);
        $callBack->return_url = Plugin::get_webhook_url('return_' . $this->id);
        $callBack->notification_url = Plugin::get_webhook_url('notification_' . $this->id);
        $setup->set($callBack);
    }

    public function process_payment($order_id)
    {
        $wc_order = wc_get_order($order_id);

        $orderService = new OrderService($this->_key, $this->_secret);

        $order = $orderService->loadModel(Order::class);

        $order_data = $wc_order->get_data();

        $merchantReferenceId = wp_generate_uuid4();
        $dataData = [
            'merchantReference' => $merchantReferenceId,
            'email' => $order_data['billing']['email'],
            'customerName' => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
            'totalAmount' => (float)$order_data['total'],
            'description' => 'Payment from ' . get_bloginfo('name'),
            'currency' => 'XAF',
            'items' => []
        ];
        foreach ($wc_order->get_items() as $item) {
            $product = $item->get_product();

            $dataData['items'][] = [
                'itemId' => $item->get_id(),
                'particulars' => $item->get_name(),
                'unitCost' => (float)$product->get_price(),
                'quantity' => $item->get_quantity()
            ];
        }

        try {
            $order->fromStringArray($dataData);
            $response = $orderService->place($order);

            $wc_order->update_status('on-hold', __('Awaiting E-Nkap payment confirmation', $this->id));

            // Empty cart
            WC()->cart->empty_cart();

            $this->logEnkapPayment($order_id, $merchantReferenceId, $response->getOrderTransactionId());
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
        if (('GET' !== $_SERVER['REQUEST_METHOD'])
            || !isset($_GET['wc-api'])
            || ('return_e_nkap' !== $_GET['wc-api'] && 'notification_e_nkap' !== $_GET['wc-api'])
        ) {
            return;
        }
        $merchantReferenceId = filter_input(INPUT_GET, 'merchantReferenceId');
        $order_id = Plugin::getWcOrderIdByMerchantReferenceId($merchantReferenceId);
        $status = filter_input(INPUT_GET, 'status');
        if ($status && wc_get_order($order_id)) {
            $this->processWebhook($order_id, $status);
        }
    }

    public function onNotification()
    {

    }

    public function processWebhook($order_id, $status)
    {
        switch ($status) {
            case Status::IN_PROGRESS_STATUS :
            case Status::CREATED_STATUS :
                $this->processWebhookProgress($order_id);
                break;
            case Status::CONFIRMED_STATUS :
                $this->processWebhookConfirmed($order_id);
                break;
            case Status::CANCELED_STATUS :
                $this->processWebhookCanceled($order_id);
                break;
            case Status::FAILED_STATUS :
                $this->processWebhookFailed($order_id);
                break;
            default :
        }

    }

    private function processWebhookConfirmed($order_id)
    {
        $order = wc_get_order($order_id);
        $order->payment_complete();
        wc_reduce_stock_levels($order->get_id());
    }

    private function processWebhookProgress($order_id)
    {
        wc_get_order($order_id);

    }

    private function processWebhookCanceled($order_id)
    {
        wc_get_order($order_id);

    }

    private function processWebhookFailed($order_id)
    {
        wc_get_order($order_id);
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
}
