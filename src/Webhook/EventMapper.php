<?php declare(strict_types=1);

namespace Nova\GoCardless\Webhook;

/**
 * Bildet GoCardless-Ereignisse auf Zahlungsstatus in Shopware ab (reine Logik).
 *
 * Zahlungen (resource_type „payments“):
 * - confirmed, paid_out          → bezahlt (Geld ist eingezogen)
 * - failed                       → fehlgeschlagen + Benachrichtigung
 * - late_failure_settled         → fehlgeschlagen (war schon bezahlt: Rückbuchung) + Benachrichtigung
 * - cancelled                    → storniert
 * - charged_back                 → Rückbuchung + Benachrichtigung
 * - chargeback_cancelled         → wieder bezahlt
 * - resubmission_requested, chargeback_settled → nur Notiz
 * Billing Requests: fulfilled → Erfolgsstatus (In Bearbeitung/Autorisiert), cancelled → storniert, failed → fehlgeschlagen.
 * Mandate: cancelled, failed, expired, blocked, … → nur Notiz an allen Bestellungen mit diesem Mandat.
 * Alles andere wird ignoriert (null).
 */
final class EventMapper
{
    public const STATE_IN_PROGRESS = 'in_progress';
    public const STATE_AUTHORIZED = 'authorized';
    public const STATE_PAID = 'paid';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_CHARGEBACK = 'chargeback';

    private const MANDATE_NOTES = [
        'cancelled' => 'Lastschriftmandat wurde storniert',
        'failed' => 'Lastschriftmandat ist fehlgeschlagen',
        'expired' => 'Lastschriftmandat ist abgelaufen',
        'blocked' => 'Lastschriftmandat wurde gesperrt',
        'replaced' => 'Lastschriftmandat wurde ersetzt',
        'transferred' => 'Lastschriftmandat wurde auf ein anderes Konto übertragen',
    ];

    public static function decide(WebhookEvent $event, string $successState = self::STATE_IN_PROGRESS): ?EventDecision
    {
        $suffix = self::suffix($event);

        switch ($event->resourceType) {
            case 'payments':
                $paymentId = $event->link('payment');
                if ($paymentId === null) {
                    return null;
                }

                return match ($event->action) {
                    'confirmed' => new EventDecision(EventDecision::LOOKUP_PAYMENT, $paymentId, self::STATE_PAID, false, 'Lastschrift bestätigt (Geld eingezogen)' . $suffix),
                    'paid_out' => new EventDecision(EventDecision::LOOKUP_PAYMENT, $paymentId, self::STATE_PAID),
                    'failed' => new EventDecision(EventDecision::LOOKUP_PAYMENT, $paymentId, self::STATE_FAILED, true, 'Lastschrift fehlgeschlagen' . $suffix),
                    'late_failure_settled' => new EventDecision(EventDecision::LOOKUP_PAYMENT, $paymentId, self::STATE_FAILED, true, 'Lastschrift nachträglich fehlgeschlagen' . $suffix),
                    'cancelled' => new EventDecision(EventDecision::LOOKUP_PAYMENT, $paymentId, self::STATE_CANCELLED, false, 'Lastschrift storniert' . $suffix),
                    'charged_back' => new EventDecision(EventDecision::LOOKUP_PAYMENT, $paymentId, self::STATE_CHARGEBACK, true, 'Rückbuchung der Lastschrift' . $suffix),
                    'chargeback_cancelled' => new EventDecision(EventDecision::LOOKUP_PAYMENT, $paymentId, self::STATE_PAID, false, 'Rückbuchung wurde zurückgenommen' . $suffix),
                    'chargeback_settled' => new EventDecision(EventDecision::LOOKUP_PAYMENT, $paymentId, null, false, 'Rückbuchung abgerechnet' . $suffix),
                    'resubmission_requested' => new EventDecision(EventDecision::LOOKUP_PAYMENT, $paymentId, null, false, 'Lastschrift wird erneut eingereicht' . $suffix),
                    default => null,
                };

            case 'billing_requests':
                $billingRequestId = $event->link('billing_request');
                if ($billingRequestId === null) {
                    return null;
                }

                return match ($event->action) {
                    'fulfilled' => new EventDecision(EventDecision::LOOKUP_BILLING_REQUEST, $billingRequestId, self::normalizeSuccessState($successState)),
                    'cancelled' => new EventDecision(EventDecision::LOOKUP_BILLING_REQUEST, $billingRequestId, self::STATE_CANCELLED),
                    'failed' => new EventDecision(EventDecision::LOOKUP_BILLING_REQUEST, $billingRequestId, self::STATE_FAILED, false, 'Lastschriftanfrage fehlgeschlagen' . $suffix),
                    default => null,
                };

            case 'mandates':
                $mandateId = $event->link('mandate');
                if ($mandateId === null || !isset(self::MANDATE_NOTES[$event->action])) {
                    return null;
                }

                return new EventDecision(EventDecision::LOOKUP_MANDATE, $mandateId, null, false, self::MANDATE_NOTES[$event->action] . $suffix);
        }

        return null;
    }

    public static function normalizeSuccessState(?string $state): string
    {
        return $state === self::STATE_AUTHORIZED ? self::STATE_AUTHORIZED : self::STATE_IN_PROGRESS;
    }

    private static function suffix(WebhookEvent $event): string
    {
        $parts = array_filter([$event->cause, $event->description], static fn (string $p): bool => $p !== '');

        return $parts === [] ? '' : ' (' . implode(': ', $parts) . ')';
    }
}
