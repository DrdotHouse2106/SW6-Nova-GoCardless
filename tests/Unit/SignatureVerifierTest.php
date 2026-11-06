<?php declare(strict_types=1);

namespace Nova\GoCardless\Tests\Unit;

use Nova\GoCardless\Webhook\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private const BODY = '{"events":[{"id":"EV123","resource_type":"payments","action":"confirmed","links":{"payment":"PM123"}}]}';

    public function testKnownDigest(): void
    {
        // Referenzwert unabhängig berechnet: HMAC-SHA256 über den Rohinhalt
        self::assertSame(hash_hmac('sha256', self::BODY, 'geheim'), SignatureVerifier::sign(self::BODY, 'geheim'));
        self::assertSame(64, \strlen(SignatureVerifier::sign(self::BODY, 'geheim')));
    }

    public function testAcceptsCorrectSignature(): void
    {
        $signature = hash_hmac('sha256', self::BODY, 'secret-a');

        self::assertSame('secret-a', SignatureVerifier::matchingSecret(self::BODY, $signature, ['secret-a']));
        self::assertSame('secret-a', SignatureVerifier::matchingSecret(self::BODY, strtoupper($signature), ['secret-a']), 'Großschreibung des Hex-Werts');
        self::assertSame('secret-a', SignatureVerifier::matchingSecret(self::BODY, ' ' . $signature . ' ', ['secret-a']));
    }

    public function testPicksMatchingSecretOfSeveral(): void
    {
        $signature = hash_hmac('sha256', self::BODY, 'secret-b');

        self::assertSame('secret-b', SignatureVerifier::matchingSecret(self::BODY, $signature, ['', 'secret-a', 'secret-b']));
    }

    public function testRejectsWrongSecretModifiedBodyAndBadHeaders(): void
    {
        $signature = hash_hmac('sha256', self::BODY, 'secret-a');

        self::assertNull(SignatureVerifier::matchingSecret(self::BODY, $signature, ['secret-x']));
        self::assertNull(SignatureVerifier::matchingSecret(self::BODY . ' ', $signature, ['secret-a']), 'Rohinhalt verändert');
        self::assertNull(SignatureVerifier::matchingSecret(self::BODY, null, ['secret-a']));
        self::assertNull(SignatureVerifier::matchingSecret(self::BODY, '', ['secret-a']));
        self::assertNull(SignatureVerifier::matchingSecret(self::BODY, 'abc', ['secret-a']));
        self::assertNull(SignatureVerifier::matchingSecret(self::BODY, $signature, []));
        self::assertNull(SignatureVerifier::matchingSecret(self::BODY, $signature, ['']));
    }
}
