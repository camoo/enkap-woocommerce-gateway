<?php

declare(strict_types=1);

/**
 * Dependency-free regression tests for the payment flow fixes.
 *
 * Run with: php tests/run.php
 */

namespace {
    define('ABSPATH', __DIR__ . '/');

    $GLOBALS['test_events'] = [];
    $GLOBALS['test_woocommerce'] = null;
    $GLOBALS['test_currency'] = 'XAF';
    $GLOBALS['test_order'] = null;

    function test_event(string $event): void
    {
        $GLOBALS['test_events'][] = $event;
    }

    function reset_test_events(): void
    {
        $GLOBALS['test_events'] = [];
    }

    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                "%s\nExpected: %s\nActual: %s",
                $message,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    interface WC_Logger_Interface
    {
        public function log($level, $message, $context = []);
    }

    final class TestLogger implements WC_Logger_Interface
    {
        public function log($level, $message, $context = []): void
        {
        }
    }

    class wpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public string $last_query = '';
        public int|false $queryResult = 1;
        public int|false $insertResult = 1;
        public mixed $getVarResult = null;
        public int $queryCount = 0;

        public function prepare(string $query, mixed ...$args): string
        {
            foreach ($args as $arg) {
                $replacement = is_int($arg) || is_float($arg)
                    ? (string)$arg
                    : "'" . addslashes((string)$arg) . "'";
                $query = preg_replace('/%[sdf]/', $replacement, $query, 1);
            }

            return $query;
        }

        public function query(string $query): int|false
        {
            $this->last_query = $query;
            $this->queryCount++;
            test_event('db_query');

            return $this->queryResult;
        }

        public function insert(string $table, array $data, array $formats): int|false
        {
            $this->last_query = 'INSERT INTO ' . $table;
            test_event('db_insert');

            return $this->insertResult;
        }

        public function get_var(string $query): mixed
        {
            return $this->getVarResult;
        }
    }

    class WC_Order
    {
        public bool $throwOnPaymentComplete = false;

        public function __construct(private int $id = 42, private string $status = 'on-hold')
        {
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function has_status($statuses): bool
        {
            return in_array($this->status, (array)$statuses, true);
        }

        public function update_status(string $status, string $note = ''): void
        {
            test_event('order_status:' . $status);
            $this->status = $status;
        }

        public function payment_complete(?string $transactionId = null): void
        {
            test_event('payment_complete');
            if ($this->throwOnPaymentComplete) {
                throw new \RuntimeException('Simulated WooCommerce payment failure.');
            }
            $this->status = 'processing';
        }

        public function add_order_note(string $note, bool $isCustomerNote = false): void
        {
            test_event('order_note');
        }
    }

    class WC_Order_Refund extends WC_Order
    {
    }

    class WC_Payment_Gateway
    {
        public array $options = ['enkap_currency' => 'XAF'];

        public function is_available(): bool
        {
            return true;
        }

        public function get_option(string $key, mixed $default = ''): mixed
        {
            return $this->options[$key] ?? $default;
        }
    }

    class WC_HTTPS
    {
        public static function force_https_url(string $url): string
        {
            return $url;
        }
    }

    class WP_REST_Response
    {
        public function __construct(public array $data, public int $status)
        {
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }

    class WC_Geolocation
    {
        public static function get_ip_address(): ?string
        {
            return null;
        }
    }

    final class TestCart
    {
        public function empty_cart(): void
        {
            test_event('cart_empty');
        }
    }

    final class TestWooCommerce
    {
        public function __construct(public ?TestCart $cart = null)
        {
        }
    }

    function WC(): ?TestWooCommerce
    {
        return $GLOBALS['test_woocommerce'];
    }

    function __($text, $domain = null): string
    {
        return (string)$text;
    }

    function esc_sql(string $value): string
    {
        return $value;
    }

    function sanitize_title(string $value): string
    {
        return strtolower(trim($value));
    }

    function sanitize_text_field(string $value): string
    {
        return trim($value);
    }

    function wp_is_uuid(string $value): bool
    {
        return true;
    }

    function wc_get_order(int $orderId): ?WC_Order
    {
        return $GLOBALS['test_order'];
    }

    function wp_kses_post(string $value): string
    {
        return $value;
    }

    function current_time(string $type): string
    {
        return '2026-11-10 12:00:00';
    }

    function wc_get_logger(): WC_Logger_Interface
    {
        return new TestLogger();
    }

    function get_woocommerce_currency(): string
    {
        return $GLOBALS['test_currency'];
    }

    function wc_reduce_stock_levels(int $orderId): void
    {
        test_event('stock_reduce');
    }

    function do_action(string $hook, ...$args): void
    {
        test_event('action:' . $hook);
    }

    function add_action(...$args): void
    {
    }

    function add_filter(...$args): void
    {
    }

    function delete_option(string $option): void
    {
    }
}

namespace Enkap\OAuth\Enum {
    enum PaymentStatus: string
    {
        case CREATED_STATUS = 'CREATED';
        case INITIALISED_STATUS = 'INITIALISED';
        case IN_PROGRESS_STATUS = 'IN_PROGRESS';
        case CONFIRMED_STATUS = 'CONFIRMED';
        case FAILED_STATUS = 'FAILED';
        case CANCELED_STATUS = 'CANCELED';
        case REFUNDED_STATUS = 'REFUNDED';
        case EXPIRED_STATUS = 'EXPIRED';
        case UNKNOWN_STATUS = 'UNKNOWN';
    }
}

namespace Enkap\OAuth\Model {
    class Order
    {
        public function __construct(private string $transactionId = 'transaction-1')
        {
        }

        public function getOrderTransactionId(): string
        {
            return $this->transactionId;
        }
    }
}

namespace Enkap\OAuth\Lib {
    class Helper
    {
        public static function getOderMerchantIdFromUrl(): string
        {
            return '00000000-0000-4000-8000-000000000001';
        }
    }
}

namespace Camoo\Enkap\WooCommerce\Logger {
    class Logger
    {
        public function info($file, $line, $message): void
        {
        }
    }
}

namespace {
    require_once __DIR__ . '/../includes/Repository/RepositoryException.php';
    require_once __DIR__ . '/../includes/Repository/EnkapPaymentRepository.php';
    require_once __DIR__ . '/../includes/Plugin.php';
    require_once __DIR__ . '/../includes/Install.php';
    require_once __DIR__ . '/../includes/Gateway.php';

    $db = new \wpdb();
    $repository = new \Camoo\Enkap\WooCommerce\Repository\EnkapPaymentRepository($db, new \TestLogger());

    // Atomic status transition and terminal-state protection.
    reset_test_events();
    assert_true(
        $repository->updateStatusIfChanged('merchant-1', 'CONFIRMED', '127.0.0.1'),
        'A changed status should be reported as updated.'
    );
    assert_same(1, $db->queryCount, 'A status transition should use one database query.');
    assert_true(
        str_contains($db->last_query, '`status` IS NULL') &&
        str_contains($db->last_query, '`status` NOT IN') &&
        str_contains($db->last_query, '`status` <>'),
        'The status update must be conditional on the previous status and terminal states.'
    );

    $db->queryResult = 0;
    assert_true(
        !$repository->updateStatusIfChanged('merchant-1', 'CONFIRMED', '127.0.0.1'),
        'A duplicate or missing status transition should not be reported as changed.'
    );

    // Database failures must not be reported as successful transitions.
    $db->queryResult = false;
    assert_true(
        !$repository->updateStatusIfChanged('merchant-1', 'FAILED'),
        'Database failures should be handled without reporting a successful transition.'
    );

    // Failed WooCommerce side effects can release the claim for a later callback retry.
    $db->queryResult = 1;
    $db->queryCount = 0;
    reset_test_events();
    $failedOrder = new \WC_Order();
    $failedOrder->throwOnPaymentComplete = true;
    $pluginRepository = new \ReflectionProperty(
        \Camoo\Enkap\WooCommerce\Plugin::class,
        'paymentRepository'
    );
    $pluginRepository->setAccessible(true);
    $pluginRepository->setValue(null, $repository);
    try {
        \Camoo\Enkap\WooCommerce\Plugin::processWebhookStatus(
            $failedOrder,
            \Enkap\OAuth\Enum\PaymentStatus::CONFIRMED_STATUS,
            'merchant-1'
        );
        throw new \RuntimeException('Expected the simulated WooCommerce failure.');
    } catch (\RuntimeException $exception) {
        assert_same(2, $db->queryCount, 'A failed side effect should be followed by one conditional reset query.');
        assert_true(str_contains($db->last_query, 'status = NULL'), 'The payment claim should be reset after failure.');
    }

    // Confirmed payments must use WooCommerce's payment completion flow.
    $db->queryResult = 1;
    reset_test_events();
    \Camoo\Enkap\WooCommerce\Plugin::processWebhookStatus(
        new \WC_Order(),
        \Enkap\OAuth\Enum\PaymentStatus::CONFIRMED_STATUS,
        'merchant-2'
    );
    assert_true(in_array('payment_complete', $GLOBALS['test_events'], true), 'Confirmed payments must call payment_complete().');
    assert_true(!in_array('stock_reduce', $GLOBALS['test_events'], true), 'Stock must not be reduced manually after payment_complete().');

    // Duplicate callbacks must not repeat order, stock, or hook side effects.
    $db->queryResult = 0;
    reset_test_events();
    \Camoo\Enkap\WooCommerce\Plugin::processWebhookStatus(
        new \WC_Order(),
        \Enkap\OAuth\Enum\PaymentStatus::CONFIRMED_STATUS,
        'merchant-1'
    );
    assert_true(
        !array_filter($GLOBALS['test_events'], fn (string $event): bool => str_starts_with($event, 'order_status:')),
        'Duplicate callbacks must not update the order again.'
    );
    assert_true(!in_array('stock_reduce', $GLOBALS['test_events'], true), 'Duplicate callbacks must not reduce stock again.');

    // Payment mapping must be inserted before order/cart side effects.
    $db->queryResult = 1;
    $db->insertResult = 1;
    $GLOBALS['test_woocommerce'] = new \TestWooCommerce(new \TestCart());
    $gatewayReflection = new \ReflectionClass(\Camoo\Enkap\WooCommerce\WC_Enkap_Gateway::class);
    $gateway = $gatewayReflection->newInstanceWithoutConstructor();
    $gatewayRepository = $gatewayReflection->getProperty('paymentRepository');
    $gatewayRepository->setAccessible(true);
    $gatewayRepository->setValue($gateway, $repository);

    // Already-terminal webhooks should be acknowledged instead of retried as failures.
    $terminalGateway = $gatewayReflection->newInstanceWithoutConstructor();
    $gatewayLogger = $gatewayReflection->getProperty('logger');
    $gatewayLogger->setAccessible(true);
    $gatewayLogger->setValue($terminalGateway, new \Camoo\Enkap\WooCommerce\Logger\Logger());
    $db->getVarResult = 42;
    $GLOBALS['test_order'] = new \WC_Order(42, 'completed');
    $terminalResponse = $terminalGateway->onNotification();
    assert_same(200, $terminalResponse->get_status(), 'Terminal webhook retries should receive HTTP 200.');
    assert_same('OK', $terminalResponse->data['status'], 'Terminal webhook retries should be acknowledged as OK.');

    $handleOrderResponse = $gatewayReflection->getMethod('handleOrderResponse');
    $handleOrderResponse->setAccessible(true);

    reset_test_events();
    $handleOrderResponse->invoke($gateway, new \WC_Order(), 'merchant-2', new \Enkap\OAuth\Model\Order());
    assert_same(
        ['db_insert', 'order_status:on-hold', 'cart_empty', 'order_note'],
        $GLOBALS['test_events'],
        'The payment mapping must be inserted before order and cart changes.'
    );

    $db->insertResult = false;
    reset_test_events();
    try {
        $handleOrderResponse->invoke($gateway, new \WC_Order(), 'merchant-3', new \Enkap\OAuth\Model\Order());
        throw new \RuntimeException('Expected the simulated insert failure.');
    } catch (\Throwable $exception) {
        assert_same(['db_insert'], $GLOBALS['test_events'], 'A failed insert must prevent local side effects.');
    }

    // Multisite cleanup must use the configured WordPress table prefix.
    $db->insertResult = 1;
    $db->prefix = 'custom_';
    $install = new \Camoo\Enkap\WooCommerce\Install();
    $GLOBALS['wpdb'] = $db;
    $tables = $install->remove_table_on_delete_blog([]);
    assert_true(
        in_array('custom_wc_enkap_payments', $tables, true),
        'Multisite cleanup must register the payment table with the configured prefix.'
    );

    // The gateway must be unavailable when the store currency differs from XAF.
    $currencyGateway = $gatewayReflection->newInstanceWithoutConstructor();
    $GLOBALS['test_currency'] = 'XAF';
    assert_true($currencyGateway->is_available(), 'The gateway should be available for its configured currency.');
    $GLOBALS['test_currency'] = 'EUR';
    assert_true(!$currencyGateway->is_available(), 'The gateway should be hidden for an incompatible currency.');

    echo "Payment-flow regression tests: PASS\n";
}
