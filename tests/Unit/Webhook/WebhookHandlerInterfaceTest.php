<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\WebhookHandlerInterface;

#[CoversNothing]
final class WebhookHandlerInterfaceTest extends TestCase
{
    #[Test]
    public function handleReceivesEventTypeAndPayload(): void
    {
        $handler = new class implements WebhookHandlerInterface {
            public string $receivedType = '';
            /** @var array<string, mixed> */
            public array $receivedPayload = [];

            public function handle(string $eventType, array $payload): void
            {
                $this->receivedType = $eventType;
                $this->receivedPayload = $payload;
            }
        };

        $handler->handle('payment.succeeded', ['amount' => 9900, 'currency' => 'usd']);

        self::assertSame('payment.succeeded', $handler->receivedType);
        self::assertSame(9900, $handler->receivedPayload['amount']);
        self::assertSame('usd', $handler->receivedPayload['currency']);
    }

    #[Test]
    #[DataProvider('eventTypeProvider')]
    public function acceptsArbitraryEventTypes(string $eventType): void
    {
        $handler = new class implements WebhookHandlerInterface {
            public string $received = '';

            public function handle(string $eventType, array $payload): void
            {
                $this->received = $eventType;
            }
        };

        $handler->handle($eventType, []);

        self::assertSame($eventType, $handler->received);
    }

    /** @return iterable<string, array{string}> */
    public static function eventTypeProvider(): iterable
    {
        yield 'dotted' => ['payment.intent.succeeded'];
        yield 'snake_case' => ['order_created'];
        yield 'simple' => ['ping'];
        yield 'deeply nested' => ['customer.subscription.trial_will_end'];
    }

    #[Test]
    public function handleAcceptsEmptyPayload(): void
    {
        $handler = new class implements WebhookHandlerInterface {
            /** @var array<string, mixed> */
            public array $receivedPayload = ['sentinel' => true];

            public function handle(string $eventType, array $payload): void
            {
                $this->receivedPayload = $payload;
            }
        };

        $handler->handle('test.event', []);

        self::assertSame([], $handler->receivedPayload);
    }

    #[Test]
    public function handleAcceptsNestedPayload(): void
    {
        $handler = new class implements WebhookHandlerInterface {
            /** @var array<string, mixed> */
            public array $receivedPayload = [];

            public function handle(string $eventType, array $payload): void
            {
                $this->receivedPayload = $payload;
            }
        };

        $nested = [
            'data' => [
                'object' => [
                    'id' => 'pi_123',
                    'metadata' => ['order_id' => 'ord-456'],
                ],
            ],
        ];

        $handler->handle('payment.intent.created', $nested);

        /** @var array{data: array{object: array{id: string}}} $payload */
        $payload = $handler->receivedPayload;
        self::assertSame('pi_123', $payload['data']['object']['id']);
    }
}
