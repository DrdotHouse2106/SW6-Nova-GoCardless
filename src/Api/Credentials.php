<?php declare(strict_types=1);

namespace Nova\GoCardless\Api;

/**
 * Zugang zu GoCardless: Access Token und Modus (Sandbox oder Live).
 */
final class Credentials
{
    public const MODE_SANDBOX = 'sandbox';
    public const MODE_LIVE = 'live';

    public const BASE_URL_SANDBOX = 'https://api-sandbox.gocardless.com';
    public const BASE_URL_LIVE = 'https://api.gocardless.com';

    public readonly string $mode;

    public function __construct(
        #[\SensitiveParameter]
        public readonly string $accessToken,
        string $mode,
    ) {
        $this->mode = self::normalizeMode($mode);
    }

    public static function normalizeMode(?string $mode): string
    {
        return $mode === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_SANDBOX;
    }

    public function baseUrl(): string
    {
        return $this->mode === self::MODE_LIVE ? self::BASE_URL_LIVE : self::BASE_URL_SANDBOX;
    }

    /** Verhindert, dass das Token in Dumps/Logs landet */
    public function __debugInfo(): array
    {
        return ['mode' => $this->mode, 'accessToken' => '***'];
    }
}
