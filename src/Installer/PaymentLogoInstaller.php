<?php declare(strict_types=1);

namespace Nova\GoCardless\Installer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Hängt das GoCardless-Logo an die Zahlungsart – nur, wenn sie noch kein Bild hat (ein eigenes Logo bleibt).
 * Läuft bei Installation, Update und Aktivierung; nutzt nur öffentliche Shopware-Dienste (media.repository,
 * FileSaver), damit es auch beim Update ohne neu aufgebauten Container klappt. Fehler brechen nichts ab.
 */
class PaymentLogoInstaller
{
    public const FILE = __DIR__ . '/../Resources/media/gocardless.png';
    private const FILENAME = 'nova-gocardless-logo';

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Payment\PaymentMethodCollection> $paymentMethodRepository
     * @param EntityRepository<\Shopware\Core\Content\Media\MediaCollection> $mediaRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $paymentMethodRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly FileSaver $fileSaver,
    ) {
    }

    public function install(Context $context): void
    {
        $id = (new PaymentMethodInstaller($this->connection, $this->paymentMethodRepository))->existingId();
        if ($id === null) {
            return;
        }

        $mediaId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(`media_id`)) FROM `payment_method` WHERE `id` = UNHEX(:id)',
            ['id' => $id]
        );
        if (\is_string($mediaId) && $mediaId !== '') {
            return;
        }

        $blob = @file_get_contents(self::FILE);
        if ($blob === false || $blob === '') {
            return;
        }

        $existing = $this->connection->fetchOne(
            'SELECT LOWER(HEX(`id`)) FROM `media` WHERE `file_name` = :name AND `file_extension` = \'png\' LIMIT 1',
            ['name' => self::FILENAME]
        );
        if (\is_string($existing) && $existing !== '') {
            // Schon einmal hochgeladen (z. B. Logo entfernt und Plugin neu aktiviert): wiederverwenden
            $this->paymentMethodRepository->update([['id' => $id, 'mediaId' => $existing]], $context);

            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'novagc');
        if ($tmp === false || file_put_contents($tmp, $blob) === false) {
            return;
        }

        $mediaId = Uuid::randomHex();
        try {
            $folder = $this->connection->fetchOne(
                'SELECT LOWER(HEX(f.`id`)) FROM `media_folder` f INNER JOIN `media_default_folder` d ON d.`id` = f.`default_folder_id` WHERE d.`entity` = \'payment_method\' LIMIT 1'
            );
            $media = ['id' => $mediaId, 'private' => false];
            if (\is_string($folder) && $folder !== '') {
                $media['mediaFolderId'] = $folder;
            }
            $this->mediaRepository->create([$media], $context);
            $this->fileSaver->persistFileToMedia(new MediaFile($tmp, 'image/png', 'png', \strlen($blob), md5($blob)), self::FILENAME, $mediaId, $context);
            $this->paymentMethodRepository->update([['id' => $id, 'mediaId' => $mediaId]], $context);
        } catch (\Throwable) {
            // Ohne Logo weiter; leeren Medien-Datensatz wieder entfernen
            try {
                $this->mediaRepository->delete([['id' => $mediaId]], $context);
            } catch (\Throwable) {
            }
        } finally {
            @unlink($tmp);
        }
    }
}
