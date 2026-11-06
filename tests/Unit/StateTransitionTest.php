<?php declare(strict_types=1);

namespace Nova\GoCardless\Tests\Unit;

use Nova\GoCardless\Payment\FinalizeOutcome;
use Nova\GoCardless\Service\StatePolicy;
use Nova\GoCardless\Service\TransitionPathFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateTransitionTest extends TestCase
{
    /**
     * Übergänge der Standard-Zustandsmaschine „order_transaction.state“ (Shopware 6.7, Auszug).
     *
     * @return list<array{from: string, action: string, to: string}>
     */
    private static function defaultEdges(): array
    {
        $raw = [
            ['open', 'process', 'in_progress'], ['open', 'paid', 'paid'], ['open', 'paid_partially', 'paid_partially'],
            ['open', 'cancel', 'cancelled'], ['open', 'fail', 'failed'], ['open', 'remind', 'reminded'],
            ['open', 'authorize', 'authorized'], ['open', 'process_unconfirmed', 'unconfirmed'],
            ['in_progress', 'paid', 'paid'], ['in_progress', 'fail', 'failed'], ['in_progress', 'cancel', 'cancelled'],
            ['in_progress', 'authorize', 'authorized'], ['in_progress', 'reopen', 'open'],
            ['authorized', 'paid', 'paid'], ['authorized', 'fail', 'failed'], ['authorized', 'cancel', 'cancelled'],
            ['paid', 'refund', 'refunded'], ['paid', 'cancel', 'cancelled'], ['paid', 'chargeback', 'chargeback'],
            ['paid', 'refund_partially', 'refunded_partially'],
            ['chargeback', 'paid', 'paid'], ['chargeback', 'cancel', 'cancelled'],
            ['failed', 'reopen', 'open'], ['cancelled', 'reopen', 'open'], ['cancelled', 'paid', 'paid'],
            ['reminded', 'paid', 'paid'], ['reminded', 'cancel', 'cancelled'],
        ];

        return array_map(static fn (array $e): array => ['from' => $e[0], 'action' => $e[1], 'to' => $e[2]], $raw);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function policyCases(): iterable
    {
        yield 'Mandat erteilt: offen → in Bearbeitung' => ['open', 'in_progress', 'in_progress'];
        yield 'Mandat erteilt nach schnellem Webhook: bezahlt bleibt' => ['paid', 'in_progress', null];
        yield 'autorisiert aus in Bearbeitung' => ['in_progress', 'authorized', 'authorized'];
        yield 'bezahlt aus in Bearbeitung' => ['in_progress', 'paid', 'paid'];
        yield 'bezahlt doppelt' => ['paid', 'paid', null];
        yield 'bezahlt nach fehlgeschlagen (erneuter Einzug)' => ['failed', 'paid', 'paid'];
        yield 'fehlgeschlagen aus in Bearbeitung' => ['in_progress', 'failed', 'failed'];
        yield 'späte Rückgabe nach bezahlt → Rückbuchung' => ['paid', 'failed', 'chargeback'];
        yield 'storniert nach bezahlt: nichts' => ['paid', 'cancelled', null];
        yield 'storniert aus offen' => ['open', 'cancelled', 'cancelled'];
        yield 'Rückbuchung aus bezahlt' => ['paid', 'chargeback', 'chargeback'];
        yield 'erstattet bleibt erstattet' => ['refunded', 'chargeback', null];
        yield 'teilerstattet bleibt' => ['refunded_partially', 'paid', null];
    }

    #[DataProvider('policyCases')]
    public function testPolicy(string $current, string $target, ?string $expected): void
    {
        self::assertSame($expected, StatePolicy::effectiveTarget($current, $target));
    }

    /**
     * @return iterable<string, array{string, string, ?list<string>}>
     */
    public static function pathCases(): iterable
    {
        yield 'direkt' => ['open', 'in_progress', ['process']];
        yield 'bezahlt direkt' => ['in_progress', 'paid', ['paid']];
        yield 'Rückbuchung aus bezahlt' => ['paid', 'chargeback', ['chargeback']];
        yield 'Rückbuchung ohne vorheriges bezahlt' => ['in_progress', 'chargeback', ['paid', 'chargeback']];
        yield 'fehlgeschlagen → bezahlt über reopen' => ['failed', 'paid', ['reopen', 'paid']];
        yield 'storniert → bezahlt direkt' => ['cancelled', 'paid', ['paid']];
        yield 'schon am Ziel' => ['paid', 'paid', []];
        yield 'kein Weg' => ['refunded', 'paid', null];
    }

    /**
     * @param list<string>|null $expected
     */
    #[DataProvider('pathCases')]
    public function testPathFinder(string $from, string $to, ?array $expected): void
    {
        self::assertSame($expected, TransitionPathFinder::find(self::defaultEdges(), $from, $to));
    }

    public function testPathFinderAvoidsDetoursOverEndStates(): void
    {
        // einziger Weg führt über „storniert“ → nicht erlaubt
        $edges = [
            ['from' => 'authorized', 'action' => 'cancel', 'to' => 'cancelled'],
            ['from' => 'cancelled', 'action' => 'paid', 'to' => 'paid'],
        ];
        self::assertNull(TransitionPathFinder::find($edges, 'authorized', 'paid'));
    }

    public function testFinalizeOutcome(): void
    {
        self::assertSame(FinalizeOutcome::SUCCESS, FinalizeOutcome::of('fulfilled', false));
        self::assertSame(FinalizeOutcome::SUCCESS, FinalizeOutcome::of('fulfilling', false));
        self::assertSame(FinalizeOutcome::SUCCESS, FinalizeOutcome::of('ready_to_fulfil', true));
        self::assertSame(FinalizeOutcome::CANCEL, FinalizeOutcome::of('pending', true));
        self::assertSame(FinalizeOutcome::CANCEL, FinalizeOutcome::of('pending', false));
        self::assertSame(FinalizeOutcome::CANCEL, FinalizeOutcome::of('cancelled', false));
        self::assertSame(FinalizeOutcome::CANCEL, FinalizeOutcome::of('irgendwas', true));
        self::assertSame(FinalizeOutcome::ERROR, FinalizeOutcome::of('irgendwas', false));
    }
}
