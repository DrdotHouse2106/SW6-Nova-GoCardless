<?php declare(strict_types=1);

namespace Nova\GoCardless\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Psr\Log\LoggerInterface;

/**
 * Setzt den Zahlungsstatus einer Bestelltransaktion über Shopwares Zustandsmaschine (damit Flows,
 * Statushistorie und Mails wie gewohnt ausgelöst werden). Welche Übergänge nötig sind, berechnet
 * {@see TransitionPathFinder} aus den Übergängen in der Datenbank; {@see StatePolicy} verhindert Rückschritte.
 */
class TransactionStateUpdater
{
    /** @var list<array{from: string, action: string, to: string}>|null */
    private ?array $edges = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function currentState(string $transactionId): ?string
    {
        $state = $this->connection->fetchOne(
            'SELECT s.`technical_name` FROM `order_transaction` t
             INNER JOIN `state_machine_state` s ON s.`id` = t.`state_id`
             WHERE t.`id` = :id AND t.`version_id` = :version',
            ['id' => Uuid::fromHexToBytes($transactionId), 'version' => Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION)]
        );

        return \is_string($state) ? $state : null;
    }

    /**
     * @return string|null erreichter Status oder null, wenn nichts geändert wurde
     */
    public function moveTo(string $transactionId, string $target, Context $context, ?string $comment = null): ?string
    {
        $current = $this->currentState($transactionId);
        if ($current === null) {
            return null;
        }

        $effective = StatePolicy::effectiveTarget($current, $target);
        if ($effective === null) {
            return null;
        }

        $path = TransitionPathFinder::find($this->edges(), $current, $effective);
        if ($path === null) {
            $this->logger->warning('Nova GoCardless: kein Statusübergang möglich', [
                'orderTransactionId' => $transactionId,
                'from' => $current,
                'to' => $effective,
            ]);

            return null;
        }

        foreach ($path as $i => $action) {
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderTransactionDefinition::ENTITY_NAME,
                    $transactionId,
                    $action,
                    'stateId',
                    $i === \count($path) - 1 && $comment !== null ? mb_substr('GoCardless: ' . $comment, 0, 1000) : null
                ),
                $context
            );
        }

        return $effective;
    }

    /**
     * @return list<array{from: string, action: string, to: string}>
     */
    private function edges(): array
    {
        if ($this->edges !== null) {
            return $this->edges;
        }

        /** @var list<array{from: string, action: string, to: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT f.`technical_name` AS `from`, tr.`action_name` AS `action`, t.`technical_name` AS `to`
             FROM `state_machine_transition` tr
             INNER JOIN `state_machine` m ON m.`id` = tr.`state_machine_id`
             INNER JOIN `state_machine_state` f ON f.`id` = tr.`from_state_id`
             INNER JOIN `state_machine_state` t ON t.`id` = tr.`to_state_id`
             WHERE m.`technical_name` = :machine',
            ['machine' => OrderTransactionStates::STATE_MACHINE]
        );

        return $this->edges = $rows;
    }
}
