<?php declare(strict_types=1);

namespace Nova\GoCardless\Webhook;

use Nova\GoCardless\Api\GoCardlessApiException;
use Nova\GoCardless\Api\GoCardlessClient;
use Nova\GoCardless\Api\PayloadBuilder;
use Nova\GoCardless\Service\OrderNotes;
use Nova\GoCardless\Service\Settings;
use Nova\GoCardless\Service\TransactionStateUpdater;
use Nova\GoCardless\Service\TransactionStore;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Verarbeitet die Ereignisse eines (bereits signaturgeprüften) Webhooks.
 *
 * - Jedes Ereignis wird höchstens einmal verarbeitet (Ereignis-ID in nova_gocardless_event).
 * - Zuordnung über die gespeicherte payment_id / billing_request_id / mandate_id. Kennt der Shop eine Zahlung
 *   noch nicht (Webhook „fulfilled“ verpasst), wird die Zahlung bei GoCardless abgerufen und über die
 *   Metadaten (Transaktions-ID) bzw. das Mandat zugeordnet.
 * - Ereignisse ohne passende Bestellung (z. B. Zahlungen aus anderen Quellen im selben GoCardless-Konto)
 *   werden ignoriert.
 */
class WebhookProcessor
{
    public function __construct(
        private readonly TransactionStore $store,
        private readonly TransactionStateUpdater $stateUpdater,
        private readonly OrderNotes $notes,
        private readonly Settings $settings,
        private readonly GoCardlessClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<WebhookEvent> $events
     * @param list<string|null> $salesChannelIds Kanäle, zu denen das passende Webhook-Secret gehört (null = global)
     *
     * @return array{processed: int, skipped: int}
     */
    public function process(array $events, array $salesChannelIds, Context $context): array
    {
        $processed = 0;
        $skipped = 0;

        foreach ($events as $event) {
            if (!$this->store->claimEvent($event->id, $event->resourceType, $event->action)) {
                ++$skipped;

                continue;
            }

            try {
                $this->handle($event, $salesChannelIds, $context) ? ++$processed : ++$skipped;
            } catch (\Throwable $e) {
                // Freigeben und Fehler weiterreichen → Antwort 500 → GoCardless wiederholt den Webhook
                $this->store->releaseEvent($event->id);
                $this->logger->error('Nova GoCardless: Webhook-Ereignis konnte nicht verarbeitet werden', [
                    'event' => $event->id,
                    'type' => $event->resourceType . '.' . $event->action,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        if (random_int(1, 50) === 1) {
            $this->store->pruneEvents();
        }

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * @param list<string|null> $salesChannelIds
     */
    private function handle(WebhookEvent $event, array $salesChannelIds, Context $context): bool
    {
        $decision = EventMapper::decide($event);
        if ($decision === null) {
            return false;
        }

        $rows = match ($decision->lookup) {
            EventDecision::LOOKUP_PAYMENT => $this->rowsForPayment($decision->lookupId, $salesChannelIds),
            EventDecision::LOOKUP_BILLING_REQUEST => $this->store->findBy('billing_request_id', $decision->lookupId),
            default => $this->store->findBy('mandate_id', $decision->lookupId),
        };
        if ($rows === []) {
            return false;
        }

        foreach ($rows as $row) {
            $transactionId = $row['order_transaction_id'];
            $target = $decision->targetState;

            if ($decision->lookup === EventDecision::LOOKUP_BILLING_REQUEST) {
                $this->syncBillingRequest($row, $event);
                if ($event->action === 'fulfilled') {
                    $target = $this->settings->successState($row['sales_channel_id']);
                }
            } elseif ($decision->lookup === EventDecision::LOOKUP_PAYMENT) {
                $this->store->update($transactionId, null, null, 'payment_' . $event->action);
            } else {
                $this->store->update($transactionId, null, null, 'mandate_' . $event->action);
            }

            $reached = $target !== null ? $this->stateUpdater->moveTo($transactionId, $target, $context, $decision->note) : null;

            $order = $this->notes->orderOf($transactionId);
            if ($order === null) {
                continue;
            }
            if ($decision->note !== null) {
                $this->notes->appendNote($order['orderId'], $decision->note);
            }
            if ($decision->notifyAdmin && $this->settings->notifyAdmin($row['sales_channel_id'])) {
                $this->notes->notifyAdmin(
                    \sprintf('GoCardless – Bestellung %s: %s%s', $order['orderNumber'], $decision->note ?? $event->action, $reached !== null ? ' (Zahlungsstatus: ' . $reached . ')' : ''),
                    $context
                );
            }
        }

        return true;
    }

    /**
     * Mandat- und Zahlungs-ID nachtragen (Webhook „fulfilled“ kann vor der Rückkehr des Kunden kommen).
     *
     * @param array{order_transaction_id: string, sales_channel_id: ?string, billing_request_id: string} $row
     */
    private function syncBillingRequest(array $row, WebhookEvent $event): void
    {
        $mandateId = $event->link('mandate_request_mandate');
        $paymentId = $event->link('payment_request_payment');

        if ($event->action === 'fulfilled' && ($mandateId === null || $paymentId === null)) {
            $credentials = $this->settings->credentials($row['sales_channel_id']);
            if ($credentials !== null) {
                try {
                    $billingRequest = $this->client->getBillingRequest($credentials, $row['billing_request_id']);
                    $links = \is_array($billingRequest['links'] ?? null) ? $billingRequest['links'] : [];
                    $mandateId ??= \is_string($links['mandate_request_mandate'] ?? null) ? $links['mandate_request_mandate'] : null;
                    $paymentId ??= \is_string($links['payment_request_payment'] ?? null) ? $links['payment_request_payment'] : null;
                } catch (GoCardlessApiException) {
                    // Zahlung wird später über die Metadaten zugeordnet
                }
            }
        }

        $this->store->update($row['order_transaction_id'], $mandateId, $paymentId, 'billing_request_' . $event->action);
    }

    /**
     * @param list<string|null> $salesChannelIds
     *
     * @return list<array{order_transaction_id: string, order_id: string, sales_channel_id: ?string, mode: string,
     *     billing_request_id: string, mandate_id: ?string, payment_id: ?string, gocardless_status: ?string}>
     */
    private function rowsForPayment(string $paymentId, array $salesChannelIds): array
    {
        $rows = $this->store->findBy('payment_id', $paymentId);
        if ($rows !== []) {
            return $rows;
        }

        // Rückfall: Zahlung bei GoCardless abrufen (mit dem Zugang des Kanals, zu dem das Secret gehört)
        $tried = [];
        foreach ($salesChannelIds as $salesChannelId) {
            $credentials = $this->settings->credentials($salesChannelId);
            if ($credentials === null || isset($tried[$credentials->accessToken])) {
                continue;
            }
            $tried[$credentials->accessToken] = true;

            try {
                $payment = $this->client->getPayment($credentials, $paymentId);
            } catch (GoCardlessApiException) {
                continue;
            }

            $metadata = \is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
            $transactionId = $metadata[PayloadBuilder::META_TRANSACTION_ID] ?? null;
            $row = \is_string($transactionId) && Uuid::isValid($transactionId) ? $this->store->byTransaction($transactionId) : null;

            if ($row === null) {
                $links = \is_array($payment['links'] ?? null) ? $payment['links'] : [];
                $mandateId = \is_string($links['mandate'] ?? null) ? $links['mandate'] : '';
                $candidates = $mandateId !== '' ? $this->store->findBy('mandate_id', $mandateId) : [];
                // Nur eindeutige Zuordnung (ein Mandat kann mehrere Bestellungen haben)
                $row = \count($candidates) === 1 && $candidates[0]['payment_id'] === null ? $candidates[0] : null;
            }

            if ($row !== null) {
                $this->store->update($row['order_transaction_id'], null, $paymentId, null);
                $row['payment_id'] = $paymentId;

                return [$row];
            }
        }

        return [];
    }
}
