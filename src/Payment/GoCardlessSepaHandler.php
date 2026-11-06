<?php declare(strict_types=1);

namespace Nova\GoCardless\Payment;

use Nova\GoCardless\Api\GoCardlessApiException;
use Nova\GoCardless\Api\GoCardlessClient;
use Nova\GoCardless\Api\PayloadBuilder;
use Nova\GoCardless\Service\Settings;
use Nova\GoCardless\Service\TransactionStateUpdater;
use Nova\GoCardless\Service\TransactionStore;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Zahlungsart „SEPA-Lastschrift (GoCardless)“ (Shopware-6.7-Handler-API).
 *
 * pay():      Billing Request (Zahlung + SEPA-Mandat) und Billing Request Flow anlegen, Kunde auf die
 *             gehostete Seite von GoCardless umleiten. Dort gibt er IBAN und Mandat ein – der Shop sieht
 *             keine Bankdaten.
 * finalize(): nach der Rückkehr den Billing Request abrufen. Erfüllt → „In Bearbeitung“ (oder „Autorisiert“);
 *             bezahlt ist eine Lastschrift erst nach dem Einzug (Webhook „confirmed“). Abbruch → Kundenabbruch.
 */
class GoCardlessSepaHandler extends AbstractPaymentHandler
{
    /** Query-Parameter an der Abbruch-URL (exit_uri) */
    public const EXIT_PARAMETER = 'nova_gocardless';
    public const EXIT_VALUE = 'exit';

    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     */
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly GoCardlessClient $client,
        private readonly Settings $settings,
        private readonly TransactionStore $store,
        private readonly TransactionStateUpdater $stateUpdater,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // Keine Erstattung über Shopware (Erstattungen im GoCardless-Dashboard), keine Abos
        return false;
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $transactionId = $transaction->getOrderTransactionId();
        $returnUrl = $transaction->getReturnUrl();
        if ($returnUrl === null || $returnUrl === '') {
            throw PaymentException::asyncProcessInterrupted($transactionId, 'Keine Rückkehr-URL für GoCardless vorhanden');
        }

        $orderTransaction = $this->loadTransaction($transactionId, $context);
        $order = $orderTransaction->getOrder();
        if ($order === null) {
            throw PaymentException::invalidTransaction($transactionId);
        }

        $salesChannelId = $order->getSalesChannelId();
        $credentials = $this->settings->credentials($salesChannelId);
        if ($credentials === null) {
            throw PaymentException::asyncProcessInterrupted($transactionId, 'GoCardless ist nicht eingerichtet (Access Token fehlt)');
        }

        $currency = strtoupper($order->getCurrency()?->getIsoCode() ?? '');
        if ($currency !== 'EUR') {
            throw PaymentException::asyncProcessInterrupted($transactionId, 'SEPA-Lastschrift ist nur in Euro möglich');
        }

        $orderNumber = (string) $order->getOrderNumber();

        try {
            $amount = PayloadBuilder::toMinorUnits($orderTransaction->getAmount()->getTotalPrice());
            $payload = PayloadBuilder::billingRequest(
                $amount,
                $currency,
                PayloadBuilder::description(
                    $this->settings->descriptionTemplate($salesChannelId),
                    $orderNumber,
                    (string) $order->getSalesChannel()?->getTranslation('name'),
                ),
                $orderNumber,
                $transactionId,
                PayloadBuilder::mandateReference($this->settings->mandateReferencePrefix($salesChannelId), $orderNumber),
            );

            $billingRequest = $this->client->createBillingRequest($credentials, $payload);
            $billingRequestId = \is_string($billingRequest['id'] ?? null) ? $billingRequest['id'] : '';
            if ($billingRequestId === '') {
                throw new GoCardlessApiException('Billing Request ohne ID');
            }

            $this->store->start($transactionId, $order->getId(), $salesChannelId, $credentials->mode, $billingRequestId);

            $language = PayloadBuilder::languageFromLocale($order->getLanguage()?->getLocale()?->getCode());
            $flow = $this->client->createBillingRequestFlow($credentials, PayloadBuilder::billingRequestFlow(
                $billingRequestId,
                $returnUrl,
                PayloadBuilder::appendQuery($returnUrl, self::EXIT_PARAMETER, self::EXIT_VALUE),
                PayloadBuilder::prefilledCustomer($this->customerData($order, $language)),
                $language,
            ));
        } catch (GoCardlessApiException|\InvalidArgumentException $e) {
            $this->logger->error('Nova GoCardless: Zahlung konnte nicht gestartet werden', [
                'orderNumber' => $orderNumber,
                'message' => $e->getMessage(),
            ]);

            throw PaymentException::asyncProcessInterrupted($transactionId, 'GoCardless: ' . $e->getMessage(), $e);
        }

        $url = \is_string($flow['authorisation_url'] ?? null) ? $flow['authorisation_url'] : '';
        if (!str_starts_with($url, 'https://')) {
            throw PaymentException::asyncProcessInterrupted($transactionId, 'GoCardless hat keine Zahlungsseite geliefert');
        }

        return new RedirectResponse($url);
    }

    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $transactionId = $transaction->getOrderTransactionId();
        $exited = $request->query->get(self::EXIT_PARAMETER) === self::EXIT_VALUE;

        $row = $this->store->byTransaction($transactionId);
        if ($row === null) {
            throw PaymentException::asyncFinalizeInterrupted($transactionId, 'Keine GoCardless-Zahlung zu dieser Transaktion gefunden');
        }

        $credentials = $this->settings->credentials($row['sales_channel_id']);
        if ($credentials === null) {
            throw PaymentException::asyncFinalizeInterrupted($transactionId, 'GoCardless ist nicht eingerichtet (Access Token fehlt)');
        }

        try {
            $billingRequest = $this->client->getBillingRequest($credentials, $row['billing_request_id']);
        } catch (GoCardlessApiException $e) {
            throw PaymentException::asyncFinalizeInterrupted($transactionId, 'GoCardless: ' . $e->getMessage(), $e);
        }

        $status = \is_string($billingRequest['status'] ?? null) ? $billingRequest['status'] : '';
        $links = \is_array($billingRequest['links'] ?? null) ? $billingRequest['links'] : [];
        $mandateId = \is_string($links['mandate_request_mandate'] ?? null) ? $links['mandate_request_mandate'] : null;
        $paymentId = \is_string($links['payment_request_payment'] ?? null) ? $links['payment_request_payment'] : null;
        $this->store->update($transactionId, $mandateId, $paymentId, $status);

        switch (FinalizeOutcome::of($status, $exited)) {
            case FinalizeOutcome::SUCCESS:
                // Webhooks können schneller sein (z. B. schon „bezahlt“) – StatePolicy verhindert dann Rückschritte
                $this->stateUpdater->moveTo(
                    $transactionId,
                    $this->settings->successState($row['sales_channel_id']),
                    $context,
                    'Lastschriftmandat erteilt'
                );

                return;
            case FinalizeOutcome::CANCEL:
                if ($status === 'pending') {
                    try {
                        $this->client->cancelBillingRequest($credentials, $row['billing_request_id']);
                    } catch (GoCardlessApiException) {
                        // nicht kritisch: ein offener Billing Request verfällt bei GoCardless von selbst
                    }
                }

                throw PaymentException::customerCanceled($transactionId, 'Lastschrift bei GoCardless nicht abgeschlossen');
            default:
                throw PaymentException::asyncFinalizeInterrupted($transactionId, 'GoCardless-Status: ' . ($status === '' ? 'unbekannt' : $status));
        }
    }

    private function loadTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.orderCustomer');
        $criteria->addAssociation('order.billingAddress.country');
        $criteria->addAssociation('order.language.locale');
        $criteria->addAssociation('order.salesChannel');

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();
        if (!$transaction instanceof OrderTransactionEntity) {
            throw PaymentException::invalidTransaction($transactionId);
        }

        return $transaction;
    }

    /**
     * @return array{firstName: ?string, lastName: ?string, company: ?string, email: ?string, street: ?string,
     *     additionalAddressLine1: ?string, additionalAddressLine2: ?string, zipcode: ?string, city: ?string,
     *     countryIso: ?string, language: ?string}
     */
    private function customerData(OrderEntity $order, ?string $language): array
    {
        $customer = $order->getOrderCustomer();
        $address = $order->getBillingAddress();

        return [
            'firstName' => $address?->getFirstName() ?? $customer?->getFirstName(),
            'lastName' => $address?->getLastName() ?? $customer?->getLastName(),
            'company' => $address?->getCompany() ?? $customer?->getCompany(),
            'email' => $customer?->getEmail(),
            'street' => $address?->getStreet(),
            'additionalAddressLine1' => $address?->getAdditionalAddressLine1(),
            'additionalAddressLine2' => $address?->getAdditionalAddressLine2(),
            'zipcode' => $address?->getZipcode(),
            'city' => $address?->getCity(),
            'countryIso' => $address?->getCountry()?->getIso(),
            'language' => $language,
        ];
    }
}
