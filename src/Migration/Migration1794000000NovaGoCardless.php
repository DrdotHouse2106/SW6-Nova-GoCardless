<?php declare(strict_types=1);

namespace Nova\GoCardless\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Tabellen von Nova GoCardless:
 * - nova_gocardless_transaction: Zuordnung Bestelltransaktion ↔ GoCardless-IDs (Billing Request, Mandat,
 *   Zahlung). Eigene Tabelle statt customFields, weil Webhooks über payment_id/mandate_id suchen und diese
 *   Spalten einen Index brauchen; IBAN oder andere Bankdaten werden nie gespeichert.
 * - nova_gocardless_event: bereits verarbeitete Webhook-Ereignisse (GoCardless liefert „mindestens einmal“)
 */
class Migration1794000000NovaGoCardless extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `nova_gocardless_transaction` (
                `order_transaction_id` BINARY(16) NOT NULL,
                `order_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NULL,
                `mode` VARCHAR(16) NOT NULL,
                `billing_request_id` VARCHAR(64) NOT NULL,
                `mandate_id` VARCHAR(64) NULL,
                `payment_id` VARCHAR(64) NULL,
                `gocardless_status` VARCHAR(64) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`order_transaction_id`),
                KEY `idx.nova_gocardless_transaction.billing_request` (`billing_request_id`),
                KEY `idx.nova_gocardless_transaction.payment` (`payment_id`),
                KEY `idx.nova_gocardless_transaction.mandate` (`mandate_id`),
                KEY `idx.nova_gocardless_transaction.order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `nova_gocardless_event` (
                `event_id` VARCHAR(64) NOT NULL,
                `resource_type` VARCHAR(64) NOT NULL,
                `action` VARCHAR(64) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`event_id`),
                KEY `idx.nova_gocardless_event.created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
