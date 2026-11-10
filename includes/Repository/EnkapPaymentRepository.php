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

    public function getPaymentByMerchantReferenceId(string $merchantReferenceId): ?object
    {
        $table = esc_sql($this->getTableName());

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->db->prepare(
            'SELECT * FROM ' . $table . ' WHERE merchant_reference_id = %s LIMIT 1',
            $merchantReferenceId
        );
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
        $normalizedNewStatus = sanitize_title($newStatus);

        try {
            $changed = $this->updateStatus($merchantReferenceId, $normalizedNewStatus, $remoteIp);

            if (!$changed) {
                $this->getLogger()->log(
                    'info',
                    sprintf('Merchant reference id "%s" was already updated or was not found.', $merchantReferenceId),
                    [
                        'source' => Plugin::WC_ENKAP_GATEWAY_ID,
                    ]
                );
            }

            return $changed;
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

    public function resetStatusIfCurrent(string $merchantReferenceId, string $currentStatus): void
    {
        $table = esc_sql($this->getTableName());
        $status = sanitize_title($currentStatus);

        // Only roll back our own claim. A newer concurrent terminal transition must win.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->db->prepare(
            'UPDATE ' . $table .
            ' SET status_date = %s, status = NULL WHERE merchant_reference_id = %s AND `status` = %s',
            current_time('mysql'),
            $merchantReferenceId,
            $status
        );
        $result = $this->db->query($query);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        if ($result === false || $this->db->last_error) {
            throw new RepositoryException('Failed to reset payment status.');
        }
    }

    private function updateStatus(string $merchantReferenceId, string $status, ?string $remoteIp = null): bool
    {
        $table = esc_sql($this->getTableName());
        $set = 'status_date = %s, status = %s';
        $arguments = [current_time('mysql'), $status];

        if ($remoteIp !== null) {
            $set .= ', remote_ip = %s';
            $arguments[] = $remoteIp;
        }

        $arguments[] = $merchantReferenceId;
        $arguments[] = $status;

        // Keep the compare-and-update in one SQL statement. Callback retries can arrive
        // concurrently, so a SELECT followed by UPDATE would allow both requests through.
        // Terminal payment states are also immutable to prevent stale callbacks from
        // changing a completed/canceled/failed payment afterward.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->db->prepare(
            'UPDATE ' . $table . ' SET ' . $set .
            ' WHERE merchant_reference_id = %s AND (`status` IS NULL OR (' .
            '`status` NOT IN (\'confirmed\', \'canceled\', \'failed\') AND `status` <> %s))',
            ...$arguments
        );
        $result = $this->db->query($query);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        if ($result === false || $this->db->last_error) {
            throw new RepositoryException('Failed to update payment status.');
        }

        return $result > 0;
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
