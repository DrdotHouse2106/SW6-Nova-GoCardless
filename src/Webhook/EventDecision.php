<?php declare(strict_types=1);

namespace Nova\GoCardless\Webhook;

/**
 * Was ein Ereignis im Shop auslösen soll.
 */
final class EventDecision
{
    public const LOOKUP_PAYMENT = 'payment';
    public const LOOKUP_BILLING_REQUEST = 'billing_request';
    public const LOOKUP_MANDATE = 'mandate';

    public function __construct(
        /** Über welche gespeicherte ID die Bestelltransaktion gefunden wird */
        public readonly string $lookup,
        /** ID aus den Links des Ereignisses */
        public readonly string $lookupId,
        /** Ziel-Zahlungsstatus (technischer Name) oder null = Status nicht ändern */
        public readonly ?string $targetState = null,
        /** Administration benachrichtigen (z. B. Rückbuchung) */
        public readonly bool $notifyAdmin = false,
        /** Notiz für den internen Kommentar der Bestellung (deutsch) */
        public readonly ?string $note = null,
    ) {
    }
}
