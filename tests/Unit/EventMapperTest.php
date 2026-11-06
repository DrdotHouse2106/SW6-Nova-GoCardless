<?php declare(strict_types=1);

namespace Nova\GoCardless\Tests\Unit;

use Nova\GoCardless\Webhook\EventDecision;
use Nova\GoCardless\Webhook\EventMapper;
use Nova\GoCardless\Webhook\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function paymentActions(): iterable
    {
        yield 'confirmed' => ['confirmed', 'paid', false];
        yield 'paid_out' => ['paid_out', 'paid', false];
        yield 'failed' => ['failed', 'failed', true];
        yield 'late_failure_settled' => ['late_failure_settled', 'failed', true];
        yield 'cancelled' => ['cancelled', 'cancelled', false];
        yield 'charged_back' => ['charged_back', 'chargeback', true];
        yield 'chargeback_cancelled' => ['chargeback_cancelled', 'paid', false];
        yield 'resubmission_requested' => ['resubmission_requested', null, false];
        yield 'chargeback_settled' => ['chargeback_settled', null, false];
    }

    #[DataProvider('paymentActions')]
    public function testPaymentEvents(string $action, ?string $target, bool $notify): void
    {
        $decision = EventMapper::decide(new WebhookEvent('EV1', 'payments', $action, ['payment' => 'PM1']));

        self::assertNotNull($decision);
        self::assertSame(EventDecision::LOOKUP_PAYMENT, $decision->lookup);
        self::assertSame('PM1', $decision->lookupId);
        self::assertSame($target, $decision->targetState);
        self::assertSame($notify, $decision->notifyAdmin);
    }

    public function testIgnoredEvents(): void
    {
        self::assertNull(EventMapper::decide(new WebhookEvent('EV1', 'payments', 'created', ['payment' => 'PM1'])));
        self::assertNull(EventMapper::decide(new WebhookEvent('EV1', 'payments', 'submitted', ['payment' => 'PM1'])));
        self::assertNull(EventMapper::decide(new WebhookEvent('EV1', 'payments', 'confirmed', [])), 'ohne Zahlungs-ID');
        self::assertNull(EventMapper::decide(new WebhookEvent('EV1', 'payouts', 'paid', ['payout' => 'PO1'])));
        self::assertNull(EventMapper::decide(new WebhookEvent('EV1', 'mandates', 'active', ['mandate' => 'MD1'])));
        self::assertNull(EventMapper::decide(new WebhookEvent('EV1', 'billing_requests', 'flow_visited', ['billing_request' => 'BRQ1'])));
    }

    public function testBillingRequestEvents(): void
    {
        $fulfilled = EventMapper::decide(new WebhookEvent('EV1', 'billing_requests', 'fulfilled', ['billing_request' => 'BRQ1']));
        self::assertNotNull($fulfilled);
        self::assertSame(EventDecision::LOOKUP_BILLING_REQUEST, $fulfilled->lookup);
        self::assertSame('in_progress', $fulfilled->targetState);

        $authorized = EventMapper::decide(new WebhookEvent('EV1', 'billing_requests', 'fulfilled', ['billing_request' => 'BRQ1']), 'authorized');
        self::assertSame('authorized', $authorized?->targetState);

        self::assertSame('cancelled', EventMapper::decide(new WebhookEvent('EV1', 'billing_requests', 'cancelled', ['billing_request' => 'BRQ1']))?->targetState);
        self::assertSame('failed', EventMapper::decide(new WebhookEvent('EV1', 'billing_requests', 'failed', ['billing_request' => 'BRQ1']))?->targetState);
    }

    public function testMandateEventsOnlyAddNote(): void
    {
        foreach (['cancelled', 'failed', 'expired', 'blocked'] as $action) {
            $decision = EventMapper::decide(new WebhookEvent('EV1', 'mandates', $action, ['mandate' => 'MD1'], 'bank_account_closed', 'Konto aufgelöst'));
            self::assertNotNull($decision);
            self::assertSame(EventDecision::LOOKUP_MANDATE, $decision->lookup);
            self::assertNull($decision->targetState);
            self::assertStringContainsString('Lastschriftmandat', (string) $decision->note);
            self::assertStringContainsString('bank_account_closed: Konto aufgelöst', (string) $decision->note);
        }
    }

    public function testNormalizeSuccessState(): void
    {
        self::assertSame('in_progress', EventMapper::normalizeSuccessState(null));
        self::assertSame('in_progress', EventMapper::normalizeSuccessState('paid'));
        self::assertSame('authorized', EventMapper::normalizeSuccessState('authorized'));
    }
}
