<?php declare(strict_types=1);

namespace Nova\GoCardless\Service;

use Doctrine\DBAL\Connection;
use Nova\GoCardless\Api\Credentials;
use Nova\GoCardless\Webhook\EventMapper;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Einstellungen je Verkaufskanal (Erweiterungen → Nova GoCardless → Konfigurieren).
 */
class Settings
{
    public const PREFIX = 'NovaGoCardless.config.';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly Connection $connection,
    ) {
    }

    public function credentials(?string $salesChannelId): ?Credentials
    {
        $token = trim($this->config->getString(self::PREFIX . 'accessToken', $salesChannelId));
        if ($token === '') {
            return null;
        }

        return new Credentials($token, $this->config->getString(self::PREFIX . 'mode', $salesChannelId));
    }

    public function successState(?string $salesChannelId): string
    {
        return EventMapper::normalizeSuccessState($this->config->getString(self::PREFIX . 'successState', $salesChannelId));
    }

    public function descriptionTemplate(?string $salesChannelId): string
    {
        return $this->config->getString(self::PREFIX . 'descriptionTemplate', $salesChannelId);
    }

    public function mandateReferencePrefix(?string $salesChannelId): string
    {
        return $this->config->getString(self::PREFIX . 'mandateReferencePrefix', $salesChannelId);
    }

    public function notifyAdmin(?string $salesChannelId): bool
    {
        $value = $this->config->get(self::PREFIX . 'notifyAdmin', $salesChannelId);

        return $value === null ? true : (bool) $value;
    }

    /**
     * Alle eingetragenen Webhook-Secrets (global und je Verkaufskanal) mit den Kanälen, für die sie gelten.
     * null als Kanal = globale Einstellung.
     *
     * @return array<string, list<string|null>> Secret => Verkaufskanal-IDs
     */
    public function webhookSecrets(): array
    {
        $result = [];
        foreach ([null, ...$this->salesChannelIds()] as $salesChannelId) {
            $secret = trim($this->config->getString(self::PREFIX . 'webhookSecret', $salesChannelId));
            if ($secret !== '') {
                $result[$secret][] = $salesChannelId;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function salesChannelIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT LOWER(HEX(`id`)) FROM `sales_channel`');

        return $ids;
    }
}
