<?php declare(strict_types=1);

namespace Nova\GoCardless\Api;

/**
 * Baut die Anfragen an GoCardless (Billing Request und Billing Request Flow).
 * Reine Logik ohne Shopware-Abhängigkeiten – dadurch ohne Kernel testbar.
 *
 * Formate nach https://docs.gocardless.com/docs/api-reference/billing-request und
 * https://docs.gocardless.com/docs/api-reference/billing-request-flow
 */
final class PayloadBuilder
{
    public const SCHEME = 'sepa_core';
    public const DEFAULT_DESCRIPTION = 'Bestellung {orderNumber}';
    public const MAX_DESCRIPTION_LENGTH = 140;
    public const MAX_MANDATE_REFERENCE_LENGTH = 35;

    /** Metadaten-Schlüssel (GoCardless erlaubt höchstens 3 Schlüssel) */
    public const META_ORDER_NUMBER = 'shopware_order';
    public const META_TRANSACTION_ID = 'shopware_transaction';

    /**
     * Betrag in Cent. Shopware rechnet mit float; zuerst auf 2 Stellen runden, damit z. B. 19.99 nicht
     * als 1998.9999… abgeschnitten wird.
     */
    public static function toMinorUnits(float $amount): int
    {
        if (!is_finite($amount)) {
            throw new \InvalidArgumentException('Betrag ist keine endliche Zahl');
        }

        return (int) round(round($amount, 2) * 100);
    }

    /**
     * @return array{billing_requests: array<string, mixed>}
     */
    public static function billingRequest(
        int $amountMinor,
        string $currency,
        string $description,
        string $orderNumber,
        string $transactionId,
        ?string $mandateReference = null,
    ): array {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Der Betrag muss größer als 0 sein');
        }

        $metadata = [
            self::META_ORDER_NUMBER => mb_substr($orderNumber, 0, 500),
            self::META_TRANSACTION_ID => mb_substr($transactionId, 0, 500),
        ];

        $mandateRequest = ['scheme' => self::SCHEME, 'currency' => strtoupper($currency)];
        if ($mandateReference !== null && $mandateReference !== '') {
            $mandateRequest['reference'] = $mandateReference;
        }

        return [
            'billing_requests' => [
                'payment_request' => [
                    'description' => $description,
                    'amount' => $amountMinor,
                    'currency' => strtoupper($currency),
                    'metadata' => $metadata,
                ],
                'mandate_request' => $mandateRequest,
                'metadata' => $metadata,
            ],
        ];
    }

    /**
     * @param array<string, string> $prefilledCustomer
     *
     * @return array{billing_request_flows: array<string, mixed>}
     */
    public static function billingRequestFlow(
        string $billingRequestId,
        string $redirectUri,
        string $exitUri,
        array $prefilledCustomer,
        ?string $language,
    ): array {
        $flow = [
            'redirect_uri' => $redirectUri,
            'exit_uri' => $exitUri,
            'links' => ['billing_request' => $billingRequestId],
        ];
        if ($prefilledCustomer !== []) {
            $flow['prefilled_customer'] = $prefilledCustomer;
        }
        if ($language !== null && $language !== '') {
            $flow['language'] = $language;
        }

        return ['billing_request_flows' => $flow];
    }

    /**
     * Vorbelegung der Kundendaten auf der GoCardless-Seite (der Kunde kann sie dort ändern).
     * Leere Werte werden weggelassen. Firmenname nur ohne Vor- und Nachnamen (Vorgabe von GoCardless).
     *
     * @param array{firstName?: ?string, lastName?: ?string, company?: ?string, email?: ?string, street?: ?string,
     *     additionalAddressLine1?: ?string, additionalAddressLine2?: ?string, zipcode?: ?string, city?: ?string,
     *     countryIso?: ?string, language?: ?string} $data
     *
     * @return array<string, string>
     */
    public static function prefilledCustomer(array $data): array
    {
        $map = [
            'given_name' => $data['firstName'] ?? null,
            'family_name' => $data['lastName'] ?? null,
            'email' => $data['email'] ?? null,
            'address_line1' => $data['street'] ?? null,
            'address_line2' => $data['additionalAddressLine1'] ?? null,
            'address_line3' => $data['additionalAddressLine2'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['zipcode'] ?? null,
            'country_code' => isset($data['countryIso']) ? strtoupper((string) $data['countryIso']) : null,
            'language' => $data['language'] ?? null,
        ];

        $hasName = trim((string) ($data['firstName'] ?? '')) !== '' || trim((string) ($data['lastName'] ?? '')) !== '';
        if (!$hasName) {
            $map['company_name'] = $data['company'] ?? null;
        }

        $result = [];
        foreach ($map as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $result[$key] = mb_substr($value, 0, 255);
            }
        }

        return $result;
    }

    /**
     * Beschreibung der Zahlung (wird dem Kunden auf der GoCardless-Seite und in der Vorankündigung gezeigt).
     * Platzhalter: {orderNumber}, {shopName}.
     */
    public static function description(?string $template, string $orderNumber, string $shopName = ''): string
    {
        $template = trim((string) $template);
        if ($template === '') {
            $template = self::DEFAULT_DESCRIPTION;
        }

        $text = strtr($template, ['{orderNumber}' => $orderNumber, '{shopName}' => $shopName]);
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        if ($text === '') {
            $text = strtr(self::DEFAULT_DESCRIPTION, ['{orderNumber}' => $orderNumber]);
        }

        return mb_substr($text, 0, self::MAX_DESCRIPTION_LENGTH);
    }

    /**
     * Eigene Mandatsreferenz aus Präfix + Bestellnummer. Nur Großbuchstaben, Ziffern und „-“ (der kleinste
     * gemeinsame Nenner der SEPA-Regeln), höchstens 35 Zeichen. Leeres Präfix = GoCardless vergibt die Referenz.
     */
    public static function mandateReference(?string $prefix, string $orderNumber): ?string
    {
        $prefix = trim((string) $prefix);
        if ($prefix === '') {
            return null;
        }

        $reference = strtoupper($prefix . $orderNumber);
        $reference = (string) preg_replace('/[^A-Z0-9-]+/', '-', $reference);
        $reference = trim((string) preg_replace('/-{2,}/', '-', $reference), '-');
        if ($reference === '') {
            return null;
        }

        return substr($reference, -self::MAX_MANDATE_REFERENCE_LENGTH);
    }

    /** Hängt einen Query-Parameter an eine URL an (Rückkehr-URL von Shopware hat schon „?_sw_payment_token=…“). */
    public static function appendQuery(string $url, string $key, string $value): string
    {
        $fragment = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value) . $fragment;
    }

    /** „de-DE“ → „de“ (ISO 639-1); nur Sprachen, die GoCardless kennt, sonst null (GoCardless wählt selbst). */
    public static function languageFromLocale(?string $locale): ?string
    {
        $language = strtolower(substr(trim((string) $locale), 0, 2));
        $supported = ['de', 'en', 'fr', 'es', 'it', 'nl', 'pt', 'da', 'nb', 'sv', 'sl'];

        return \in_array($language, $supported, true) ? $language : null;
    }
}
