<?php declare(strict_types=1);

namespace Nova\GoCardless\Tests\Unit;

use Nova\GoCardless\Api\PayloadBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayloadBuilderTest extends TestCase
{
    /**
     * @return iterable<string, array{float, int}>
     */
    public static function amounts(): iterable
    {
        yield 'glatt' => [10.0, 1000];
        yield '19,99 (float-Falle)' => [19.99, 1999];
        yield '0,1 + 0,2' => [0.1 + 0.2, 30];
        yield '1,005 rundet' => [1.005, 101];
        yield 'groß' => [12345.67, 1234567];
        yield 'Cent' => [0.01, 1];
        yield '4,35 (float-Falle)' => [4.35, 435];
        yield '1,15 (float-Falle)' => [1.15, 115];
    }

    #[DataProvider('amounts')]
    public function testToMinorUnits(float $amount, int $expected): void
    {
        self::assertSame($expected, PayloadBuilder::toMinorUnits($amount));
    }

    public function testToMinorUnitsRejectsNan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayloadBuilder::toMinorUnits(\NAN);
    }

    public function testBillingRequestPayload(): void
    {
        $payload = PayloadBuilder::billingRequest(1999, 'eur', 'Bestellung 10001', '10001', str_repeat('a', 32));

        self::assertSame([
            'billing_requests' => [
                'payment_request' => [
                    'description' => 'Bestellung 10001',
                    'amount' => 1999,
                    'currency' => 'EUR',
                    'metadata' => ['shopware_order' => '10001', 'shopware_transaction' => str_repeat('a', 32)],
                ],
                'mandate_request' => ['scheme' => 'sepa_core', 'currency' => 'EUR'],
                'metadata' => ['shopware_order' => '10001', 'shopware_transaction' => str_repeat('a', 32)],
            ],
        ], $payload);
        self::assertLessThanOrEqual(3, \count($payload['billing_requests']['metadata']), 'GoCardless erlaubt höchstens 3 Metadaten-Schlüssel');
    }

    public function testBillingRequestWithMandateReference(): void
    {
        $payload = PayloadBuilder::billingRequest(500, 'EUR', 'x', '1', 'b', 'SHOP-1');
        self::assertSame('SHOP-1', $payload['billing_requests']['mandate_request']['reference']);
    }

    public function testBillingRequestRejectsZeroAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayloadBuilder::billingRequest(0, 'EUR', 'x', '1', 'b');
    }

    public function testFlowPayload(): void
    {
        $payload = PayloadBuilder::billingRequestFlow(
            'BRQ123',
            'https://shop.example/payment/finalize-transaction?_sw_payment_token=abc',
            'https://shop.example/payment/finalize-transaction?_sw_payment_token=abc&nova_gocardless=exit',
            ['given_name' => 'Erika'],
            'de'
        );

        self::assertSame([
            'billing_request_flows' => [
                'redirect_uri' => 'https://shop.example/payment/finalize-transaction?_sw_payment_token=abc',
                'exit_uri' => 'https://shop.example/payment/finalize-transaction?_sw_payment_token=abc&nova_gocardless=exit',
                'links' => ['billing_request' => 'BRQ123'],
                'prefilled_customer' => ['given_name' => 'Erika'],
                'language' => 'de',
            ],
        ], $payload);

        $minimal = PayloadBuilder::billingRequestFlow('BRQ1', 'https://a', 'https://b', [], null);
        self::assertArrayNotHasKey('prefilled_customer', $minimal['billing_request_flows']);
        self::assertArrayNotHasKey('language', $minimal['billing_request_flows']);
    }

    public function testPrefilledCustomer(): void
    {
        $customer = PayloadBuilder::prefilledCustomer([
            'firstName' => ' Erika ',
            'lastName' => 'Mustermann',
            'company' => 'Test GmbH',
            'email' => 'erika@example.com',
            'street' => 'Musterstraße 1',
            'additionalAddressLine1' => '',
            'additionalAddressLine2' => null,
            'zipcode' => '10115',
            'city' => 'Berlin',
            'countryIso' => 'de',
            'language' => 'de',
        ]);

        self::assertSame([
            'given_name' => 'Erika',
            'family_name' => 'Mustermann',
            'email' => 'erika@example.com',
            'address_line1' => 'Musterstraße 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country_code' => 'DE',
            'language' => 'de',
        ], $customer, 'Firmenname nur ohne Vor-/Nachname, leere Felder entfallen');

        self::assertSame(['company_name' => 'Test GmbH'], PayloadBuilder::prefilledCustomer(['company' => 'Test GmbH']));
        self::assertSame([], PayloadBuilder::prefilledCustomer([]));
    }

    public function testDescription(): void
    {
        self::assertSame('Bestellung 10001', PayloadBuilder::description(null, '10001'));
        self::assertSame('Bestellung 10001', PayloadBuilder::description('  ', '10001'));
        self::assertSame('Demo Shop – Auftrag 10001', PayloadBuilder::description('{shopName} – Auftrag {orderNumber}', '10001', 'Demo Shop'));
        self::assertSame('Auftrag 7', PayloadBuilder::description("<b>Auftrag</b>\n {orderNumber}", '7'));
        self::assertSame(140, mb_strlen(PayloadBuilder::description(str_repeat('ä', 300), '1')));
    }

    public function testMandateReference(): void
    {
        self::assertNull(PayloadBuilder::mandateReference('', '10001'));
        self::assertNull(PayloadBuilder::mandateReference(null, '10001'));
        self::assertSame('SHOP-10001', PayloadBuilder::mandateReference('shop-', '10001'));
        self::assertSame('MEIN-SHOP10001', PayloadBuilder::mandateReference('Mein Shop ', '10001'), 'Präfix wird getrimmt, Leerzeichen innen → -');
        self::assertSame('A-B1', PayloadBuilder::mandateReference('a/ß/b', '1'));

        $long = PayloadBuilder::mandateReference(str_repeat('X', 40), '2026-123456');
        self::assertNotNull($long);
        self::assertSame(35, \strlen($long));
        self::assertStringEndsWith('2026-123456', $long, 'Bestellnummer bleibt erhalten (Eindeutigkeit)');
    }

    public function testAppendQuery(): void
    {
        self::assertSame('https://a.example/x?t=1&nova_gocardless=exit', PayloadBuilder::appendQuery('https://a.example/x?t=1', 'nova_gocardless', 'exit'));
        self::assertSame('https://a.example/x?nova_gocardless=exit', PayloadBuilder::appendQuery('https://a.example/x', 'nova_gocardless', 'exit'));
        self::assertSame('https://a.example/x?t=1&k=a%20b#frag', PayloadBuilder::appendQuery('https://a.example/x?t=1#frag', 'k', 'a b'));
    }

    public function testLanguageFromLocale(): void
    {
        self::assertSame('de', PayloadBuilder::languageFromLocale('de-DE'));
        self::assertSame('en', PayloadBuilder::languageFromLocale('en-GB'));
        self::assertNull(PayloadBuilder::languageFromLocale('xx-XX'));
        self::assertNull(PayloadBuilder::languageFromLocale(null));
    }
}
