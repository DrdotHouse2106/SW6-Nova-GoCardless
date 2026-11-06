<?php declare(strict_types=1);

namespace Nova\GoCardless\Webhook;

/**
 * Prüft die Signatur eines GoCardless-Webhooks: Header „Webhook-Signature“ = HMAC-SHA256 (hex) des
 * unveränderten Rohinhalts, Schlüssel = Secret des Webhook-Endpunkts.
 */
final class SignatureVerifier
{
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * Gibt das passende Secret zurück (oder null). Mehrere Secrets sind möglich, wenn je Verkaufskanal ein
     * eigenes GoCardless-Konto bzw. ein eigener Webhook-Endpunkt eingetragen ist.
     *
     * @param list<string> $secrets
     */
    public static function matchingSecret(string $body, ?string $signature, array $secrets): ?string
    {
        $signature = strtolower(trim((string) $signature));
        if ($signature === '' || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return null;
        }

        $match = null;
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            // Alle Secrets prüfen (keine vorzeitige Rückkehr), Vergleich in konstanter Zeit
            if (hash_equals(self::sign($body, $secret), $signature) && $match === null) {
                $match = $secret;
            }
        }

        return $match;
    }
}
