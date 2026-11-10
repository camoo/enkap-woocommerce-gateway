<?php

namespace Camoo\Enkap\WooCommerce;

use Camoo\Enkap\WooCommerce\Repository\EnkapPaymentRepository;
use Enkap\OAuth\Enum\PaymentStatus;
use Enkap\OAuth\Lib\Helper;
use Enkap\OAuth\Model\CallbackUrl;
use Enkap\OAuth\Model\Order;
use Enkap\OAuth\Services\CallbackUrlService;
use Enkap\OAuth\Services\OrderService;
use Enkap\OAuth\Services\PaymentService;
use Enkap\OAuth\Services\StatusService;
use Exception;
use Throwable;
use WC_HTTPS;
use WC_Order;
use WC_Payment_Gateway;
use WP_REST_Response;

defined('ABSPATH') || exit;

class WC_Enkap_Gateway extends WC_Payment_Gateway
{
    private string $consumerKey;

    private string $consumerSecret;

    private string $instructions;

    private bool $testMode;

    private Logger\Logger $logger;

    private EnkapPaymentRepository $paymentRepository;

    public function __construct()
    {
        $this->id = Plugin::WC_ENKAP_GATEWAY_ID;
        $this->icon = null;
        $this->has_fields = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->title = esc_html($this->get_option('title'));
        $methodTitle = esc_html($this->get_option('method_title'));
        $this->method_title = $methodTitle ?: 'SmobilPay for e-Commerce';
        $this->method_description = esc_html($this->get_option('description'));
        $this->enabled = sanitize_text_field($this->get_option('enabled'));
        $this->testMode = 'yes' === sanitize_text_field($this->get_option('test_mode'));
        $this->description = esc_html($this->get_option('description'));
        $this->instructions = esc_html($this->get_option('instructions'));

        $this->consumerKey = sanitize_text_field($this->get_option('enkap_key') ?? '');
        $this->consumerSecret = sanitize_text_field($this->get_option('enkap_secret') ?? '');
        $this->registerHooks();

        $this->logger = new Logger\Logger($this->id, WP_DEBUG || $this->testMode);
        global $wpdb;

        $this->paymentRepository = new EnkapPaymentRepository($wpdb);
    }

    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }

        $gatewayCurrency = strtoupper((string)$this->get_option('enkap_currency', 'XAF'));

        return $gatewayCurrency !== '' && strtoupper(get_woocommerce_currency()) === $gatewayCurrency;
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
                'description' => __(
                    'This controls the description which the user sees during checkout.',
                    'woocommerce'
                ),
                'default' => __(
                    'Pay with your mobile phone via SmobilPay for e-commerce payment gateway.',
                    Plugin::DOMAIN_TEXT
                ),
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

    public function process_admin_options()
    {
        parent::process_admin_options();

        $this->consumerKey = sanitize_text_field($this->get_option('enkap_key'));
        $this->consumerSecret = sanitize_text_field($this->get_option('enkap_secret'));
        $setup = new CallbackUrlService($this->consumerKey, $this->consumerSecret, $this->testMode);
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

            if (
                empty($wcOrder->get_billing_first_name()) ||
                empty($wcOrder->get_billing_last_name())
            ) {
                wc_add_notice('First name is required!', 'error');

                return [];
            }

            $orderService = $this->createOrderService();
            $merchantReferenceId = wp_generate_uuid4();
            $orderData = $this->prepareOrderData($wcOrder, $merchantReferenceId);
            $response = $this->placeOrder($orderService, $orderData);

            if (!$response instanceof Order) {
                $this->logger->error(__FILE__, __LINE__, 'Payment initialization returned no order.');
                wc_add_notice(
                    __('Unable to initialize payment with SmobilPay. Please try again.', Plugin::DOMAIN_TEXT),
                    'error'
                );

                return [];
            }

            $redirectUrl = $response->getRedirectUrl();
            if (!is_string($redirectUrl) || $redirectUrl === '') {
                $this->logger->error(__FILE__, __LINE__, 'Payment initialization returned no redirect URL.');
                wc_add_notice(
                    __('Unable to initialize payment with SmobilPay. Please try again.', Plugin::DOMAIN_TEXT),
                    'error'
                );

                return [];
            }

            $this->handleOrderResponse($wcOrder, $merchantReferenceId, $response);

            return [
                'result' => 'success',
                'redirect' => $redirectUrl,
            ];
        } catch (Throwable $exception) {
            $this->logger->error(__FILE__, __LINE__, $exception->getMessage());
            wc_add_notice(
                __('Unable to initialize payment with SmobilPay. Please try again.', Plugin::DOMAIN_TEXT),
                'error'
            );

            return [];
        }
    }

    public function onReturn(): void
    {
        $merchantReferenceId = sanitize_text_field(Helper::getOderMerchantIdFromUrl());

        $orderId = Plugin::getWcOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($orderId)) {
            $this->logger->error(__FILE__, __LINE__, 'OnReturn:: Order Id not found');
            wp_safe_redirect(get_permalink(wc_get_page_id('shop')));
            Helper::exitOrDie();
        }

        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order && !$order->has_status($this->getTerminalOrderStatuses())) {
            try {
                // The return URL is public and its query string is user-controlled. Never use its
                // status value to update the order; verify the transaction with E-nkap instead.
                $paymentStatus = $this->getVerifiedPaymentStatus($order, $merchantReferenceId);
                if ($paymentStatus !== null) {
                    Plugin::processWebhookStatus($order, $paymentStatus, $merchantReferenceId);
                }
            } catch (Throwable $exception) {
                $this->logger->error(__FILE__, __LINE__, 'OnReturn:: Payment verification failed');
            }
        }

        $shopPageUrl = $order instanceof WC_Order ? $order->get_checkout_order_received_url() :
            get_permalink(wc_get_page_id('shop'));

        if (wp_safe_redirect($shopPageUrl)) {
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

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            $this->logger->error(__FILE__, __LINE__, 'onNotification:: WooCommerce order not found');

            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Bad Request',
            ], 400);
        }

        if ($order->has_status($this->getTerminalOrderStatuses())) {
            $this->logger->info(__FILE__, __LINE__, 'onNotification:: Terminal order already processed');

            return new WP_REST_Response([
                'status' => 'OK',
                'message' => 'Order is already in a terminal state',
            ], 200);
        }

        try {
            // E-nkap's callback body is not authenticated by the SDK. The status in that body
            // must therefore be treated as untrusted and is deliberately not read here.
            $paymentStatus = $this->getVerifiedPaymentStatus($order, $merchantReferenceId);
        } catch (Throwable $exception) {
            $this->logger->error(__FILE__, __LINE__, 'onNotification:: Payment verification failed');

            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Payment verification failed',
            ], 503);
        }

        if ($paymentStatus === null) {
            $this->logger->error(__FILE__, __LINE__, 'onNotification:: Payment verification rejected');

            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Payment verification failed',
            ], 400);
        }

        $oldStatus = $order->get_status();
        Plugin::processWebhookStatus($order, $paymentStatus, $merchantReferenceId);

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
        return new OrderService($this->consumerKey, $this->consumerSecret, $this->testMode);
    }

    /**
     * Verify a callback using E-nkap's authenticated API instead of request-controlled data.
     *
     * The current E-nkap SDK does not expose a signed-webhook verifier. The transaction id is
     * read from the payment row created during checkout, then both the remote status and the
     * remote order details are fetched over the authenticated API. The remote order must match
     * the WooCommerce order before any status transition is allowed.
     */
    private function getVerifiedPaymentStatus(WC_Order $order, string $merchantReferenceId): ?PaymentStatus
    {
        $paymentData = $this->paymentRepository->getPaymentByMerchantReferenceId($merchantReferenceId);
        $transactionId = sanitize_text_field($paymentData->order_transaction_id ?? '');

        if (
            $transactionId === '' ||
            (int)($paymentData->wc_order_id ?? 0) !== $order->get_id()
        ) {
            $this->logger->error(__FILE__, __LINE__, 'Payment verification failed: transaction not found');

            return null;
        }

        $statusService = new StatusService($this->consumerKey, $this->consumerSecret, $this->testMode);
        $status = $statusService->getByTransactionId($transactionId);
        if (!is_object($status) || !method_exists($status, 'getCurrent')) {
            $this->logger->error(__FILE__, __LINE__, 'Payment verification failed: invalid remote status');

            return null;
        }

        $paymentService = new PaymentService($this->consumerKey, $this->consumerSecret, $this->testMode);
        $payment = $paymentService->getByTransactionId($transactionId);
        if (!is_object($payment) || !method_exists($payment, 'getOrder')) {
            $this->logger->error(__FILE__, __LINE__, 'Payment verification failed: invalid remote payment');

            return null;
        }
        $remoteOrder = $payment->getOrder();

        if ($remoteOrder === null) {
            $this->logger->error(__FILE__, __LINE__, 'Payment verification failed: remote order not found');

            return null;
        }

        $remoteOrderData = $remoteOrder->toStringArray();
        $remoteTransactionId = sanitize_text_field($remoteOrderData['orderTransactionId'] ?? '');
        $remoteMerchantReferenceId = sanitize_text_field(
            $remoteOrderData['merchantReferenceId'] ?? $remoteOrderData['merchantReference'] ?? ''
        );
        $remoteCurrency = strtoupper(sanitize_text_field($remoteOrderData['currency'] ?? ''));
        $remoteAmount = $remoteOrderData['totalAmount'] ?? null;

        if (
            $remoteTransactionId === '' ||
            !hash_equals($transactionId, $remoteTransactionId) ||
            $remoteMerchantReferenceId === '' ||
            !hash_equals($merchantReferenceId, $remoteMerchantReferenceId) ||
            $remoteCurrency === '' ||
            $remoteCurrency !== strtoupper($order->get_currency()) ||
            $remoteAmount === null ||
            !$this->amountsMatch($order->get_total(), $remoteAmount)
        ) {
            $this->logger->error(__FILE__, __LINE__, 'Payment verification failed: order details mismatch');

            return null;
        }

        $paymentStatus = $status->getCurrent();

        return $paymentStatus === PaymentStatus::UNKNOWN_STATUS ? null : $paymentStatus;
    }

    private function amountsMatch(string $localAmount, mixed $remoteAmount): bool
    {
        if (!is_scalar($remoteAmount) || !is_numeric((string)$remoteAmount)) {
            return false;
        }

        $precision = wc_get_price_decimals();

        return wc_format_decimal($localAmount, $precision) ===
            wc_format_decimal((string)$remoteAmount, $precision);
    }

    /** @return string[] */
    private function getTerminalOrderStatuses(): array
    {
        return ['completed', 'cancelled', 'failed', 'refunded'];
    }

    /** @throws Exception */
    private function placeOrder(OrderService $orderService, array $orderData): ?Order
    {
        /** @var Order $order */
        $order = $orderService->loadModel(Order::class);
        $order->fromStringArray($orderData);

        return $orderService->place($order);
    }

    /** @return array<string, mixed> */
    private function prepareOrderData(WC_Order $wcOrder, string $merchantReferenceId): array
    {
        $order_data = $wcOrder->get_data();
        $orderData = [
            'merchantReference' => $merchantReferenceId,
            'email' => sanitize_email($order_data['billing']['email']),
            'customerName' => sanitize_text_field(
                $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name']
            ),
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

        // Persist the transaction mapping before changing the order/cart state. If the
        // database write fails, checkout can fail without leaving local payment state half-applied.
        $this->paymentRepository->insert(
            $wcOrder->get_id(),
            sanitize_text_field($merchantReferenceId),
            sanitize_text_field($response->getOrderTransactionId())
        );

        $wcOrder->update_status('on-hold', __('Awaiting SmobilPay payment confirmation', 'wc-wp-enkap'));
        $woocommerce = WC();
        if ($woocommerce && $woocommerce->cart) {
            $woocommerce->cart->empty_cart();
        }

        $wcOrder->add_order_note(
            __('Your order is under process. Thank you!', Plugin::DOMAIN_TEXT),
            true
        );
    }
}
