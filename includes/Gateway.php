<?php

namespace Camoo\Enkap\WooCommerce;

use Enkap\OAuth\Lib\Helper;
use Enkap\OAuth\Model\CallbackUrl;
use Enkap\OAuth\Model\Order;
use Enkap\OAuth\Model\Status;
use Enkap\OAuth\Services\CallbackUrlService;
use Enkap\OAuth\Services\OrderService;
use Exception;
use Throwable;
use WC_HTTPS;
use WC_Order;
use WC_Payment_Gateway;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;

class WC_Enkap_Gateway extends WC_Payment_Gateway
{
    /** @var string */
    private $consumerKey;

    /** @var string */
    private $consumerSecret;

    private $instructions;

    /** @var bool */
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

        $this->consumerKey = sanitize_text_field($this->get_option('enkap_key') ?? '');
        $this->consumerSecret = sanitize_text_field($this->get_option('enkap_secret') ?? '');
        $this->registerHooks();

        $this->logger = new Logger\Logger($this->id, WP_DEBUG || $this->testMode);
    }

    public function init_form_fields()
    {
        $wc_enkap_settings = [
            'enabled' => [
                'title' => __('Enable/Disable', Plugin::DOMAIN_TEXT),
                'label' => __('Enable SmobilPay for e-commerce Payment', Plugin::DOMAIN_TEXT),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                'default' => __('SmobilPay for e-commerce Payment.', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.',
                    'woocommerce'),
                'default' => __('Pay with your mobile phone via SmobilPay for e-commerce payment gateway.',
                    Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
            ],
            'instructions' => [
                'title' => __('Instructions', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page.', 'woocommerce'),
                'default' => __('Secured Payment with Smobilpay for e-commerce', Plugin::DOMAIN_TEXT),
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
                'label' => __('SmobilPay Currency', Plugin::DOMAIN_TEXT),
                'type' => 'select',
                'description' => __('Define the currency to place your payments', Plugin::DOMAIN_TEXT),
                'default' => 'XAF',
                'options' => ['XAF' => __('CFA-Franc BEAC', Plugin::DOMAIN_TEXT)],
                'desc_tip' => true,
            ],
            'api_details' => [
                'title' => __('API credentials', 'woocommerce'),
                'type' => 'title',
                'description' => wp_kses(
                    sprintf(
                        __(
                            'Enter your SmobilPay for e-commerce API credentials to process Payments via SmobilPay for e-commerce. Learn how to access your <a href="%s" target="_blank" rel="noopener noreferrer">SmobilPay for e-commerce API Credentials</a>.',
                            Plugin::DOMAIN_TEXT
                        ),
                        'https://enkap.cm/faq/'
                    ),
                    [
                        'a' => [
                            'href' => true,
                            'target' => true,
                            'rel' => true,
                        ],
                    ]
                ),
            ],
            'enkap_key' => [
                'title' => __('Consumer Key', Plugin::DOMAIN_TEXT),
                'type' => 'text',
                'description' => __('Get your API Consumer Key from SmobilPay for e-commerce.', Plugin::DOMAIN_TEXT),
                'default' => '',
                'desc_tip' => true,
            ],
            'enkap_secret' => [
                'title' => __('Consumer Secret', Plugin::DOMAIN_TEXT),
                'type' => 'password',
                'description' => __('Get your API Consumer Secret from SmobilPay for e-commerce.', Plugin::DOMAIN_TEXT),
                'default' => '',
                'desc_tip' => true,
            ],
        ];
        $this->form_fields = apply_filters('wc_enkap_settings', $wc_enkap_settings);
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

        $this->consumerKey = sanitize_text_field($this->get_option('enkap_key'));
        $this->consumerSecret = sanitize_text_field($this->get_option('enkap_secret'));
        $setup = new CallbackUrlService($this->consumerKey, $this->consumerSecret, [], $this->testMode);
        /** @var CallbackUrl $callBack */
        $callBack = $setup->loadModel(CallbackUrl::class);
        $callBack->return_url = Plugin::get_webhook_url('return');
        $callBack->notification_url = Plugin::get_webhook_url('notification');
        if ($setup->set($callBack)) {
            $this->logger->info(
                __FILE__,
                __LINE__,
                __('Return and Notification Urls setup successfully', Plugin::DOMAIN_TEXT)
            );
        } else {
            $this->logger->error(
                __FILE__,
                __LINE__,
                __('Return and Notification Urls could not be setup', Plugin::DOMAIN_TEXT)
            );
        }
    }

    public function process_payment($order_id)
    {
        try {
            $wcOrder = $this->getWcOrder($order_id);
            $orderService = $this->createOrderService();
            $merchantReferenceId = wp_generate_uuid4();
            $orderData = $this->prepareOrderData($wcOrder, $merchantReferenceId);
            $response = $this->placeOrder($orderService, $orderData);

            $this->handleOrderResponse($wcOrder, $merchantReferenceId, $response);

            return [
                'result' => 'success',
                'redirect' => $response->getRedirectUrl(),
            ];
        } catch (Throwable $exception) {
            $this->logger->error(__FILE__, __LINE__, $exception->getMessage());
            wc_add_notice($exception->getMessage(), 'error');

            return [];
        }
    }

    public function onReturn(): void
    {
        $merchantReferenceId = sanitize_text_field(Helper::getOderMerchantIdFromUrl());

        $orderId = Plugin::getWcOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($orderId)) {
            $this->logger->error(__FILE__, __LINE__, 'OnReturn:: Order Id not found');
            wp_redirect(get_permalink(wc_get_page_id('shop')));
            Helper::exitOrDie();
        }
        $status = filter_input(INPUT_GET, 'status');

        if ($status && ($order = wc_get_order($orderId))) {
            Plugin::processWebhookStatus($order, sanitize_text_field($status), $merchantReferenceId);
        }

        $shopPageUrl = isset($order) ? $order->get_checkout_order_received_url() :
            get_permalink(wc_get_page_id('shop'));

        if (wp_redirect($shopPageUrl)) {
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
                'message' => 'Bad Request',
            ], 400);
        }

        $requestBody = WP_REST_Server::get_raw_data();
        $bodyData = json_decode($requestBody, true);

        $status = $bodyData['status'];

        if (empty($status) || !in_array(sanitize_text_field($status), Status::getAllowedStatus())) {
            $this->logger->error(__FILE__, __LINE__, 'onNotification:: Invalide status ' . $status);

            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Bad Request',
            ], 400);
        }

        $order = wc_get_order($orderId);
        $oldStatus = '';
        if ($order) {
            $oldStatus = $order->get_status();
            Plugin::processWebhookStatus($order, sanitize_text_field($status), $merchantReferenceId);
        }

        $this->logger->info(
            __FILE__,
            __LINE__,
            'onNotification:: status ' . $status . ' updates successfully'
        );

        return new WP_REST_Response([
            'status' => 'OK',
            'message' => sprintf('Status Updated From %s To %s', $oldStatus, $order->get_status()),
        ], 200);
    }

    public function get_icon()
    {
        $icon_html = '';
        $icon = WC_HTTPS::force_https_url(plugin_dir_url(__FILE__) . 'assets/images/e-nkap.png');
        $icon_html .= '<img src="' . esc_attr($icon) . '" alt="' .
            esc_attr__('SmobilPay for e-commerce acceptance mark', Plugin::DOMAIN_TEXT) . '" />';

        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }

    protected function logEnkapPayment(int $orderId, string $merchantReferenceId, string $orderTransactionId): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'wc_enkap_payments',
            [
                'wc_order_id' => absint(wp_unslash($orderId)),
                'order_transaction_id' => sanitize_text_field($orderTransactionId),
                'merchant_reference_id' => sanitize_text_field($merchantReferenceId),
            ]
        );
    }

    private function registerHooks(): void
    {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    private function getWcOrder(int $orderId): WC_Order
    {
        return wc_get_order(absint(wp_unslash($orderId)));
    }

    private function createOrderService(): OrderService
    {
        return new OrderService($this->consumerKey, $this->consumerSecret, [], $this->testMode);
    }

    /**
     * @throws Exception
     *
     * @return Order|null
     */
    private function placeOrder(OrderService $orderService, array $orderData)
    {
        /** @var Order $order */
        $order = $orderService->loadModel(Order::class);
        $order->fromStringArray($orderData);

        return $orderService->place($order);
    }

    private function prepareOrderData(WC_Order $wcOrder, string $merchantReferenceId): array
    {
        $order_data = $wcOrder->get_data();
        $orderData = [
            'merchantReference' => $merchantReferenceId,
            'email' => $order_data['billing']['email'],
            'customerName' => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
            'totalAmount' => (float)$order_data['total'],
            'description' => __('Payment from', Plugin::DOMAIN_TEXT) . ' ' . get_bloginfo('name'),
            'currency' => sanitize_text_field($this->get_option('enkap_currency')),
            'langKey' => Plugin::getLanguageKey(),
            'items' => [],
        ];

        foreach ($wcOrder->get_items() as $item) {
            $product = $item->get_product();
            $orderData['items'][] = [
                'itemId' => $item->get_id(),
                'particulars' => $item->get_name(),
                'unitCost' => (float)$product->get_price(),
                'subTotal' => (float)$item->get_subtotal(),
                'quantity' => $item->get_quantity(),
            ];
        }

        return $orderData;
    }

    private function handleOrderResponse(WC_Order $wcOrder, string $merchantReferenceId, ?Order $response = null): void
    {
        if (null === $response) {
            return;
        }
        $wcOrder->update_status('on-hold', __('Awaiting SmobilPay payment confirmation', 'wc-wp-enkap'));
        WC()->cart->empty_cart();
        $this->logEnkapPayment(
            $wcOrder->get_id(),
            sanitize_text_field($merchantReferenceId),
            sanitize_text_field($response->getOrderTransactionId())
        );

        $wcOrder->add_order_note(
            __('Your order is under process. Thank you!', Plugin::DOMAIN_TEXT),
            true
        );
    }
}
