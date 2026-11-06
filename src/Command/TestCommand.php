<?php declare(strict_types=1);

namespace Nova\GoCardless\Command;

use Nova\GoCardless\Api\GoCardlessApiException;
use Nova\GoCardless\Api\GoCardlessClient;
use Nova\GoCardless\Service\Settings;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prüft den Zugang zu GoCardless (GET /creditors) und zeigt Modus, Gläubiger und Webhook-Adresse.
 */
#[AsCommand(name: 'nova-gocardless:test', description: 'Verbindung zu GoCardless prüfen (Access Token, Modus, Gläubiger, Webhook)')]
class TestCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly GoCardlessClient $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Einstellungen dieses Verkaufskanals (ID) prüfen; Standard: alle Verkaufskanäle');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('sales-channel');
        if (\is_string($option) && $option !== '') {
            if (!Uuid::isValid($option)) {
                $output->writeln('<error>Ungültige Verkaufskanal-ID</error>');

                return self::FAILURE;
            }
            $channels = [strtolower($option)];
        } else {
            $channels = [null, ...$this->settings->salesChannelIds()];
        }

        $secrets = $this->settings->webhookSecrets();
        $failed = false;
        $tested = [];

        foreach ($channels as $salesChannelId) {
            $label = $salesChannelId === null ? 'Alle Verkaufskanäle (Standard)' : 'Verkaufskanal ' . $salesChannelId;
            $credentials = $this->settings->credentials($salesChannelId);
            if ($credentials === null) {
                if ($salesChannelId === null || \is_string($option)) {
                    $output->writeln($label . ': <comment>kein Access Token eingetragen</comment>');
                }

                continue;
            }

            $key = hash('sha256', $credentials->mode . $credentials->accessToken);
            if (isset($tested[$key]) && $option === null) {
                continue;
            }
            $tested[$key] = true;

            $output->writeln('<info>' . $label . '</info>');
            $output->writeln('  Modus:   ' . ($credentials->mode === 'live' ? 'Live' : 'Sandbox') . ' (' . $credentials->baseUrl() . ')');

            try {
                $creditors = $this->client->listCreditors($credentials);
            } catch (GoCardlessApiException $e) {
                $output->writeln('  <error>Fehler: ' . $e->getMessage() . '</error>');
                if ($e->statusCode === 401) {
                    $output->writeln('  Token falsch oder für den anderen Modus (Sandbox-Token funktionieren nur im Sandbox-Modus).');
                }
                $failed = true;

                continue;
            }

            if ($creditors === []) {
                $output->writeln('  <comment>Verbindung ok, aber kein Gläubiger (Creditor) gefunden</comment>');
            }
            foreach ($creditors as $creditor) {
                $output->writeln(\sprintf(
                    '  Gläubiger: %s (%s), Verifizierung: %s',
                    \is_string($creditor['name'] ?? null) ? $creditor['name'] : '?',
                    \is_string($creditor['id'] ?? null) ? $creditor['id'] : '?',
                    \is_string($creditor['verification_status'] ?? null) ? $creditor['verification_status'] : 'unbekannt'
                ));
            }

            $hasSecret = false;
            foreach ($secrets as $channelIds) {
                $hasSecret = $hasSecret || \in_array($salesChannelId, $channelIds, true);
            }
            $output->writeln('  Webhook-Secret: ' . ($hasSecret ? 'eingetragen' : '<comment>fehlt – Zahlungsstatus wird sonst nicht aktualisiert</comment>'));
        }

        $appUrl = rtrim((string) ($_SERVER['APP_URL'] ?? getenv('APP_URL') ?: 'https://ihr-shop.example'), '/');
        $output->writeln('');
        $output->writeln('Webhook-Adresse für das GoCardless-Dashboard (Developers → Webhooks):');
        $output->writeln('  ' . $appUrl . '/api/_action/nova-gocardless/webhook');

        if ($tested === [] && !$failed) {
            $output->writeln('<error>Kein Access Token eingetragen.</error>');

            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
