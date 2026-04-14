<?php

declare(strict_types=1);

namespace Camoo\Enkap\WooCommerce\Repository;

use function sanitize_title;
use function wp_is_uuid;

use wpdb;

/**
 * Repository for handling Enkap Payments in WooCommerce
 */
final class EnkapPaymentRepository
{
    private const TABLE_NAME = 'wc_enkap_payments';

    public function __construct(private wpdb $wpdb)
    {
    }

    /**
     * Inserts a payment record.
     *
     * @throws RepositoryException on failure
     */
    public function insert(int $orderId, string $merchantReferenceId, string $orderTransactionId): void
    {
        $data = [
            'wc_order_id' => $orderId,
            'order_transaction_id' => $orderTransactionId,
            'merchant_reference_id' => $merchantReferenceId,
        ];

        $types = ['%d', '%s', '%s'];

        $result = $this->wpdb->insert($this->getTableName(), $data, $types);

        if ($result === false) {
            throw new RepositoryException('Failed to insert Enkap payment record.');
        }
    }

    /** Fetch payment by WooCommerce order ID. */
    public function getPaymentByWcOrderId(int $wcOrderId): ?object
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->getTableName()} WHERE wc_order_id = %d LIMIT 1",
            $wcOrderId
        );

        return $this->wpdb->get_row($query) ?: null;
    }

    /** Returns WooCommerce order ID by merchant reference ID if valid. */
    public function getWcOrderIdByMerchantReferenceId(string $merchantReferenceId): ?int
    {
        if (!wp_is_uuid($merchantReferenceId)) {
            return null;
        }

        $query = $this->wpdb->prepare(
            "SELECT wc_order_id FROM {$this->getTableName()} WHERE merchant_reference_id = %s LIMIT 1",
            $merchantReferenceId
        );

        $result = $this->wpdb->get_var($query);

        return $result !== null ? (int)$result : null;
    }

    /** Updates payment status only if it has actually changed. */
    public function updateStatusIfChanged(string $merchantReferenceId, string $newStatus, ?string $remoteIp = null): bool
    {
        $query = $this->wpdb->prepare(
            "SELECT `status` FROM {$this->getTableName()} WHERE merchant_reference_id = %s LIMIT 1",
            $merchantReferenceId
        );
        $current = $this->wpdb->get_var($query);

        $normalizedNewStatus = sanitize_title($newStatus);

        if ($current === $normalizedNewStatus) {
            return false; // No update needed if status is the same
        }

        try {
            $this->updateStatus($merchantReferenceId, $normalizedNewStatus, $remoteIp);

            return true;
        } catch (RepositoryException) {
            // Log the error or handle it as needed
            return false;
        }
    }

    /** Updates payment status. */
    private function updateStatus(string $merchantReferenceId, string $status, ?string $remoteIp = null): void
    {
        $data = [
            'status_date' => current_time('mysql'),
            'status' => sanitize_title($status),
        ];

        $formats = ['%s', '%s'];

        if ($remoteIp !== null) {
            $data['remote_ip'] = $remoteIp;
            $formats[] = '%s';
        }

        $result = $this->wpdb->update(
            $this->getTableName(),
            $data,
            ['merchant_reference_id' => $merchantReferenceId],
            $formats,
            ['%s']
        );

        if ($result === false || $this->wpdb->last_error) {
            throw new RepositoryException('Failed to update payment status.');
        }
    }

    /** Gets fully-qualified Enkap payment table name. */
    private function getTableName(): string
    {
        return $this->wpdb->prefix . self::TABLE_NAME;
    }
}
