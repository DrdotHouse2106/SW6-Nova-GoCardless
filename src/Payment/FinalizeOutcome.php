<?php declare(strict_types=1);

namespace Nova\GoCardless\Payment;

/**
 * Ergebnis der Rückkehr vom Billing Request Flow (reine Logik).
 */
final class FinalizeOutcome
{
    public const SUCCESS = 'success';
    public const CANCEL = 'cancel';
    public const ERROR = 'error';

    /**
     * @param string $billingRequestStatus Status des Billing Requests bei GoCardless
     * @param bool $exited Kunde kam über die Abbruch-URL (exit_uri) zurück
     *
     * @return self::SUCCESS|self::CANCEL|self::ERROR
     */
    public static function of(string $billingRequestStatus, bool $exited): string
    {
        return match ($billingRequestStatus) {
            // „fulfilling“/„ready_to_fulfil“: Kunde ist fertig, GoCardless legt Mandat und Zahlung gerade an
            'fulfilled', 'fulfilling', 'ready_to_fulfil' => self::SUCCESS,
            // nicht abgeschlossen (Abbruch, Zurück-Knopf) oder bei GoCardless abgebrochen
            'pending', 'cancelled' => self::CANCEL,
            default => $exited ? self::CANCEL : self::ERROR,
        };
    }
}
