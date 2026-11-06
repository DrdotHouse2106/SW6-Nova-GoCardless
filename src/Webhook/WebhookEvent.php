<?php declare(strict_types=1);

namespace Nova\GoCardless\Webhook;

/**
 * Ein Ereignis aus dem Webhook-Inhalt („events“). Es werden nur die Felder übernommen, die das Plugin braucht.
 */
final class WebhookEvent
{
    /**
     * @param array<string, string> $links
     */
    public function __construct(
        public readonly string $id,
        public readonly string $resourceType,
        public readonly string $action,
        public readonly array $links,
        public readonly string $cause = '',
        public readonly string $description = '',
    ) {
    }

    public function link(string $name): ?string
    {
        $value = $this->links[$name] ?? '';

        return $value === '' ? null : $value;
    }

    /**
     * @throws \InvalidArgumentException bei ungültigem Inhalt
     *
     * @return list<self>
     */
    public static function listFromBody(string $body): array
    {
        try {
            $data = json_decode($body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Webhook-Inhalt ist kein gültiges JSON', 0, $e);
        }
        if (!\is_array($data) || !isset($data['events']) || !\is_array($data['events'])) {
            throw new \InvalidArgumentException('Webhook-Inhalt enthält keine Ereignisliste');
        }

        $events = [];
        foreach ($data['events'] as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $id = self::str($raw['id'] ?? null);
            $resourceType = self::str($raw['resource_type'] ?? null);
            $action = self::str($raw['action'] ?? null);
            if ($id === '' || $resourceType === '' || $action === '') {
                continue;
            }

            $links = [];
            if (isset($raw['links']) && \is_array($raw['links'])) {
                foreach ($raw['links'] as $key => $value) {
                    if (\is_string($key) && \is_string($value) && $value !== '') {
                        $links[$key] = $value;
                    }
                }
            }

            $details = isset($raw['details']) && \is_array($raw['details']) ? $raw['details'] : [];
            $events[] = new self(
                mb_substr($id, 0, 64),
                $resourceType,
                $action,
                $links,
                self::str($details['cause'] ?? null),
                mb_substr(self::str($details['description'] ?? null), 0, 500),
            );
        }

        return $events;
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
