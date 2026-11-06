<?php declare(strict_types=1);

namespace Nova\GoCardless\Service;

use Nova\GoCardless\Webhook\EventMapper;

/**
 * Entscheidet, ob ein Statuswechsel sinnvoll ist (reine Logik). Webhooks kommen teils doppelt oder in falscher
 * Reihenfolge; der Status darf deshalb nie „rückwärts“ laufen (z. B. von „bezahlt“ zurück auf „in Bearbeitung“).
 * Erstattete Zahlungen fasst das Plugin nie an – das ist Sache des Händlers.
 */
final class StatePolicy
{
    /** Status, aus denen heraus „Mandat erteilt“ (in Bearbeitung/autorisiert) gesetzt wird */
    private const PENDING_STATES = ['open', 'reminded', 'unconfirmed'];

    /** Status, die als „noch nicht bezahlt“ gelten */
    private const NOT_PAID_STATES = ['open', 'reminded', 'unconfirmed', 'in_progress', 'authorized'];

    private const NEVER_TOUCH = ['refunded', 'refunded_partially'];

    /**
     * @return string|null Ziel, das wirklich angesteuert werden soll, oder null (nichts tun)
     */
    public static function effectiveTarget(string $current, string $target): ?string
    {
        if ($current === $target || \in_array($current, self::NEVER_TOUCH, true)) {
            return null;
        }

        switch ($target) {
            case EventMapper::STATE_IN_PROGRESS:
                return \in_array($current, self::PENDING_STATES, true) ? $target : null;

            case EventMapper::STATE_AUTHORIZED:
                return \in_array($current, [...self::PENDING_STATES, 'in_progress'], true) ? $target : null;

            case EventMapper::STATE_PAID:
                // auch aus „fehlgeschlagen“, „storniert“, „Rückbuchung“ (z. B. erneuter Einzug, zurückgenommene Rückbuchung)
                return $target;

            case EventMapper::STATE_FAILED:
                if (\in_array($current, self::NOT_PAID_STATES, true)) {
                    return $target;
                }
                // Späte Rückgabe nach „bezahlt“: Geld ist zurückgegangen → Rückbuchung
                if (\in_array($current, ['paid', 'paid_partially'], true)) {
                    return EventMapper::STATE_CHARGEBACK;
                }

                return null;

            case EventMapper::STATE_CANCELLED:
                return \in_array($current, [...self::NOT_PAID_STATES, 'failed'], true) ? $target : null;

            case EventMapper::STATE_CHARGEBACK:
                return $target;
        }

        return null;
    }
}
