<?php

declare(strict_types=1);

namespace Camoo\Enkap\WooCommerce\Repository;

use Camoo\Enkap\WooCommerce\Plugin;

use function esc_sql;
use function sanitize_title;

use WC_Logger_Interface;

use function wp_is_uuid;
use function wp_kses_post;

use wpdb;

defined('ABSPATH') || exit; // Exit if accessed directly
final class EnkapPaymentRepository
{
    private const TABLE_NAME = 'wc_enkap_payments';

    public function __construct(private wpdb $db, private ?WC_Logger_Interface $logger = null)
    {
    }

    public function insert(int $orderId, string $merchantReferenceId, string $orderTransactionId): void
    {
        $data = [
            'wc_order_id' => $orderId,
            'order_transaction_id' => $orderTransactionId,
            'merchant_reference_id' => $merchantReferenceId,
        ];

        $types = ['%d', '%s', '%s'];

        $result = $this->db->insert(esc_sql($this->getTableName()), $data, $types);

        if ($result === false) {
            $message = 'Failed to insert Enkap payment record.';
            if ($this->db->last_error !== '') {
                $message .= ' DB error: ' . $this->db->last_error . '.';
            }
            if ($this->db->last_query !== '') {
                $message .= ' Last query: ' . $this->db->last_query;
            }

            $this->getLogger()->log(
                'error',
                wp_kses_post($message),
                [
                    'source' => Plugin::WC_ENKAP_GATEWAY_ID,
                    'backtrace' => [
                        [
                            'file' => __FILE__,
                            'line' => __LINE__,
                            'function' => __FUNCTION__,
                            'class' => __CLASS__,
                        ],
                    ],
                ]
            );
            throw new RepositoryException(wp_kses_post($message));
        }
    }

    public function getPaymentByWcOrderId(int $wcOrderId): ?object
    {
        $table = esc_sql($this->getTableName());

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->db->prepare('SELECT * FROM ' . $table . ' WHERE wc_order_id = %d LIMIT 1', $wcOrderId);
        $result = $this->db->get_row($query);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return $result ?: null;
    }

    public function getWcOrderIdByMerchantReferenceId(string $merchantReferenceId): ?int
    {
        if (!wp_is_uuid($merchantReferenceId)) {

            $this->getLogger()->log(
                'warning',
                sprintf('Merchant reference id must be a UUID. Received: %s', $merchantReferenceId),
                [
                    'source' => Plugin::WC_ENKAP_GATEWAY_ID,
                    'backtrace' => [
                        [
                            'file' => __FILE__,
                            'line' => __LINE__,
                            'function' => __FUNCTION__,
                            'class' => __CLASS__,
                        ],
                    ],
                ]
            );

            return null;
        }

        $table = esc_sql($this->getTableName());

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->db->prepare(
            'SELECT wc_order_id FROM ' . $table . ' WHERE merchant_reference_id = %s LIMIT 1',
            $merchantReferenceId
        );

        $result = $this->db->get_var($query);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return $result !== null ? (int)$result : null;
    }

    public function updateStatusIfChanged(string $merchantReferenceId, string $newStatus, ?string $remoteIp = null): bool
    {
        $table = esc_sql($this->getTableName());

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->db->prepare(
            'SELECT `status` FROM ' . $table . ' WHERE merchant_reference_id = %s LIMIT 1',
            $merchantReferenceId
        );

        $current = $this->db->get_var($query);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        $normalizedNewStatus = sanitize_title($newStatus);

        if ($current === $normalizedNewStatus) {
            $this->getLogger()->log(
                'warning',
                sprintf('Merchant reference id "%s" is already in use.', $merchantReferenceId),
                [
                    'source' => Plugin::WC_ENKAP_GATEWAY_ID,
                    'backtrace' => [
                        [
                            'file' => __FILE__,
                            'line' => __LINE__,
                            'function' => __FUNCTION__,
                            'class' => __CLASS__,
                        ],
                    ],
                ]
            );

            return false;
        }

        try {
            $this->updateStatus($merchantReferenceId, $normalizedNewStatus, $remoteIp);

            return true;
        } catch (RepositoryException $exception) {
            $this->getLogger()->log(
                'error',
                wp_kses_post($exception->getMessage()),
                [
                    'source' => Plugin::WC_ENKAP_GATEWAY_ID,
                    'backtrace' => $exception->getTraceAsString(),
                ]
            );

            return false;
        }
    }

    private function updateStatus(string $merchantReferenceId, string $status, ?string $remoteIp = null): void
    {
        $data = [
            'status_date' => current_time('mysql'),
            'status' => $status,
        ];

        $formats = ['%s', '%s'];

        if ($remoteIp !== null) {
            $data['remote_ip'] = $remoteIp;
            $formats[] = '%s';
        }

        $result = $this->db->update(
            esc_sql($this->getTableName()),
            $data,
            ['merchant_reference_id' => $merchantReferenceId],
            $formats,
            ['%s']
        );

        if ($result === false || $this->db->last_error) {
            throw new RepositoryException('Failed to update payment status.');
        }
    }

    private function getTableName(): string
    {
        return $this->db->prefix . self::TABLE_NAME;
    }

    private function getLogger(): WC_Logger_Interface
    {
        if ($this->logger === null) {
            $this->logger = wc_get_logger();
        }

        return $this->logger;
    }
}
