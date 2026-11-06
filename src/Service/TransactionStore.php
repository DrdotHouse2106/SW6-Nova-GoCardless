<?php declare(strict_types=1);

namespace Nova\GoCardless\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zuordnung Bestelltransaktion ↔ GoCardless-IDs (Tabelle nova_gocardless_transaction).
 *
 * @phpstan-type Row array{order_transaction_id: string, order_id: string, sales_channel_id: ?string, mode: string,
 *     billing_request_id: string, mandate_id: ?string, payment_id: ?string, gocardless_status: ?string}
 */
class TransactionStore
{
    private const SELECT = 'SELECT LOWER(HEX(`order_transaction_id`)) AS order_transaction_id, LOWER(HEX(`order_id`)) AS order_id,
        LOWER(HEX(`sales_channel_id`)) AS sales_channel_id, `mode`, `billing_request_id`, `mandate_id`, `payment_id`, `gocardless_status`
        FROM `nova_gocardless_transaction`';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Neuer Zahlungsversuch: alte IDs der Transaktion werden ersetzt */
    public function start(string $transactionId, string $orderId, ?string $salesChannelId, string $mode, string $billingRequestId): void
    {
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->connection->executeStatement(
            'INSERT INTO `nova_gocardless_transaction`
                (`order_transaction_id`, `order_id`, `sales_channel_id`, `mode`, `billing_request_id`, `mandate_id`, `payment_id`, `gocardless_status`, `created_at`)
             VALUES (:tid, :oid, :scid, :mode, :brq, NULL, NULL, :status, :now)
             ON DUPLICATE KEY UPDATE `order_id` = VALUES(`order_id`), `sales_channel_id` = VALUES(`sales_channel_id`), `mode` = VALUES(`mode`),
                `billing_request_id` = VALUES(`billing_request_id`), `mandate_id` = NULL, `payment_id` = NULL,
                `gocardless_status` = VALUES(`gocardless_status`), `updated_at` = :now',
            [
                'tid' => Uuid::fromHexToBytes($transactionId),
                'oid' => Uuid::fromHexToBytes($orderId),
                'scid' => $salesChannelId !== null ? Uuid::fromHexToBytes($salesChannelId) : null,
                'mode' => $mode,
                'brq' => $billingRequestId,
                'status' => 'pending',
                'now' => $now,
            ]
        );
    }

    /** Übernimmt Mandat-/Zahlungs-ID und Status, leere Werte überschreiben nichts */
    public function update(string $transactionId, ?string $mandateId, ?string $paymentId, ?string $status): void
    {
        $this->connection->executeStatement(
            'UPDATE `nova_gocardless_transaction`
             SET `mandate_id` = COALESCE(:mandate, `mandate_id`), `payment_id` = COALESCE(:payment, `payment_id`),
                 `gocardless_status` = COALESCE(:status, `gocardless_status`), `updated_at` = :now
             WHERE `order_transaction_id` = :tid',
            [
                'mandate' => self::emptyToNull($mandateId),
                'payment' => self::emptyToNull($paymentId),
                'status' => self::emptyToNull($status === null ? null : mb_substr($status, 0, 64)),
                'now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'tid' => Uuid::fromHexToBytes($transactionId),
            ]
        );
    }

    /**
     * @return Row|null
     */
    public function byTransaction(string $transactionId): ?array
    {
        $row = $this->connection->fetchAssociative(self::SELECT . ' WHERE `order_transaction_id` = :tid', ['tid' => Uuid::fromHexToBytes($transactionId)]);

        return $row === false ? null : self::row($row);
    }

    /**
     * @return list<Row>
     */
    public function findBy(string $column, string $value): array
    {
        if (!\in_array($column, ['billing_request_id', 'payment_id', 'mandate_id'], true)) {
            throw new \InvalidArgumentException('Unbekannte Spalte ' . $column);
        }

        $rows = $this->connection->fetchAllAssociative(self::SELECT . ' WHERE `' . $column . '` = :value LIMIT 50', ['value' => $value]);

        return array_map(self::row(...), $rows);
    }

    /** Merkt sich ein Webhook-Ereignis; false = war schon da (doppelte Zustellung) */
    public function claimEvent(string $eventId, string $resourceType, string $action): bool
    {
        $affected = $this->connection->executeStatement(
            'INSERT IGNORE INTO `nova_gocardless_event` (`event_id`, `resource_type`, `action`, `created_at`) VALUES (:id, :type, :action, :now)',
            [
                'id' => $eventId,
                'type' => mb_substr($resourceType, 0, 64),
                'action' => mb_substr($action, 0, 64),
                'now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        return $affected > 0;
    }

    /** Verarbeitung gescheitert → Ereignis freigeben, damit die Wiederholung von GoCardless greift */
    public function releaseEvent(string $eventId): void
    {
        $this->connection->executeStatement('DELETE FROM `nova_gocardless_event` WHERE `event_id` = :id', ['id' => $eventId]);
    }

    /** Ereignis-Protokoll aufräumen (GoCardless wiederholt höchstens wenige Tage) */
    public function pruneEvents(int $days = 90): void
    {
        $this->connection->executeStatement(
            'DELETE FROM `nova_gocardless_event` WHERE `created_at` < :limit',
            ['limit' => (new \DateTimeImmutable('-' . $days . ' days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return Row
     */
    private static function row(array $row): array
    {
        $str = static fn (mixed $v): ?string => \is_string($v) && $v !== '' ? $v : null;

        return [
            'order_transaction_id' => (string) $row['order_transaction_id'],
            'order_id' => (string) $row['order_id'],
            'sales_channel_id' => $str($row['sales_channel_id'] ?? null),
            'mode' => (string) $row['mode'],
            'billing_request_id' => (string) $row['billing_request_id'],
            'mandate_id' => $str($row['mandate_id'] ?? null),
            'payment_id' => $str($row['payment_id'] ?? null),
            'gocardless_status' => $str($row['gocardless_status'] ?? null),
        ];
    }

    private static function emptyToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
