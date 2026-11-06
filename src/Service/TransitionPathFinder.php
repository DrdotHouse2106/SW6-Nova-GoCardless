<?php declare(strict_types=1);

namespace Nova\GoCardless\Service;

/**
 * Sucht den kürzesten Weg durch die Zustandsmaschine „order_transaction.state“ (Breitensuche, höchstens zwei Schritte,
 * reine Logik).
 * Die Übergänge werden aus der Datenbank gelesen – so funktioniert das Plugin auch, wenn ein Shop die
 * Zustandsmaschine erweitert hat. Beispiel: „fehlgeschlagen“ → „bezahlt“ = reopen, paid.
 */
final class TransitionPathFinder
{
    /** Kein Umweg über End- oder Fehlerzustände (z. B. nie „storniert“ → … → „fehlgeschlagen“) */
    public const AVOID = ['cancelled', 'failed', 'refunded', 'refunded_partially', 'chargeback'];

    /**
     * @param list<array{from: string, action: string, to: string}> $edges
     * @param list<string> $avoid Zustände, die nicht als Zwischenschritt dienen dürfen
     *
     * @return list<string>|null Aktionen in Reihenfolge, [] wenn schon am Ziel, null wenn kein Weg
     */
    public static function find(array $edges, string $from, string $to, int $maxSteps = 2, array $avoid = self::AVOID): ?array
    {
        if ($from === $to) {
            return [];
        }

        $byFrom = [];
        foreach ($edges as $edge) {
            $byFrom[$edge['from']][] = $edge;
        }
        foreach ($byFrom as &$list) {
            // stabile Reihenfolge, direkte Übergänge zum Ziel zuerst
            usort($list, static fn (array $a, array $b): int => [$a['to'] !== $to, $a['action']] <=> [$b['to'] !== $to, $b['action']]);
        }
        unset($list);

        /** @var list<array{0: string, 1: list<string>}> $queue */
        $queue = [[$from, []]];
        $visited = [$from => true];
        while ($queue !== []) {
            [$state, $path] = array_shift($queue);
            if (\count($path) >= $maxSteps) {
                continue;
            }
            foreach ($byFrom[$state] ?? [] as $edge) {
                $next = $edge['to'];
                if (isset($visited[$next])) {
                    continue;
                }
                $nextPath = [...$path, $edge['action']];
                if ($next === $to) {
                    return $nextPath;
                }
                if (\in_array($next, $avoid, true)) {
                    continue;
                }
                $visited[$next] = true;
                $queue[] = [$next, $nextPath];
            }
        }

        return null;
    }
}
