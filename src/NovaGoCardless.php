<?php declare(strict_types=1);

namespace Nova\GoCardless;

use Doctrine\DBAL\Connection;
use Nova\GoCardless\Installer\PaymentMethodInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

/**
 * Nova GoCardless – SEPA-Lastschrift über GoCardless (Billing Request Flow, gehostete Seite).
 */
class NovaGoCardless extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->installPaymentMethod($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->installPaymentMethod($updateContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        // Zahlungsart bewusst nicht automatisch aktivieren – erst nach dem Einrichten
        $this->installPaymentMethod($activateContext->getContext());
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->paymentMethods()?->setActive(false, $deactivateContext->getContext());
        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $this->paymentMethods()?->setActive(false, $uninstallContext->getContext());
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->connection();
        if ($connection === null) {
            return;
        }
        // Zuordnung Bestellung ↔ GoCardless-IDs; die Zahlungsart selbst bleibt (Bestellungen verweisen darauf)
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_gocardless_transaction`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_gocardless_event`');
        $connection->executeStatement('DELETE FROM `system_config` WHERE `configuration_key` LIKE \'NovaGoCardless.%\'');
    }

    private function installPaymentMethod(\Shopware\Core\Framework\Context $context): void
    {
        $installer = $this->paymentMethods();
        $idProvider = $this->container?->get(PluginIdProvider::class);
        if ($installer === null || !$idProvider instanceof PluginIdProvider) {
            return;
        }

        $installer->install($idProvider->getPluginIdByBaseClass(static::class, $context), $context);
    }

    private function paymentMethods(): ?PaymentMethodInstaller
    {
        $connection = $this->connection();
        $repository = $this->container?->get('payment_method.repository');
        if ($connection === null || !$repository instanceof EntityRepository) {
            return null;
        }

        /** @var EntityRepository<\Shopware\Core\Checkout\Payment\PaymentMethodCollection> $repository */
        return new PaymentMethodInstaller($connection, $repository);
    }

    private function connection(): ?Connection
    {
        $connection = $this->container?->get(Connection::class);

        return $connection instanceof Connection ? $connection : null;
    }
}
