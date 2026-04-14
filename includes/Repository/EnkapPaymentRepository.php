<?php

declare(strict_types=1);

namespace Camoo\Enkap\WooCommerce\Repository;

use function esc_sql;
use function sanitize_title;
use function wp_is_uuid;
use function wp_kses_post;

use wpdb;

final class EnkapPaymentRepository
{
    private const TABLE_NAME = 'wc_enkap_payments';

    public function __construct(private wpdb $wpdb)
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

        $result = $this->wpdb->insert(esc_sql($this->getTableName()), $data, $types);

        if ($result === false) {
            $message = 'Failed to insert Enkap payment record.';
            if ($this->wpdb->last_error !== '') {
                $message .= ' DB error: ' . $this->wpdb->last_error . '.';
            }
            if ($this->wpdb->last_query !== '') {
                $message .= ' Last query: ' . $this->wpdb->last_query;
            }

            throw new RepositoryException(wp_kses_post($message));
        }
    }

    public function getPaymentByWcOrderId(int $wcOrderId): ?object
    {
        $table = esc_sql($this->getTableName());

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            'SELECT * FROM ' . $table . ' WHERE wc_order_id = %d LIMIT 1',
            $wcOrderId
        );

        $result = $this->wpdb->get_row($query);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return $result ?: null;
    }

    public function getWcOrderIdByMerchantReferenceId(string $merchantReferenceId): ?int
    {
        if (!wp_is_uuid($merchantReferenceId)) {
            return null;
        }

        $table = esc_sql($this->getTableName());

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            'SELECT wc_order_id FROM ' . $table . ' WHERE merchant_reference_id = %s LIMIT 1',
            $merchantReferenceId
        );

        $result = $this->wpdb->get_var($query);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return $result !== null ? (int)$result : null;
    }

    public function updateStatusIfChanged(string $merchantReferenceId, string $newStatus, ?string $remoteIp = null): bool
    {
        $table = esc_sql($this->getTableName());

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $query = $this->wpdb->prepare(
            'SELECT `status` FROM ' . $table . ' WHERE merchant_reference_id = %s LIMIT 1',
            $merchantReferenceId
        );

        $current = $this->wpdb->get_var($query);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        $normalizedNewStatus = sanitize_title($newStatus);

        if ($current === $normalizedNewStatus) {
            return false;
        }

        try {
            $this->updateStatus($merchantReferenceId, $normalizedNewStatus, $remoteIp);

            return true;
        } catch (RepositoryException) {
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

        $result = $this->wpdb->update(
            esc_sql($this->getTableName()),
            $data,
            ['merchant_reference_id' => $merchantReferenceId],
            $formats,
            ['%s']
        );

        if ($result === false || $this->wpdb->last_error) {
            throw new RepositoryException('Failed to update payment status.');
        }
    }

    private function getTableName(): string
    {
        return $this->wpdb->prefix . self::TABLE_NAME;
    }
}
