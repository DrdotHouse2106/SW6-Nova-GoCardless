<?php declare(strict_types=1);

namespace Nova\GoCardless\Tests\Unit;

use Nova\GoCardless\Webhook\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase
{
    public function testParsesEvents(): void
    {
        $body = json_encode(['events' => [
            [
                'id' => 'EV123', 'created_at' => '2026-01-01T10:00:00.000Z', 'resource_type' => 'payments', 'action' => 'charged_back',
                'links' => ['payment' => 'PM123', 'parent_event' => null],
                'details' => ['origin' => 'bank', 'cause' => 'payment_charged_back', 'description' => 'The customer disputed the payment'],
                'metadata' => [],
            ],
            ['id' => 'EV124', 'resource_type' => 'billing_requests', 'action' => 'fulfilled', 'links' => ['billing_request' => 'BRQ1', 'payment_request_payment' => 'PM9', 'mandate_request_mandate' => 'MD9']],
            ['id' => '', 'resource_type' => 'payments', 'action' => 'paid_out'],
            'kaputt',
        ], 'meta' => ['webhook_id' => 'WB1']], \JSON_THROW_ON_ERROR);

        $events = WebhookEvent::listFromBody($body);

        self::assertCount(2, $events);
        self::assertSame('EV123', $events[0]->id);
        self::assertSame('payments', $events[0]->resourceType);
        self::assertSame('charged_back', $events[0]->action);
        self::assertSame(['payment' => 'PM123'], $events[0]->links);
        self::assertSame('payment_charged_back', $events[0]->cause);
        self::assertSame('PM9', $events[1]->link('payment_request_payment'));
        self::assertNull($events[1]->link('customer'));
    }

    public function testRejectsInvalidBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebhookEvent::listFromBody('{kein json');
    }

    public function testRejectsBodyWithoutEvents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebhookEvent::listFromBody('{"foo":[]}');
    }
}
