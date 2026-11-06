<?php declare(strict_types=1);

namespace Nova\GoCardless\Installer;

use Doctrine\DBAL\Connection;
use Nova\GoCardless\Payment\GoCardlessSepaHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Legt die Zahlungsart „SEPA-Lastschrift (GoCardless)“ an. Sie ist nach der Installation inaktiv und keinem
 * Verkaufskanal zugeordnet – erst einrichten (Access Token, Webhook), dann aktivieren und zuordnen.
 * Bei der Deinstallation wird sie nur deaktiviert (Bestellungen verweisen auf sie).
 */
class PaymentMethodInstaller
{
    public const TECHNICAL_NAME = 'nova_gocardless_sepa';

    private const TEXTS = [
        'de' => [
            'name' => 'SEPA-Lastschrift (GoCardless)',
            'description' => 'Bequem per Lastschrift bezahlen: Sie erteilen das SEPA-Lastschriftmandat auf der sicheren Seite von GoCardless.',
        ],
        'en' => [
            'name' => 'SEPA Direct Debit (GoCardless)',
            'description' => 'Pay conveniently by direct debit: you set up the SEPA mandate on the secure GoCardless page.',
        ],
    ];

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Payment\PaymentMethodCollection> $paymentMethodRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $paymentMethodRepository,
    ) {
    }

    public function install(string $pluginId, Context $context): void
    {
        $id = $this->existingId();
        $data = [
            'id' => $id ?? Uuid::randomHex(),
            'handlerIdentifier' => GoCardlessSepaHandler::class,
            'technicalName' => self::TECHNICAL_NAME,
            'pluginId' => $pluginId,
        ];

        if ($id === null) {
            // Nur bei der Neuanlage: Texte und inaktiv (vom Händler geänderte Texte bleiben bei Updates erhalten)
            $data['active'] = false;
            $data['afterOrderEnabled'] = true;
            $data['position'] = 50;
            $data['translations'] = $this->translations();
        }

        $this->paymentMethodRepository->upsert([$data], $context);
    }

    public function setActive(bool $active, Context $context): void
    {
        $id = $this->existingId();
        if ($id !== null) {
            $this->paymentMethodRepository->update([['id' => $id, 'active' => $active]], $context);
        }
    }

    public function existingId(): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(`id`)) FROM `payment_method` WHERE `technical_name` = :name OR `handler_identifier` = :handler LIMIT 1',
            ['name' => self::TECHNICAL_NAME, 'handler' => GoCardlessSepaHandler::class]
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Texte je vorhandener Sprache: Deutsch für de-*, sonst Englisch.
     *
     * @return array<string, array{name: string, description: string}>
     */
    private function translations(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(l.`id`)) AS id, loc.`code` FROM `language` l INNER JOIN `locale` loc ON loc.`id` = l.`locale_id`'
        );

        $translations = [];
        foreach ($rows as $row) {
            $lang = str_starts_with(strtolower((string) $row['code']), 'de') ? 'de' : 'en';
            $translations[(string) $row['id']] = self::TEXTS[$lang];
        }

        return $translations;
    }
}
