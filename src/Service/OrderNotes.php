<?php declare(strict_types=1);

namespace Nova\GoCardless\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Notizen am internen Kommentar der Bestellung und Benachrichtigungen in der Administration (Glocke oben rechts).
 */
class OrderNotes
{
    /**
     * @param EntityRepository<\Shopware\Core\Framework\Notification\NotificationCollection> $notificationRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $notificationRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{orderId: string, orderNumber: string}|null
     */
    public function orderOf(string $transactionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(o.`id`)) AS id, o.`order_number` FROM `order_transaction` t
             INNER JOIN `order` o ON o.`id` = t.`order_id` AND o.`version_id` = t.`order_version_id`
             WHERE t.`id` = :id AND t.`version_id` = :version',
            ['id' => Uuid::fromHexToBytes($transactionId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        if ($row === false) {
            return null;
        }

        return ['orderId' => (string) $row['id'], 'orderNumber' => (string) ($row['order_number'] ?? '')];
    }

    public function appendNote(string $orderId, string $text): void
    {
        $line = (new \DateTimeImmutable())->format('d.m.Y H:i') . ' GoCardless: ' . $text;
        $this->connection->executeStatement(
            'UPDATE `order` SET `internal_comment` = CONCAT_WS(:nl, NULLIF(`internal_comment`, \'\'), :line)
             WHERE `id` = :id AND `version_id` = :version',
            [
                'nl' => "\n",
                'line' => mb_substr($line, 0, 1000),
                'id' => Uuid::fromHexToBytes($orderId),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]
        );
    }

    public function notifyAdmin(string $message, Context $context): void
    {
        try {
            $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($message): void {
                $this->notificationRepository->create([[
                    'id' => Uuid::randomHex(),
                    'status' => 'warning',
                    'message' => mb_substr($message, 0, 1000),
                    'adminOnly' => false,
                    'requiredPrivileges' => ['order:read'],
                ]], $context);
            });
        } catch (\Throwable $e) {
            $this->logger->error('Nova GoCardless: Benachrichtigung konnte nicht angelegt werden', ['exception' => $e->getMessage()]);
        }
    }
}
