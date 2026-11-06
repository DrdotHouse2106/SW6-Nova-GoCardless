<?php declare(strict_types=1);

namespace Nova\GoCardless\Api;

/**
 * Fehler bei einer Anfrage an GoCardless. Die Meldung enthält nie Token oder Bankdaten.
 */
final class GoCardlessApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly string $errorType = '',
        ?\Throwable $previous = null,
        public readonly string $conflictingResourceId = '',
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
