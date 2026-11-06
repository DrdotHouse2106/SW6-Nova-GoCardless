<?php declare(strict_types=1);

namespace Nova\GoCardless\Api;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Schlanker Client für die GoCardless-API (kein SDK nötig).
 *
 * - Authorization: Bearer <Access Token>, GoCardless-Version: 2015-07-06
 * - POST mit Idempotency-Key; bei Netzwerkfehlern oder 5xx wird einmal mit demselben Schlüssel wiederholt,
 *   GoCardless legt dann keine doppelte Ressource an.
 * - Fehler werden ohne Token und ohne Anfrageinhalt (Kundendaten) protokolliert.
 */
class GoCardlessClient
{
    public const API_VERSION = '2015-07-06';
    private const TIMEOUT = 15.0;
    private const MAX_DURATION = 30.0;

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly LoggerInterface $logger,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    /**
     * @param array{billing_requests: array<string, mixed>} $payload
     *
     * @return array<string, mixed>
     */
    public function createBillingRequest(Credentials $credentials, array $payload): array
    {
        try {
            return $this->resource($this->request($credentials, 'POST', '/billing_requests', $payload), 'billing_requests');
        } catch (GoCardlessApiException $e) {
            // Wiederholung nach Zeitüberschreitung: GoCardless hatte die erste Anfrage schon angelegt (409 mit
            // links.conflicting_resource_id) → vorhandenen Billing Request verwenden
            if ($e->statusCode === 409 && $e->conflictingResourceId !== '') {
                return $this->getBillingRequest($credentials, $e->conflictingResourceId);
            }

            throw $e;
        }
    }

    /**
     * @param array{billing_request_flows: array<string, mixed>} $payload
     *
     * @return array<string, mixed>
     */
    public function createBillingRequestFlow(Credentials $credentials, array $payload): array
    {
        return $this->resource($this->request($credentials, 'POST', '/billing_request_flows', $payload), 'billing_request_flows');
    }

    /**
     * @return array<string, mixed>
     */
    public function getBillingRequest(Credentials $credentials, string $id): array
    {
        return $this->resource($this->request($credentials, 'GET', '/billing_requests/' . self::id($id)), 'billing_requests');
    }

    /** Bricht einen noch offenen Billing Request ab, damit der alte Link nicht mehr genutzt werden kann */
    public function cancelBillingRequest(Credentials $credentials, string $id): void
    {
        $this->request($credentials, 'POST', '/billing_requests/' . self::id($id) . '/actions/cancel', ['data' => new \stdClass()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayment(Credentials $credentials, string $id): array
    {
        return $this->resource($this->request($credentials, 'GET', '/payments/' . self::id($id)), 'payments');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCreditors(Credentials $credentials): array
    {
        $data = $this->request($credentials, 'GET', '/creditors?limit=10');
        $list = $data['creditors'] ?? null;
        if (!\is_array($list)) {
            throw new GoCardlessApiException('Unerwartete Antwort von GoCardless (creditors fehlt)');
        }

        return array_values(array_filter($list, '\is_array'));
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function request(Credentials $credentials, string $method, string $path, ?array $payload = null): array
    {
        if ($credentials->accessToken === '') {
            throw new GoCardlessApiException('Kein GoCardless Access Token eingetragen');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $credentials->accessToken,
            'GoCardless-Version' => self::API_VERSION,
            'Accept' => 'application/json',
        ];
        $options = ['headers' => $headers, 'timeout' => self::TIMEOUT, 'max_duration' => self::MAX_DURATION];
        if ($payload !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['headers']['Idempotency-Key'] = bin2hex(random_bytes(16));
            $options['body'] = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        $url = $credentials->baseUrl() . $path;
        $lastError = new GoCardlessApiException('GoCardless-Anfrage fehlgeschlagen');

        // Höchstens ein Wiederholungsversuch (bei Netzwerkfehler oder 5xx), POST mit demselben Idempotency-Key
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();
                $content = $response->getContent(false);
            } catch (TransportExceptionInterface $e) {
                $lastError = new GoCardlessApiException('GoCardless nicht erreichbar: ' . $e->getMessage(), 0, 'transport', $e);
                $this->log($credentials, $method, $path, 0, 'transport', $e->getMessage());

                continue;
            }

            $data = $content === '' ? [] : json_decode($content, true);
            if (!\is_array($data)) {
                $data = [];
            }

            if ($status >= 200 && $status < 300) {
                return $data;
            }

            $error = \is_array($data['error'] ?? null) ? $data['error'] : [];
            $type = \is_string($error['type'] ?? null) ? $error['type'] : '';
            $message = \is_string($error['message'] ?? null) ? $error['message'] : 'HTTP ' . $status;
            $details = self::errorDetails($error);
            $this->log($credentials, $method, $path, $status, $type, $message . $details);

            $links = \is_array($error['errors'][0]['links'] ?? null) ? $error['errors'][0]['links'] : [];
            $conflicting = \is_string($links['conflicting_resource_id'] ?? null) ? $links['conflicting_resource_id'] : '';
            $lastError = new GoCardlessApiException('GoCardless-Fehler: ' . $message . $details, $status, $type, null, $conflicting);

            if ($status < 500) {
                break;
            }
        }

        throw $lastError;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resource(array $data, string $key): array
    {
        $resource = $data[$key] ?? null;
        if (!\is_array($resource)) {
            throw new GoCardlessApiException('Unerwartete Antwort von GoCardless (' . $key . ' fehlt)');
        }

        /** @var array<string, mixed> $resource */
        return $resource;
    }

    /**
     * Nur Feldnamen und Meldungen der Validierungsfehler, keine Werte.
     *
     * @param array<mixed> $error
     */
    private static function errorDetails(array $error): string
    {
        $parts = [];
        foreach (\is_array($error['errors'] ?? null) ? $error['errors'] : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $field = \is_string($item['field'] ?? null) ? $item['field'] . ' ' : '';
            $msg = \is_string($item['message'] ?? null) ? $item['message'] : '';
            if ($field . $msg !== '') {
                $parts[] = trim($field . $msg);
            }
        }

        return $parts === [] ? '' : ' (' . implode('; ', \array_slice($parts, 0, 5)) . ')';
    }

    private static function id(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $id)) {
            throw new GoCardlessApiException('Ungültige GoCardless-ID');
        }

        return $id;
    }

    private function log(Credentials $credentials, string $method, string $path, int $status, string $type, string $message): void
    {
        $this->logger->warning('Nova GoCardless: API-Anfrage fehlgeschlagen', [
            'mode' => $credentials->mode,
            'method' => $method,
            'path' => strtok($path, '?'),
            'status' => $status,
            'type' => $type,
            'message' => mb_substr($message, 0, 500),
        ]);
    }
}
