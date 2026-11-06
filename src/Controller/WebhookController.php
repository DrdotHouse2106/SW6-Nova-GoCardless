<?php declare(strict_types=1);

namespace Nova\GoCardless\Controller;

use Nova\GoCardless\Service\Settings;
use Nova\GoCardless\Webhook\SignatureVerifier;
use Nova\GoCardless\Webhook\WebhookEvent;
use Nova\GoCardless\Webhook\WebhookProcessor;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Öffentlicher Webhook-Endpunkt für GoCardless: POST /api/_action/nova-gocardless/webhook
 *
 * Ohne Anmeldung erreichbar (auth_required = false), geschützt allein durch die Signatur. Antworten nach
 * Empfehlung von GoCardless: 498 bei falscher Signatur (keine Wiederholung), 200 bei Erfolg, 204 wenn kein
 * Ereignis relevant war, 500 bei Fehlern in der Verarbeitung (GoCardless wiederholt).
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class WebhookController
{
    private const MAX_BODY_BYTES = 1048576;

    public function __construct(
        private readonly Settings $settings,
        private readonly WebhookProcessor $processor,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/api/_action/nova-gocardless/webhook',
        name: 'api.action.nova_gocardless.webhook',
        defaults: ['auth_required' => false],
        methods: ['POST']
    )]
    public function webhook(Request $request, Context $context): Response
    {
        $body = $request->getContent();
        if ($body === '' || \strlen($body) > self::MAX_BODY_BYTES) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $secrets = $this->settings->webhookSecrets();
        $secret = SignatureVerifier::matchingSecret($body, $request->headers->get('Webhook-Signature'), array_map('strval', array_keys($secrets)));
        if ($secret === null) {
            $this->logger->notice('Nova GoCardless: Webhook mit ungültiger Signatur abgewiesen', [
                'secretsConfigured' => \count($secrets),
            ]);

            return new Response('', 498);
        }

        try {
            $events = WebhookEvent::listFromBody($body);
        } catch (\InvalidArgumentException) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->processor->process($events, $secrets[$secret], $context);
        } catch (\Throwable) {
            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($result['processed'] === 0) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return new JsonResponse($result);
    }
}
